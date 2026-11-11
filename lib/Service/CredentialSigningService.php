<?php

/**
 * Learniq Credential Signing Service
 *
 * Builds an Open Badges 3.0 JSON-LD assertion and signs it with the tenant's
 * RS256 keypair. Legitimate PHP per ADR-031: "Cryptographic operations that
 * must run before a state transition".
 *
 * The issuing paths (CredentialIssuanceHandler, ExternalTrainingController)
 * call sign() before they save a Credential, so the signed fields travel in
 * the create itself. No lifecycle transition calls this class: OpenRegister
 * runs `requires` guards and `actions` on updates only, and a guard may not
 * write to the object, so a guard on create never signed anything (learniq#182).
 *
 * @category Service
 * @package  OCA\Learniq\Service
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

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;

/**
 * Assembles the Open Badges 3.0 JSON-LD payload and signs it via RS256.
 *
 * Single responsibility: sign. No CRUD, no notifications, no state
 * management; all of that is declared in the schema.
 */
class CredentialSigningService {


	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Nextcloud application config.
	 * @param ICrypto $crypto Encrypt/decrypt wrapper for private key storage.
	 * @param IURLGenerator $urlGenerator Generates the public verification URL.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ICrypto $crypto,
		private readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * Sign the credential held in a context array, in place.
	 *
	 * Assembles the OB3 payload, signs it, and injects `openbadges3Payload`,
	 * `signature`, `issuerDid` and `verificationUrl` back into the context's
	 * object. sign() is the form the issuing paths call.
	 *
	 * @param array<string,mixed> $transitionContext Context array whose 'object' key holds the Credential data.
	 *
	 * @return bool True if signing succeeded; false when the credential cannot be signed.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
	 */
	public function check(array &$transitionContext): bool {
		$object = &$transitionContext['object'];

		$tenantId = $object['tenant_id'] ?? '';
		if (($object['learnerId'] ?? '') === '' || $tenantId === '') {
			return false;
		}

		$issuerDid = $this->resolveIssuerDid(tenantId: $tenantId);
		if ($issuerDid === null) {
			return false;
		}

		$signedFields = $this->signedFields(object: $object, issuerDid: $issuerDid);
		if ($signedFields === null) {
			return false;
		}

		foreach ($signedFields as $field => $value) {
			$object[$field] = $value;
		}

		return true;
	}//end check()

	/**
	 * The fields signing stamps on a credential: its signed Open Badges
	 * payload with the proof embedded, the JWS, the issuer DID and the public
	 * verification URL.
	 *
	 * @param array<string,mixed> $object    The Credential data, its learner and tenant set.
	 * @param string              $issuerDid The tenant's issuer DID.
	 *
	 * @return array<string,mixed>|null The fields in stamping order, or null when the tenant cannot sign.
	 */
	private function signedFields(array $object, string $issuerDid): ?array {
		$issuer = $this->issuerFields(object: $object, issuerDid: $issuerDid);
		$payload = $this->buildOb3Payload(credential: $this->credentialFields(object: $object), issuer: $issuer);

		$proof = $this->proofFor(payload: $payload, tenantId: ($object['tenant_id'] ?? ''), issuerDid: $issuerDid);
		if ($proof === null) {
			return null;
		}

		$payload['proof'] = $proof;

		return [
			'openbadges3Payload' => $payload,
			'signature' => $proof['jws'],
			'issuerDid' => $issuerDid,
			'verificationUrl' => $issuer['verificationUrl'],
		];
	}//end signedFields()

	/**
	 * The issuer fields the Open Badges payload is built from, the public
	 * verification URL (which carries the credential id) among them.
	 *
	 * @param array<string,mixed> $object    The Credential data.
	 * @param string              $issuerDid The tenant's issuer DID.
	 *
	 * @return array{issuerDid:string,issuedBy:mixed,verificationUrl:string}
	 */
	private function issuerFields(array $object, string $issuerDid): array {
		return [
			'issuerDid' => $issuerDid,
			'issuedBy' => ($object['issuedBy'] ?? ''),
			'verificationUrl' => $this->urlGenerator->linkToRouteAbsolute(
				'learniq.credentialVerify.verify',
				['id' => ($object['id'] ?? '')]
			),
		];
	}//end issuerFields()

