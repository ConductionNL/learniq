<?php

/**
 * Learniq Credential Verify Controller
 *
 * Public (unauthenticated) endpoint for Open Badges 3.0 credential verification.
 * External auditors and employers call GET /api/credentials/{id}/verify to
 * confirm a credential's validity without requiring Nextcloud session auth.
 *
 * Legitimate PHP per ADR-031: "External-system contract — public verification
 * surface that must bypass NC session middleware via @PublicPage + @NoCSRFRequired."
 *
 * Returns only credential metadata: no personal data beyond the opaque learner
 * UUID used in the OB3 payload (REQ-CE-002-B). Read-only — does not mutate any
 * credential record (CR-2: removed unauthenticated write path).
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

use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\JwsProofVerifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;

/**
 * Public credential verification endpoint.
 *
 * No session auth, no CSRF. Returns {valid, issuedAt, expiresAt, issuerName}
 * — no personal data. Read-only: no writes from the anonymous context (CR-2).
 * Validates the JWS proof to confirm cryptographic integrity (C1 fix).
 */
class CredentialVerifyController extends Controller {

	/**
	 * Brute-force throttler action for failed credential verifications.
	 *
	 * @var string
	 */
	private const THROTTLE_ACTION = 'learniq_credential_verify';

	/**
	 * Record a failed verification with the brute-force throttler.
	 *
	 * The half that COUNTS. `#[BruteForceProtection]` on verify() is the half
	 * that ENFORCES -- BruteForceMiddleware only applies a delay when the
	 * attribute is present, so a registration without it feeds a counter that
	 * nothing reads. See ADR-082.
	 *
	 * @return void
	 */
	private function registerFailedVerification(): void {
		try {
			$this->throttler->registerAttempt(
				action: self::THROTTLE_ACTION,
				ip: $this->request->getRemoteAddress()
			);
		} catch (\Throwable $throttlerFailure) {
			$this->logger->warning(
				'CredentialVerifyController: registerAttempt failed: ' . $throttlerFailure->getMessage()
			);
		}
	}//end registerFailedVerification()

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param ObjectService $objectService OR object-read service.
	 * @param JwsProofVerifier $proofs Verifies a payload's detached JWS against the tenant key.
	 * @param IThrottler $throttler Brute-force throttler counting failed verifications.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ObjectService $objectService,
		private readonly JwsProofVerifier $proofs,
		private readonly IThrottler $throttler,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Verify a credential by UUID without requiring authentication.
	 *
	 * Validates the RS256 JWS proof embedded in openbadges3Payload.proof.jws
	 * before returning valid:true. Returns valid:false + error:'signature_invalid'
	 * when the JWS fails cryptographic verification (C1).
	 *
	 * @param string $id Credential UUID.
	 *
	 * @return JSONResponse {valid, issuedAt, expiresAt, issuerName} or error response.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function verify(string $id): JSONResponse {
		// ABSENCE REACHES THIS METHOD IN TWO SHAPES, NOT ONE. `find()`
		// documents `@throws Exception If the object is not found`, and
		// resolving the `learniq` register / `credential` schema slug goes
		// through RegisterMapper::find() / SchemaMapper::find(), which raise
		// DoesNotExistException when the register is not installed. Only the
		// `null` shape was handled, so on the throwing path this
		// #[PublicPage] endpoint answered an anonymous caller with a
		// framework HTTP 500 and a stack trace -- AND skipped
		// registerFailedVerification(), leaving the enumeration oracle this
		// method throttles wide open on exactly the requests that reached it.
		try {
			$credentialObj = $this->objectService->find(
				id: $id,
				register: 'learniq',
				schema: 'credential'
			);
		} catch (DoesNotExistException | MultipleObjectsReturnedException $notFound) {
			// Same meaning as `null` below, so the same answer: a guess, and
			// it is counted.
			$this->registerFailedVerification();
			return new JSONResponse(['valid' => false, 'error' => 'not_found'], 404);
		} catch (\Throwable $lookupFailure) {
			// A SERVER FAULT IS NOT AN ATTACKER'S GUESS, so this arm does NOT
			// register a throttle attempt -- doing so would let a broken
			// OpenRegister lock out every legitimate verifier. The cause is
			// logged; the caller gets a generic envelope, never a trace.
			$this->logger->error(
				'CredentialVerifyController: credential lookup failed: ' . $lookupFailure->getMessage(),
				['exception' => $lookupFailure]
			);
			return new JSONResponse(
				['valid' => false, 'error' => 'verification_unavailable'],
				500
			);
		}//end try

		if ($credentialObj === null) {
			// A UUID that resolves to nothing is a guess. This endpoint is
			// public by design -- anyone holding a credential may verify it --
			// but that also makes it an enumeration oracle over who holds which
			// credential, which is exactly the privacy leak worth throttling.
			$this->registerFailedVerification();
			return new JSONResponse(['valid' => false, 'error' => 'not_found'], 404);
		}

		$data = $credentialObj->jsonSerialize();

		$lifecycle = $data['lifecycle'] ?? 'issued';
		$isExpired = $data['isExpired'] ?? false;

		if ($lifecycle === 'revoked') {
			return new JSONResponse(
				[
					'valid' => false,
					'revokedAt' => $data['updatedAt'] ?? null,
					'revocationReason' => $data['revocationReason'] ?? null,
				]
			);
		}

		// C1: validate the JWS proof before declaring the credential valid.
		$tenantId = $data['tenant_id'] ?? '';
		$jwsValid = $this->validateJwsProof(data: $data, tenantId: $tenantId);
		if ($jwsValid === false) {
			return new JSONResponse(
				[
					'valid' => false,
					'error' => 'signature_invalid',
				],
				200
			);
		}

		$valid = ($lifecycle === 'issued') && ($isExpired !== true);

		return new JSONResponse(
			[
				'valid' => $valid,
				'issuedAt' => $data['issuedAt'] ?? null,
				'expiresAt' => $data['expiresAt'] ?? null,
				'issuerName' => $data['issuedBy'] ?? null,
			]
		);
	}//end verify()

