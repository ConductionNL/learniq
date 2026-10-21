<?php

/**
 * Learniq Key Admin Controller
 *
 * Admin-only endpoints for managing the per-tenant RSA keypair used for
 * Open Badges 3.0 credential signing. Wraps KeyManagementService.
 *
 * Legitimate PHP per ADR-031: "Cryptographic operation — admin action that
 * generates / rotates RSA-2048 keypairs via openssl; cannot be expressed as
 * a schema declaration."
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\KeyManagementService;
use OCA\Learniq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use RuntimeException;

/**
 * Admin-only endpoints for RSA keypair generation and status.
 *
 * Non-admin requests are rejected with HTTP 403 by Nextcloud's middleware
 * (no @NoAdminRequired annotation).
 *
 * C2: generateKey resolves the caller's bound tenant server-side and rejects
 * requests where the supplied tenantId does not match. Rotations require
 * confirm:true plus a 24-hour throttle (M4).
 */
class KeyAdminController extends Controller {
	/**
	 * App-config key for the last key-generation timestamp per tenant.
	 * Format: learniq.keygen.last_at.<tenantId>
	 */
	private const KEYGEN_LAST_AT_PREFIX = 'keygen.last_at.';

	/**
	 * Minimum seconds between key rotations (24 h).
	 */
	private const KEYGEN_MIN_INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param KeyManagementService $keyManagementService Key management service.
	 * @param IAppConfig $appConfig App config for throttle timestamps.
	 * @param IConfig $config System config for tenant binding lookup.
	 * @param IUserSession $userSession User session for caller identity.
	 * @param CallerTenantResolver $tenants Resolves the caller's tenant: the per-user binding, else the default tenant.
	 * @param IEventDispatcher $dispatcher Dispatches the admin audit event of a rotation.
	 * @param IL10N $l10n Translates what the admin page shows.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly KeyManagementService $keyManagementService,
		private readonly IAppConfig $appConfig,
		private readonly IConfig $config,
		private readonly IUserSession $userSession,
		private readonly CallerTenantResolver $tenants,
		private readonly IEventDispatcher $dispatcher,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Generate or rotate the RSA-2048 signing keypair for a tenant.
	 *
	 * C2: The caller's bound tenant is resolved server-side. When the caller has
	 * no tenant binding (super-admin case) the request tenantId is accepted only
	 * for first-install (no existing key). Rotations always require confirm:true
	 * and are throttled to one per 24 hours (M4).
	 *
	 * L4: Response is marked no-cache so clients always re-fetch key status.
	 *
	 * @return JSONResponse {fingerprint, publicKey} on success; {error} on failure.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function generateKey(): JSONResponse {
		$tenantId = $this->request->getParam('tenantId', '');

		if ($tenantId === '') {
			$response = new JSONResponse(['error' => 'tenantId is required'], 400);
			$response->cacheFor(0);
			return $response;
		}

		// C2: resolve caller's bound tenant server-side and enforce it.
		$callerTenantId = $this->resolveCallerTenant();
		if ($callerTenantId !== null && $callerTenantId !== $tenantId) {
			$response = new JSONResponse(
				['error' => 'tenantId does not match your bound tenant'],
				403
			);
			$response->cacheFor(0);
			return $response;
		}

		$existingKey = $this->keyManagementService->getTenantKeyStatus(tenantId: $tenantId);

		if ($existingKey !== null) {
			// This is a rotation — require explicit confirmation + throttle.
			$confirm = $this->request->getParam('confirm', '');
			if ($confirm !== 'true') {
				$response = new JSONResponse(
					['error' => 'Key rotation requires confirm=true. This will invalidate the current signing key.'],
					400
				);
				$response->cacheFor(0);
				return $response;
			}

			// M4: 24-hour throttle on rotations.
			$throttleError = $this->checkRotationThrottle(tenantId: $tenantId);
			if ($throttleError !== null) {
				$response = new JSONResponse(['error' => $throttleError], 429);
				$response->cacheFor(0);
				return $response;
			}
		}

		try {
			$result = $this->keyManagementService->generateTenantKeypair(tenantId: $tenantId);

			// Record the timestamp for throttle enforcement on the next rotation.
			$this->appConfig->setValueString(
				app: 'learniq',
				key: self::KEYGEN_LAST_AT_PREFIX . $tenantId,
				value: (string)time()
			);

			$response = new JSONResponse($result, 201);
			$response->cacheFor(0);
			return $response;
		} catch (\RuntimeException $e) {
			$response = new JSONResponse(['error' => $e->getMessage()], 500);
			$response->cacheFor(0);
			return $response;
		}
	}//end generateKey()

