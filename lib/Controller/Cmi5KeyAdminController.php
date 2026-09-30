<?php

/**
 * Learniq cmi5 Key Admin Controller
 *
 * Admin endpoints that provision and inspect the RS256 key-pair cmi5 launch
 * tokens are signed with. Mirrors `KeyAdminController` for the credential
 * signing key: a first generation needs no confirmation, a rotation needs
 * `confirm=true` and is throttled to one per 24 hours, because rotating
 * invalidates every launch token in flight.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#1-key-provisioning
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\Cmi5LaunchTokenService;
use OCA\Learniq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use RuntimeException;

/**
 * Provision and inspect the cmi5 launch key-pair.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-run-cmi5--xapi-natively-with-scorm-shim
 */
class Cmi5KeyAdminController extends Controller {

	/**
	 * App-config key holding the last generation time.
	 *
	 * @var string
	 */
	private const LAST_AT_KEY = 'cmi5.keygen.last_at';

	/**
	 * Minimum seconds between two rotations.
	 *
	 * @var int
	 */
	private const MIN_INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request   The current request.
	 * @param Cmi5LaunchTokenService $tokens    Owns the key-pair.
	 * @param IAppConfig             $appConfig Holds the rotation timestamp.
	 */
	public function __construct(
		IRequest $request,
		private readonly Cmi5LaunchTokenService $tokens,
		private readonly IAppConfig $appConfig,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Generate the key-pair, or rotate it with `confirm=true` at most once a day.
	 *
	 * @return JSONResponse `{fingerprint, publicKey}` (201), or an error.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#1-key-provisioning
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function generateKey(): JSONResponse {
		if ($this->tokens->keyStatus() !== null) {
			$refusal = $this->rotationRefusal();
			if ($refusal !== null) {
				return $refusal;
			}
		}

		try {
			$result = $this->tokens->generateKeyPair();
		} catch (RuntimeException $e) {
			return $this->noCache(response: new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR));
		}

		$this->appConfig->setValueString(app: Application::APP_ID, key: self::LAST_AT_KEY, value: (string)time());

		return $this->noCache(response: new JSONResponse(data: $result, statusCode: Http::STATUS_CREATED));
	}//end generateKey()

	/**
	 * Whether a key is provisioned, with its public half and fingerprint.
	 *
	 * @return JSONResponse `{configured: bool, fingerprint?, publicKey?}`.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#1-key-provisioning
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function keyStatus(): JSONResponse {
		$status = $this->tokens->keyStatus();
		if ($status === null) {
			return $this->noCache(response: new JSONResponse(data: ['configured' => false]));
		}

		return $this->noCache(response: new JSONResponse(data: array_merge(['configured' => true], $status)));
	}//end keyStatus()

	/**
	 * Why a rotation may not happen now, or null when it may.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 */
	private function rotationRefusal(): ?JSONResponse {
		if ($this->request->getParam('confirm', '') !== 'true') {
			return $this->noCache(
				response: new JSONResponse(
					data: ['error' => 'Rotating the cmi5 key needs confirm=true. Launches in progress stop working.'],
					statusCode: Http::STATUS_BAD_REQUEST
				)
			);
		}

		$lastAt = (int)$this->appConfig->getValueString(app: Application::APP_ID, key: self::LAST_AT_KEY, default: '0');
		if (time() - $lastAt < self::MIN_INTERVAL_SECONDS) {
			return $this->noCache(
				response: new JSONResponse(
					data: ['error' => 'The cmi5 key was generated less than 24 hours ago. Try again later.'],
					statusCode: Http::STATUS_TOO_MANY_REQUESTS
				)
			);
		}

		return null;
	}//end rotationRefusal()

	/**
	 * Mark a response as not cacheable.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return JSONResponse The same response.
	 */
	private function noCache(JSONResponse $response): JSONResponse {
		$response->cacheFor(0);
		return $response;
	}//end noCache()
}//end class
