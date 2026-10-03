<?php

/**
 * Learniq Course Store Publisher
 *
 * Sends a share package to the course registry through OpenRegister's store
 * plane: `GenericStoreService::publish()` (openregister #4079). The plane owns
 * the whole transport, the same guard chain as discovery:
 *
 *   - the registry URL, token and register come from learniq's app config,
 *     read by the plane for this app id; learniq never reads the token;
 *   - no registry configured means no request and outcome `not_configured`;
 *   - the SSRF guard, the redirect refusal, the timeouts and the 20 MiB cap;
 *   - a 2xx counts only when the registry stored the slug that was sent.
 *
 * What may leave the server and who may send it are learniq's decisions, made
 * on CourseStoreDescriptor (publishFields, and publishGroups from the ADR-023
 * matrix action `course-package.share`). Who may publish is asked of the
 * plane's StoreActionAuthorizer before a package is built.
 *
 * Duck-typed against the installed OpenRegister: an older one has no publish
 * path, and then this class says so (`publish_not_supported`) instead of
 * sending anything or failing search and install with it.
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
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-store-publish-travels-through-the-store-planes-write-path
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CourseStore;

use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCP\IUser;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes a gated share package through the store plane.
 */
class CourseStorePublisher {

	public const OUTCOME_OK = GenericStoreService::OUTCOME_OK;

	public const OUTCOME_NOT_CONFIGURED = GenericStoreService::OUTCOME_NOT_CONFIGURED;

	public const OUTCOME_UNREACHABLE = GenericStoreService::OUTCOME_UNREACHABLE;

	public const OUTCOME_INVALID = GenericStoreService::OUTCOME_INVALID;

	/*
	 * The next four are literals on purpose, not references to the plane's
	 * constants: an OpenRegister before #4079 lacks those constants, and a
	 * missing constant in a constant expression is fatal when this class loads.
	 */

	public const OUTCOME_REJECTED = 'store_rejected';

	public const OUTCOME_TOO_LARGE = 'too_large';

	public const OUTCOME_RATE_LIMITED = 'rate_limited';

	public const OUTCOME_NOT_PUBLISHABLE = 'not_publishable';

	/**
	 * Learniq's own refusal: the plane did not admit this user.
	 */
	public const OUTCOME_FORBIDDEN = 'forbidden';

	/**
	 * Learniq's own refusal: the installed OpenRegister has no publish path.
	 */
	public const OUTCOME_NOT_SUPPORTED = 'publish_not_supported';

	/**
	 * OpenRegister's store authorizer, resolved lazily (see mayPublish()).
	 */
	private const AUTHORIZER_CLASS = 'OCA\\OpenRegister\\AppHost\\Store\\StoreActionAuthorizer';

	/**
	 * Constructor.
	 *
	 * @param GenericStoreService       $storeService   OpenRegister's store plane client.
	 * @param CourseStoreDescriptor     $descriptor     Learniq's store parameters and publish opt-in.
	 * @param CourseStoreRegistryObject $registryObject Builds the object to send.
	 * @param ContainerInterface        $container      Server container, for the plane's authorizer.
	 * @param LoggerInterface           $logger         Server-side diagnostics only.
	 */
	public function __construct(
		private readonly GenericStoreService $storeService,
		private readonly CourseStoreDescriptor $descriptor,
		private readonly CourseStoreRegistryObject $registryObject,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether a course registry is configured, as the plane reads it.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-store-publish-travels-through-the-store-planes-write-path
	 */
	public function isConfigured(): bool {
		return $this->storeService->isConfigured(descriptor: $this->descriptor->descriptor());

	}//end isConfigured()

	/**
	 * Whether the installed OpenRegister can publish: the descriptor opt-in
	 * exists and the plane has a publish() method.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-publishing-degrades-cleanly-on-an-openregister-without-the-write-path
	 */
	public function supportsPublish(): bool {
		return $this->descriptor->supportsPublish() === true
			&& $this->planeHas(method: 'publish') === true;

	}//end supportsPublish()

	/**
	 * Whether the installed store plane client has a method.
	 *
	 * A runtime question on purpose: static analysis reads the declaration
	 * stub of the newest OpenRegister, while an instance may run an older one.
	 *
	 * @param string $method The method name.
	 *
	 * @return bool
	 */
	private function planeHas(string $method): bool {
		return method_exists($this->storeService, $method);

	}//end planeHas()

	/**
	 * Whether the plane admits this user as a publisher for the course store.
	 *
	 * The authorizer is resolved from the container here, not injected: an
	 * OpenRegister without the class would otherwise stop the DI container
	 * from building the store controller, taking search and install with it.
	 * Every failure to resolve or ask is a refusal, never a pass: an absent
	 * class, an object without canPublish(), or canPublish() throwing.
	 *
	 * @param IUser $user The signed-in user.
	 *
	 * @return bool True only when the plane answered yes.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-the-plane-decides-who-may-publish-before-a-package-is-built
	 */
	public function mayPublish(IUser $user): bool {
		if ($this->supportsPublish() === false) {
			return false;
		}

		try {
			$authorizer = $this->container->get(self::AUTHORIZER_CLASS);
		} catch (Throwable $e) {
			$this->logger->error(message: 'Learniq course store: publish refused, the store authorizer is not resolvable: ' . $e->getMessage());
			return false;
		}

		if (is_object($authorizer) === false || method_exists($authorizer, 'canPublish') === false) {
			$this->logger->error(message: 'Learniq course store: publish refused, the store authorizer has no canPublish()');
			return false;
		}

		try {
			return $authorizer->canPublish($this->descriptor->descriptor(), $user) === true;
		} catch (Throwable $e) {
			$this->logger->error(message: 'Learniq course store: publish refused, canPublish() threw: ' . $e->getMessage());
			return false;
		}

	}//end mayPublish()

	/**
	 * Publish a share package through the plane.
	 *
	 * @param array<string, mixed> $package The share package from the sharing gate.
	 *
	 * @return array{outcome: string, slug: string}
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-store-publish-travels-through-the-store-planes-write-path
	 */
	public function publish(array $package): array {
		if ($this->supportsPublish() === false) {
			return ['outcome' => self::OUTCOME_NOT_SUPPORTED, 'slug' => ''];
		}

		$result = $this->storeService->publish(
			descriptor: $this->descriptor->descriptor(),
			payload: $this->registryObject->build(package: $package)
		);

		return [
			'outcome' => (string)($result['outcome'] ?? self::OUTCOME_INVALID),
			'slug'    => (string)($result['slug'] ?? ''),
		];

	}//end publish()
}//end class