	/**
	 * Return the public key status (fingerprint + public key PEM) for a tenant.
	 *
	 * @return JSONResponse {fingerprint, publicKey} or {configured: false}.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function keyStatus(): JSONResponse {
		$tenantId = $this->request->getParam('tenantId', '');

		if ($tenantId === '') {
			$response = new JSONResponse(['error' => 'tenantId is required'], 400);
			$response->cacheFor(0);
			return $response;
		}

		$status = $this->keyManagementService->getTenantKeyStatus(tenantId: $tenantId);

		if ($status === null) {
			$response = new JSONResponse(['configured' => false]);
			$response->cacheFor(0);
			return $response;
		}

		$response = new JSONResponse(array_merge(['configured' => true], $status));
		$response->cacheFor(0);
		return $response;
	}//end keyStatus()

	/**
	 * Report the signing key of the caller's own tenant.
	 *
	 * The tenant is resolved on the server, so the admin page needs no tenant id.
	 *
	 * @return JSONResponse {configured: false} or {configured: true, fingerprint}.
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-allow-the-credential-signing-key-to-be-rotated-from-settings
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function signingKeyStatus(): JSONResponse {
		$tenantId = $this->callerTenantOrDefault();
		$status = $this->keyManagementService->getTenantKeyStatus(tenantId: $tenantId);

		$body = ['configured' => false];
		if ($status !== null) {
			$body = ['configured' => true, 'fingerprint' => $status['fingerprint']];
		}

		$response = new JSONResponse($body);
		$response->cacheFor(0);
		return $response;
	}//end signingKeyStatus()

	/**
	 * Generate the caller's tenant signing key, or rotate it with `confirm=true`.
	 *
	 * The tenant is resolved on the server. A rotation keeps the old public key
	 * for verification, is throttled to one per 24 hours, and is written to the
	 * admin audit log.
	 *
	 * @return JSONResponse {fingerprint, rotated, message} with 201; {error, message} with 400, 429 or 500.
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-allow-the-credential-signing-key-to-be-rotated-from-settings
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function rotateSigningKey(): JSONResponse {
		$tenantId = $this->callerTenantOrDefault();
		$rotated = $this->keyManagementService->getTenantKeyStatus(tenantId: $tenantId) !== null;

		if ($rotated === true) {
			if ($this->request->getParam('confirm', '') !== 'true') {
				$response = new JSONResponse(
					['error' => 'Key rotation requires confirm=true.', 'message' => $this->l10n->t('The signing key was not changed.')],
					400
				);
				$response->cacheFor(0);
				return $response;
			}

			$throttleError = $this->checkRotationThrottle(tenantId: $tenantId);
			if ($throttleError !== null) {
				$response = new JSONResponse(
					['error' => $throttleError, 'message' => $this->l10n->t('The signing key was rotated less than a day ago. Try again tomorrow.')],
					429
				);
				$response->cacheFor(0);
				return $response;
			}
		}

		try {
			$result = $this->keyManagementService->generateTenantKeypair(tenantId: $tenantId);
		} catch (RuntimeException $e) {
			$response = new JSONResponse(['error' => $e->getMessage(), 'message' => $this->l10n->t('The signing key was not changed.')], 500);
			$response->cacheFor(0);
			return $response;
		}

		$this->appConfig->setValueString(
			app: 'learniq',
			key: self::KEYGEN_LAST_AT_PREFIX . $tenantId,
			value: (string)time()
		);

		$actor = $this->userSession->getUser()?->getUID() ?? 'unknown';
		$action = 'generated';
		if ($rotated === true) {
			$action = 'rotated';
		}

		$this->dispatcher->dispatchTyped(
			new CriticalActionPerformedEvent(
				'Credential signing key %s by "%s" for tenant "%s", new fingerprint "%s"',
				[
					'action' => $action,
					'actor' => $actor,
					'tenantId' => $tenantId,
					'fingerprint' => $result['fingerprint'],
				]
			)
		);

		$message = $this->l10n->t('A signing key was generated.');
		if ($rotated === true) {
			$message = $this->l10n->t('The signing key was rotated. Credentials issued before stay verifiable.');
		}

		$response = new JSONResponse(['fingerprint' => $result['fingerprint'], 'rotated' => $rotated, 'message' => $message], 201);
		$response->cacheFor(0);
		return $response;
	}//end rotateSigningKey()

	/**
	 * The caller's tenant: the per-user binding, else the default tenant.
	 *
	 * @return string The tenant id.
	 */
	private function callerTenantOrDefault(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return CallerTenantResolver::DEFAULT_TENANT;
		}

		return $this->tenants->resolve(user: $user);
	}//end callerTenantOrDefault()

	/**
	 * Resolve the tenant ID bound to the authenticated caller.
	 *
	 * Reads the `tenant_id` user preference set by the admin module.
	 * Returns null when the caller has no per-user tenant binding (super-admin).
	 *
	 * @return string|null Bound tenant ID, or null if not set.
	 */
	private function resolveCallerTenant(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		$tenantId = $this->config->getUserValue(
			userId: $user->getUID(),
			appName: 'learniq',
			key: 'tenant_id',
			default: ''
		);

		if ($tenantId === '') {
			return null;
		}

		return $tenantId;
	}//end resolveCallerTenant()

	/**
	 * Check whether the 24-hour rotation throttle allows a new key generation.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return string|null Null when allowed; human-readable error string when throttled.
	 */
	private function checkRotationThrottle(string $tenantId): ?string {
		$lastAtStr = $this->appConfig->getValueString(
			app: 'learniq',
			key: self::KEYGEN_LAST_AT_PREFIX . $tenantId,
			default: ''
		);

		if ($lastAtStr === '') {
			return null;
		}

		$lastAt = (int)$lastAtStr;
		$elapsed = time() - $lastAt;

		if ($elapsed < self::KEYGEN_MIN_INTERVAL_SECONDS) {
			$remaining = self::KEYGEN_MIN_INTERVAL_SECONDS - $elapsed;
			$hours = (int)ceil($remaining / 3600);
			return "Key rotation throttled. Next rotation allowed in approximately {$hours} hour(s).";
		}

		return null;
	}//end checkRotationThrottle()
}//end class