	/**
	 * The credential fields the Open Badges payload is built from.
	 *
	 * @param array<string,mixed> $object The Credential data.
	 *
	 * @return array<string,mixed>
	 */
	private function credentialFields(array $object): array {
		return [
			'credentialId' => $object['id'] ?? '',
			'learnerId' => $object['learnerId'] ?? '',
			'courseId' => $object['courseId'] ?? null,
			'issuedAt' => $object['issuedAt'] ?? (new DateTimeImmutable())->format(\DATE_ATOM),
			'expiresAt' => $object['expiresAt'] ?? null,
		];
	}//end credentialFields()

	/**
	 * The DataIntegrityProof block for a payload: an RS256 JWS with the
	 * tenant key and its `kid`, the same for the Open Badges payload and the
	 * Europass form (credentials-europass-edci-export).
	 *
	 * @param array<string,mixed> $payload   The payload, without a proof.
	 * @param string              $tenantId  The tenant whose key signs.
	 * @param string              $issuerDid The issuer DID, for the verification method.
	 *
	 * @return array<string,string>|null The proof, or null when the tenant cannot sign.
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
	 */
	public function proofFor(array $payload, string $tenantId, string $issuerDid): ?array {
		$jws = $this->signPayload(payload: $payload, tenantId: $tenantId);
		if ($jws === null) {
			return null;
		}

		$kid = $this->signer()->keyId(tenantId: $tenantId);

		return [
			'type' => 'DataIntegrityProof',
			'cryptosuite' => 'rsa-signature-2025',
			'created' => (new DateTimeImmutable())->format(\DATE_ATOM),
			'verificationMethod' => $issuerDid . '#' . $kid,
			'proofPurpose' => 'assertionMethod',
			'jws' => $jws,
		];
	}//end proofFor()

	/**
	 * Sign a Credential before it is saved.
	 *
	 * Gives the credential its id first when it has none, because the signed
	 * payload names the public verification URL, which carries that id. The
	 * caller saves the result under the same id (`saveObject(uuid: ...)`) so
	 * the verify route finds the credential the payload points at.
	 *
	 * @param array<string,mixed> $credential The Credential object to be saved.
	 *
	 * @return array<string,mixed>|null The credential with `id`, `openbadges3Payload`,
	 *                                  `signature`, `issuerDid` and `verificationUrl`
	 *                                  set, or null when it cannot be signed (no
	 *                                  key for the tenant, or signing failed).
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
	 */
	public function sign(array $credential): ?array {
		if ((string)($credential['id'] ?? '') === '') {
			$credential['id'] = $this->newCredentialId();
		}

		$context = ['object' => $credential];
		if ($this->check(transitionContext: $context) === false) {
			return null;
		}

		return $context['object'];
	}//end sign()

	/**
	 * A fresh RFC 4122 version 4 UUID for a credential that has no id yet.
	 *
	 * @return string The UUID.
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
	 */
	private function newCredentialId(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}//end newCredentialId()

	/**
	 * Assemble the Open Badges 3.0 JSON-LD assertion (without proof).
	 *
	 * The credential names `credentialId` (UUID of the credential being issued),
	 * `learnerId` (opaque learner UUID, never BSN), `courseId` (UUID of the
	 * associated course, or null), `issuedAt` (ISO 8601) and `expiresAt` (ISO
	 * 8601, or null). The issuer names `issuerDid` (DID of the issuing
	 * organisation), `issuedBy` (its display name) and `verificationUrl` (the
	 * public URL for unauthenticated verification).
	 *
	 * @param array{credentialId: string, learnerId: string, courseId: ?string, issuedAt: string, expiresAt: ?string} $credential The credential.
	 * @param array{issuerDid: string, issuedBy: string, verificationUrl: string}                                       $issuer     The issuer.
	 *
	 * @return array<string,mixed> OB3 JSON-LD array (without proof).
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
	 */
	public function buildOb3Payload(array $credential, array $issuer): array {
		['credentialId' => $credentialId, 'learnerId' => $learnerId, 'courseId' => $courseId] = $credential;
		['issuedAt' => $issuedAt, 'expiresAt' => $expiresAt] = $credential;
		['issuerDid' => $issuerDid, 'issuedBy' => $issuedBy, 'verificationUrl' => $verificationUrl] = $issuer;
		$payload = [
			'@context' => [
				'https://www.w3.org/2018/credentials/v1',
				'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json',
			],
			'type' => ['VerifiableCredential', 'OpenBadgeCredential'],
			'id' => $verificationUrl,
			'issuer' => [
				'id' => $issuerDid,
				'type' => 'Profile',
				'name' => $issuedBy,
			],
			'issuanceDate' => $issuedAt,
		];

		$achievementId = 'urn:learniq:manual:' . $credentialId;
		if ($courseId !== null) {
			$achievementId = 'urn:learniq:course:' . $courseId;
		}

		// #190: credentialSubject.id must never embed a BSN, email, or NC UID.
		// We UUID-validate learnerId and fall back to a hash-based pseudonym if
		// it looks like an e-mail address or otherwise non-UUID identifier, ensuring
		// PII is never embedded in a publicly shareable verifiable credential.
		$subjectId = $this->buildSubjectId(learnerId: $learnerId);

		$payload['credentialSubject'] = [
			'type' => 'AchievementSubject',
			'id' => $subjectId,
			'achievement' => [
				'type' => 'Achievement',
				'id' => $achievementId,
			],
		];

		if ($expiresAt !== null) {
			$payload['expirationDate'] = $expiresAt;
		}

		return $payload;
	}//end buildOb3Payload()

