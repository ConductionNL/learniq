<?php

/**
 * Learniq Course Store Publisher
 *
 * Sends a share package to the course registry. OpenRegister's store plane
 * covers discovery only (`GenericStoreService` exposes isConfigured, search and
 * resolve) and has no write path, so this class writes to the registry's
 * objects API itself, applying the plane's own rules to the write:
 *
 *   - the registry URL, token and register come from learniq's app config, the
 *     same keys the plane reads for learniq;
 *   - no registry configured means no request and outcome `not_configured`;
 *   - every URL passes OpenRegister's SSRF guard first, and redirects are
 *     refused, so a public host cannot bounce the token to a private address;
 *   - the token travels only as a Bearer header and never reaches a response
 *     or a log line.
 *
 * When OpenRegister adds a write path to the plane, this class becomes one
 * call to it (design.md D2).
 *
 * @category Service
 * @package  OCA\Learniq\Service\CourseStore
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lesson-sharing-via-store-plane/specs/course-management/spec.md#requirement-publishing-sends-a-gated-package-to-the-registry
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CourseStore;

use OCA\Learniq\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * POSTs a registry object to the configured course registry.
 */
class CourseStorePublisher {

	public const OUTCOME_OK = 'ok';

	public const OUTCOME_NOT_CONFIGURED = 'not_configured';

	public const OUTCOME_UNREACHABLE = 'store_unreachable';

	public const OUTCOME_REJECTED = 'store_rejected';

	public const OUTCOME_TOO_LARGE = 'too_large';

	/**
	 * Largest package, as JSON, this publisher sends.
	 */
	public const MAX_BYTES = 20 * 1024 * 1024;

	/**
	 * Connect and request timeout in seconds, as the store plane uses.
	 */
	private const TIMEOUT = 10;

	/**
	 * Constructor.
	 *
	 * @param IClientService            $clientService  Nextcloud HTTP client factory.
	 * @param IAppConfig                $appConfig      Learniq's app config (registry connection).
	 * @param CourseStoreUrlGuard       $urlGuard       OpenRegister's SSRF guard.
	 * @param CourseStoreRegistryObject $registryObject Builds the object to send.
	 * @param LoggerInterface           $logger         Server-side diagnostics only.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IAppConfig $appConfig,
		private readonly CourseStoreUrlGuard $urlGuard,
		private readonly CourseStoreRegistryObject $registryObject,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether a course registry is configured.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/lesson-sharing-via-store-plane/specs/course-management/spec.md#requirement-publishing-sends-a-gated-package-to-the-registry
	 */
	public function isConfigured(): bool {
		return trim($this->appConfig->getValueString(Application::APP_ID, 'registry_url', '')) !== '';

	}//end isConfigured()

	/**
	 * Publish a share package.
	 *
	 * @param array<string, mixed> $package The share package from the sharing gate.
	 *
	 * @return array{outcome: string, slug: string}
	 *
	 * @spec openspec/changes/lesson-sharing-via-store-plane/specs/course-management/spec.md#requirement-publishing-sends-a-gated-package-to-the-registry
	 */
	public function publish(array $package): array {
		$object = $this->registryObject->build(package: $package);
		$slug   = (string)$object['slug'];

		if ($this->isConfigured() === false) {
			return ['outcome' => self::OUTCOME_NOT_CONFIGURED, 'slug' => ''];
		}

		$body = (string)json_encode($object, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (strlen($body) > self::MAX_BYTES) {
			return ['outcome' => self::OUTCOME_TOO_LARGE, 'slug' => ''];
		}

		$url = $this->objectsUrl();
		try {
			$this->urlGuard->assertSafe(url: $url);
		} catch (Throwable $e) {
			$this->logger->warning(message: 'Learniq course store: refused unsafe registry URL: ' . $e->getMessage());
			return ['outcome' => self::OUTCOME_UNREACHABLE, 'slug' => ''];
		}

		$status = $this->post(url: $url, body: $body);
		if ($status === null) {
			return ['outcome' => self::OUTCOME_UNREACHABLE, 'slug' => ''];
		}

		if ($status < 200 || $status >= 300) {
			$this->logger->warning(message: 'Learniq course store: registry refused the package with HTTP ' . $status);
			return ['outcome' => self::OUTCOME_REJECTED, 'slug' => ''];
		}

		return ['outcome' => self::OUTCOME_OK, 'slug' => $slug];

	}//end publish()

	/**
	 * The registry's objects API URL for shared course packages.
	 *
	 * @return string
	 */
	private function objectsUrl(): string {
		$base     = rtrim(trim($this->appConfig->getValueString(Application::APP_ID, 'registry_url', '')), '/');
		$register = trim(
			$this->appConfig->getValueString(Application::APP_ID, 'registry_register', CourseStoreDescriptor::DEFAULT_REGISTER)
		);
		if ($register === '') {
			$register = CourseStoreDescriptor::DEFAULT_REGISTER;
		}

		return $base . '/index.php/apps/openregister/api/objects/'
			. rawurlencode($register) . '/' . rawurlencode(CourseStoreDescriptor::SCHEMA);

	}//end objectsUrl()

	/**
	 * POST the body; return the status, or null when the request failed.
	 *
	 * @param string $url  The guarded URL.
	 * @param string $body The JSON body.
	 *
	 * @return int|null
	 */
	private function post(string $url, string $body): ?int {
		$headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
		$token   = trim($this->appConfig->getValueString(Application::APP_ID, 'registry_token', ''));
		if ($token !== '') {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		try {
			$response = $this->clientService->newClient()->post(
				$url,
				[
					'body'            => $body,
					'headers'         => $headers,
					'timeout'         => self::TIMEOUT,
					'connect_timeout' => self::TIMEOUT,
					'allow_redirects' => false,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(message: 'Learniq course store: registry write failed: ' . $e->getMessage());
			return null;
		}

		return $response->getStatusCode();

	}//end post()
}//end class
