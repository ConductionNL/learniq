<?php

/**
 * Learniq Learning Record Export Signing Service
 *
 * Canonicalises (RFC 8785 JCS) and signs a LearningRecordExport bundle with
 * the tenant's existing RS256 keypair — the SAME IAppConfig keys
 * (`SigningKeyConfigKey::forTenant()`) `KeyManagementService` generates and `CredentialSigningService` already signs Credentials with.
 * No new key material, no new crypto primitive: the canonicalisation +
 * signing routine is ported verbatim from `CredentialSigningService`
 * (design.md: "reuse or port CredentialSigningService's RFC 8785 JCS
 * canonicalisation" — those methods are private on that class, so porting,
 * not sharing a trait, is the precedented shape; `WerkprocesAssessment`/
 * `PokSignature` are this register's own precedent for a parallel schema
 * over a shared shape instead of a widened one).
 *
 * Legitimate PHP per ADR-031 "Cryptographic operations" — the same
 * exception category `CredentialSigningService`/`KeyManagementService`
 * already occupy.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/specs/portable-learning-record/spec.md#requirement-the-export-bundle-is-cryptographically-signed-and-its-artefact-retained
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Kernel\Contracts\LearningRecordExportSigningServiceInterface;
use OCP\IAppConfig;
use OCP\Security\ICrypto;

/**
 * Signs and verifies a LearningRecordExport bundle with the tenant's
 * existing RS256 keypair.
 *
 * @spec openspec/changes/archive/2026-07-16-portable-learning-record/tasks.md#task-2-4
 */
class LearningRecordExportSigningService implements LearningRecordExportSigningServiceInterface {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Nextcloud application config.
	 * @param ICrypto $crypto Encrypt/decrypt wrapper for private key storage.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ICrypto $crypto,
	) {
	}//end __construct()

	/**
	 * Resolve the tenant's issuer DID the same way
	 * `CredentialSigningService::resolveIssuerDid()` does — a synthetic
	 * `did:web` derived from the tenant public key's fingerprint.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return string|null DID string, or null when no key has been generated yet.
	 *
	 * @spec openspec/changes/archive/2026-07-16-portable-learning-record/tasks.md#task-2-4
	 */
	public function resolveIssuerDid(string $tenantId): ?string {
		return $this->signer()->issuerDid(tenantId: $tenantId);
	}//end resolveIssuerDid()

	/**
	 * Canonicalise (RFC 8785 JCS) and sign a bundle with the tenant's RS256
	 * private key, producing a compact JWS with a detached payload
	 * (`b64:false`, RFC 7797) — identical shape to
	 * `CredentialSigningService::signPayload()`.
	 *
	 * @param array<string,mixed> $bundle The bundle to canonicalise and sign.
	 * @param string $tenantId Tenant UUID whose key to use.
	 *
	 * @return string|null Compact JWS string, or null when the key is absent / signing fails.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-the-signature-verifies-against-the-tenant-s-existing-public-key
	 */
	public function sign(array $bundle, string $tenantId): ?string {
		return $this->signer()->sign(payload: $bundle, tenantId: $tenantId);
	}//end sign()

	/**
	 * Verify a compact JWS against the tenant's public key, re-canonicalising
	 * the same bundle the caller believes was signed — mirrors
	 * `CredentialVerifyController::verifyJwsSignature()`.
	 *
	 * @param string $jws Compact JWS string produced by {@see self::sign()}.
	 * @param array<string,mixed> $bundle The bundle believed to have been signed.
	 * @param string $tenantId Tenant UUID whose public key to verify against.
	 *
	 * @return bool True when the signature is cryptographically valid.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-public-verification-page-resolves-an-active-unexpired-share-and-denies-otherwise
	 */
	public function verify(string $jws, array $bundle, string $tenantId): bool {
		$parsed = DetachedJws::parse(jws: $jws);
		if ($parsed === null) {
			return false;
		}

		$publicKeyPem = $this->signer()->publicKeyPem(tenantId: $tenantId);
		if ($publicKeyPem === '') {
			return false;
		}

		// The stored/downloaded artifact embeds `proof` alongside the signed
		// payload (mirrors Credential.openbadges3Payload.proof); the
		// signing input never included it — verifies() strips it before
		// re-canonicalising, exactly as JwsProofVerifier does.
		return $parsed->verifies(payload: $bundle, publicKeyPem: $publicKeyPem);
	}//end verify()

	/**
	 * The tenant-key signer over this service's config and crypto.
	 *
	 * @return TenantJwsSigner
	 */
	private function signer(): TenantJwsSigner {
		return new TenantJwsSigner(appConfig: $this->appConfig, crypto: $this->crypto);
	}//end signer()
}//end class