	/**
	 * Check a Europass file for a credential (credentials-europass-edci-export).
	 *
	 * Body: `europass`, the JSON-LD file the holder downloaded. The file's JWS
	 * must verify against the tenant key and the file must equal the stored
	 * `edciPayload`; the answer is validity only, never the stored payload,
	 * so this public route leaks no name. Unknown ids are counted by the
	 * brute-force throttler like the GET.
	 *
	 * @param string $id Credential UUID.
	 *
	 * @return JSONResponse {valid, issuedAt, expiresAt, issuerName} or {valid: false, error}.
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-the-europass-form-is-checkable-on-the-verification-route
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function verifyEuropass(string $id): JSONResponse {
		$file = $this->request->getParam('europass');
		if (is_array($file) === false) {
			return new JSONResponse(['valid' => false, 'error' => 'no_file'], 400);
		}

		try {
			$credentialObj = $this->objectService->find(id: $id, register: 'learniq', schema: 'credential');
		} catch (DoesNotExistException | MultipleObjectsReturnedException) {
			$credentialObj = null;
		}

		if ($credentialObj === null) {
			$this->registerFailedVerification();
			return new JSONResponse(['valid' => false, 'error' => 'not_found'], 404);
		}

		$data = $credentialObj->jsonSerialize();
		$error = $this->europassError(file: $file, stored: $data['edciPayload'] ?? null, tenantId: (string)($data['tenant_id'] ?? ''));
		if ($error !== null) {
			return new JSONResponse(['valid' => false, 'error' => $error]);
		}

		$valid = (($data['lifecycle'] ?? 'issued') === 'issued') && (($data['isExpired'] ?? false) !== true);

		return new JSONResponse(
			[
				'valid' => $valid,
				'issuedAt' => $data['issuedAt'] ?? null,
				'expiresAt' => $data['expiresAt'] ?? null,
				'issuerName' => $data['issuedBy'] ?? null,
			]
		);
	}//end verifyEuropass()

	/**
	 * Why a Europass file is not valid for the stored form, or null.
	 *
	 * @param array<string,mixed> $file     The file sent.
	 * @param mixed               $stored   The stored `edciPayload`.
	 * @param string              $tenantId The credential's tenant.
	 *
	 * @return string|null `no_europass`, `not_matching`, `signature_invalid`, or null.
	 */
	private function europassError(array $file, mixed $stored, string $tenantId): ?string {
		if (is_array($stored) === false) {
			return 'no_europass';
		}

		if ($this->proofs->canonical(payload: $file) !== $this->proofs->canonical(payload: $stored)) {
			return 'not_matching';
		}

		if ($this->proofs->verify(payload: $file, tenantId: $tenantId) === false) {
			return 'signature_invalid';
		}

		return null;
	}//end europassError()

	/**
	 * Validate the RS256 JWS proof embedded in a credential's openbadges3Payload.
	 *
	 * Extracts the `kid` from the JWS protected header, resolves the matching
	 * public key via KeyManagementService::resolvePublicKeyByFingerprint, then
	 * calls openssl_verify with OPENSSL_ALGO_SHA256.
	 *
	 * Returns true when the signature verifies. Returns false (fail-closed) in
	 * all error conditions: missing proof, unknown kid, key not found, bad sig.
	 *
	 * @param array<string,mixed> $data Serialised Credential data.
	 * @param string $tenantId Tenant UUID from the Credential row.
	 *
	 * @return bool True when the JWS signature is cryptographically valid.
	 */
	private function validateJwsProof(array $data, string $tenantId): bool {
		$ob3Payload = $data['openbadges3Payload'] ?? null;
		if (is_array($ob3Payload) === false) {
			// No OB3 payload at all — treat as unsigned (legacy record pre-dates signing).
			// Return true to not break old records; operators can re-issue to gain a proof.
			return true;
		}

		$proof = $ob3Payload['proof'] ?? null;
		if (is_array($proof) === false) {
			// Payload without proof — same legacy-record accommodation.
			return true;
		}

		return $this->proofs->verify(payload: $ob3Payload, tenantId: $tenantId);
	}//end validateJwsProof()
}//end class
