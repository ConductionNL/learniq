<?php

/**
 * Learniq Store Registry Settings Controller
 *
 * The course registry connection, on the Learniq admin settings page instead
 * of `occ config:app:set` (store-rights-for-teachers). It reads and writes the
 * same three app config keys OpenRegister's store plane reads for learniq:
 * `registry_url`, `registry_register` and `registry_token`.
 *
 * The token is write-only: it is stored as a sensitive value, never returned
 * (the page only learns whether one is set), kept when a save omits it, and
 * removed only on an explicit `clearToken`. The address must be empty (which
 * disconnects the store) or an absolute http or https URL without user
 * credentials; the plane's SSRF guard still runs on every request it makes.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
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
 * @spec openspec/changes/store-rights-for-teachers/specs/course-management/spec.md#requirement-an-administrator-connects-the-course-registry-in-the-admin-settings
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * Admin-only read and write of the course registry connection.
 */
class StoreRegistrySettingsController extends Controller {

	public const KEY_URL = 'registry_url';

	public const KEY_REGISTER = 'registry_register';

	public const KEY_TOKEN = 'registry_token';

	/**
	 * A register segment: lowercase slug, as registers are named.
	 */
	private const REGISTER_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest   $request   The request.
	 * @param IAppConfig $appConfig Learniq's app config.
	 */
	public function __construct(
		IRequest $request,
		private readonly IAppConfig $appConfig,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * The connection, without the token.
	 *
	 * @return JSONResponse `{url, register, tokenSet}`.
	 *
	 * @spec openspec/changes/store-rights-for-teachers/specs/course-management/spec.md#requirement-an-administrator-connects-the-course-registry-in-the-admin-settings
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function show(): JSONResponse {
		return new JSONResponse(data: $this->current());

	}//end show()

	/**
	 * Save the connection. Reads `url`, `register`, `token` and `clearToken`
	 * from the request body.
	 *
	 * @return JSONResponse The saved connection, as show() answers; 400 on a malformed address or register.
	 *
	 * @spec openspec/changes/store-rights-for-teachers/specs/course-management/spec.md#requirement-an-administrator-connects-the-course-registry-in-the-admin-settings
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function update(): JSONResponse {
		$url      = trim((string)$this->request->getParam('url', ''));
		$register = trim((string)$this->request->getParam('register', ''));

		$error = $this->validate(url: $url, register: $register);
		if ($error !== null) {
			return new JSONResponse(data: ['error' => $error], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$this->appConfig->setValueString(Application::APP_ID, self::KEY_URL, $url);
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_REGISTER, $register);
		$this->saveToken();

		return new JSONResponse(data: $this->current());

	}//end update()

	/**
	 * Why the address or register cannot be saved, or null when both can.
	 *
	 * @param string $url      The registry address.
	 * @param string $register The register segment.
	 *
	 * @return string|null
	 */
	private function validate(string $url, string $register): ?string {
		if ($url !== '') {
			$parts  = parse_url($url);
			$scheme = strtolower((string)($parts['scheme'] ?? ''));
			if ($parts === false || in_array($scheme, ['http', 'https'], true) === false || (string)($parts['host'] ?? '') === '') {
				return 'The registry address must be a full http or https address.';
			}

			if (isset($parts['user']) === true || isset($parts['pass']) === true) {
				return 'Put the token in the token field, not in the address.';
			}
		}

		if ($register !== '' && preg_match(self::REGISTER_PATTERN, $register) !== 1) {
			return 'The register is a lowercase name such as learniq.';
		}

		return null;

	}//end validate()

	/**
	 * Keep, replace or remove the token as the request asks.
	 *
	 * @return void
	 */
	private function saveToken(): void {
		if (filter_var($this->request->getParam('clearToken', false), FILTER_VALIDATE_BOOLEAN) === true) {
			$this->appConfig->deleteKey(Application::APP_ID, self::KEY_TOKEN);
			return;
		}

		$token = $this->request->getParam('token');
		if (is_string($token) === true && trim($token) !== '') {
			$this->appConfig->setValueString(Application::APP_ID, self::KEY_TOKEN, trim($token), sensitive: true);
		}

	}//end saveToken()

	/**
	 * The stored connection, with only whether a token is set.
	 *
	 * @return array{url: string, register: string, tokenSet: bool}
	 */
	private function current(): array {
		return [
			'url'      => $this->appConfig->getValueString(Application::APP_ID, self::KEY_URL, ''),
			'register' => $this->appConfig->getValueString(Application::APP_ID, self::KEY_REGISTER, ''),
			'tokenSet' => $this->appConfig->getValueString(Application::APP_ID, self::KEY_TOKEN, '') !== '',
		];

	}//end current()
}//end class