	/**
	 * Sign a canonicalised payload with the tenant's RSA private key (RS256).
	 *
	 * Produces an RFC 7515 compact JWS with detached payload (b64:false, RFC 7797).
	 * Header and signature are base64url-encoded per RFC 4648 §5. The `kid` value
	 * is derived from the SHA-256 fingerprint of the tenant's public key so that
	 * external verifiers can identify the correct verification method in the DID
	 * document without holding prior state.
	 *
	 * @param array<string,mixed> $payload The JSON-LD payload to sign.
	 * @param string $tenantId The tenant UUID whose key to use.
	 *
	 * @return string|null RS256 compact JWS string, or null if the key is absent / signing fails.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
	 */
	public function signPayload(array $payload, string $tenantId): ?string {
		return $this->signer()->sign(payload: $payload, tenantId: $tenantId);
	}//end signPayload()

	/**
	 * Build a privacy-safe credentialSubject.id from learnerId.
	 *
	 * If the learnerId is a valid UUID it is used verbatim as a URN (safe, opaque).
	 * Otherwise — to prevent embedding an email, NC UID, or BSN — the value is
	 * replaced with a deterministic SHA-256 pseudonym so no PII appears in the
	 * publicly shareable credential. Fixes #190.
	 *
	 * @param string $learnerId Raw learner identifier from the Credential record.
	 *
	 * @return string `urn:learniq:learner:{uuid-or-pseudonym}`.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
	 */

	/**
	 * Build a privacy-safe credentialSubject.id from learnerId.
	 *
	 * If the learnerId is a valid UUID it is used verbatim as a URN (safe, opaque).
	 * Otherwise — to prevent embedding an email, NC UID, or BSN — the value is
	 * replaced with a deterministic SHA-256 pseudonym so no PII appears in the
	 * publicly shareable credential. Fixes #190.
	 *
	 * @param string $learnerId Raw learner identifier from the Credential record.
	 *
	 * @return string `urn:learniq:learner:{uuid-or-pseudonym}`.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
	 */
	private function buildSubjectId(string $learnerId): string {
		// UUID v4 regex — only format guaranteed to be opaque / non-PII.
		$uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
		if (preg_match($uuidPattern, $learnerId) === 1) {
			return 'urn:learniq:learner:' . $learnerId;
		}

		// Non-UUID: pseudonymise with a stable one-way hash to avoid embedding PII.
		$pseudonym = 'pseudo-' . hash('sha256', 'learniq-learner:' . $learnerId);
		return 'urn:learniq:learner:' . $pseudonym;
	}//end buildSubjectId()

	/**
	 * Resolve the DID for a tenant from app config.
	 *
	 * Falls back to a synthetic did:web based on the public key fingerprint
	 * stored at setup time.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return string|null DID string, or null if no key has been generated yet.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
	 */
	private function resolveIssuerDid(string $tenantId): ?string {
		return $this->signer()->issuerDid(tenantId: $tenantId);
	}//end resolveIssuerDid()

	/**
	 * The tenant-key signer over this service's config and crypto.
	 *
	 * @return TenantJwsSigner
	 */
	private function signer(): TenantJwsSigner {
		return new TenantJwsSigner(appConfig: $this->appConfig, crypto: $this->crypto);
	}//end signer()
}//end class
