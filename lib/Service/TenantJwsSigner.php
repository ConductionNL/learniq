<?php

/**
 * Learniq tenant RS256 signer.
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
 * @spec openspec/specs/portable-learning-record/spec.md#scenario-the-signature-verifies-against-the-tenant-s-existing-public-key
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCP\IAppConfig;
use OCP\Security\ICrypto;

/**
 * Signs a payload with the tenant's stored RS256 keypair as a detached JWS,
 * and names the tenant's synthetic did:web issuer. Built by the services
 * that sign credentials and learning-record exports.
 *
 * @spec openspec/specs/portable-learning-record/spec.md#scenario-the-signature-verifies-against-the-tenant-s-existing-public-key
 */
final class TenantJwsSigner {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Nextcloud application config holding the keypair.
	 * @param ICrypto    $crypto    Decrypts the stored private key.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ICrypto $crypto,
	) {
	}//end __construct()

	/**
	 * Canonicalise (RFC 8785 JCS) and sign a payload with the tenant's RS256
	 * private key, producing a compact JWS with a detached payload
	 * (`b64:false`, RFC 7797) whose `kid` is the public-key fingerprint.
	 *
	 * @param array<string,mixed> $payload  The payload to canonicalise and sign.
	 * @param string              $tenantId Tenant UUID whose key to use.
	 *
	 * @return string|null Compact JWS string, or null when the key is absent / signing fails.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-the-signature-verifies-against-the-tenant-s-existing-public-key
	 */
	public function sign(array $payload, string $tenantId): ?string {
		$privateKeyPem = $this->privateKeyPem(tenantId: $tenantId);
		if ($privateKeyPem === null) {
			return null;
		}

		$signingInput = $this->signingInput(payload: $payload, kid: $this->keyId(tenantId: $tenantId));
		if ($signingInput === null) {
			return null;
		}

		return $this->signDetached(signingInput: $signingInput, privateKeyPem: $privateKeyPem);
	}//end sign()

	/**
	 * The tenant's synthetic did:web issuer, derived from the public key's
	 * 32-character (128-bit) fingerprint to match KeyManagementService.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return string|null DID string, or null when no key has been generated yet.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-the-signature-verifies-against-the-tenant-s-existing-public-key
	 */
	public function issuerDid(string $tenantId): ?string {
		$publicKey = $this->publicKeyPem(tenantId: $tenantId);
		if ($publicKey === '') {
			return null;
		}

		return 'did:web:learniq:' . $tenantId . ':' . substr(hash('sha256', $publicKey), 0, 32);
	}//end issuerDid()

	/**
	 * The tenant's stored public key PEM.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return string PEM, or '' when no key has been generated yet.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-the-signature-verifies-against-the-tenant-s-existing-public-key
	 */
	public function publicKeyPem(string $tenantId): string {
		return $this->appConfig->getValueString(
			app: 'learniq',
			key: SigningKeyConfigKey::forTenant(purpose: SigningKeyConfigKey::PUBLIC, tenantId: $tenantId),
			default: ''
		);
	}//end publicKeyPem()

	/**
	 * The tenant's decrypted private key PEM.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return string|null PEM, or null when none is stored or it does not decrypt.
	 */
	private function privateKeyPem(string $tenantId): ?string {
		$encryptedPrivateKey = $this->appConfig->getValueString(
			app: 'learniq',
			key: SigningKeyConfigKey::forTenant(purpose: SigningKeyConfigKey::PRIVATE, tenantId: $tenantId),
			default: ''
		);

		if ($encryptedPrivateKey === '') {
			return null;
		}

		try {
			return $this->crypto->decrypt($encryptedPrivateKey);
		} catch (\Exception) {
			return null;
		}
	}//end privateKeyPem()

	/**
	 * The key id: the public-key fingerprint (32 characters, matching
	 * KeyManagementService), or 'unknown' when no public key is stored.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return string
	 */
	private function keyId(string $tenantId): string {
		$publicKeyPem = $this->publicKeyPem(tenantId: $tenantId);

		$kid = 'unknown';
		if ($publicKeyPem !== '') {
			$kid = substr(hash('sha256', $publicKeyPem), 0, 32);
		}

		return $kid;
	}//end keyId()

	/**
	 * The signing input: the base64url protected header, a dot, and the
	 * payload's canonical JSON.
	 *
	 * @param array<string,mixed> $payload The payload to sign.
	 * @param string              $kid     The key id.
	 *
	 * @return string|null Null when the payload can not be encoded.
	 */
	private function signingInput(array $payload, string $kid): ?string {
		$canonicalised = JcsCanonicaliser::encode(payload: $payload);
		if ($canonicalised === null) {
			return null;
		}

		$headerJson = json_encode(['alg' => 'RS256', 'b64' => false, 'crit' => ['b64'], 'kid' => $kid]);

		return DetachedJws::base64UrlEncode(bytes: $headerJson) . '.' . $canonicalised;
	}//end signingInput()

	/**
	 * Sign the input and return `<header>..<signature>`.
	 *
	 * @param string $signingInput  `<header>.<canonical payload>`.
	 * @param string $privateKeyPem The private key PEM.
	 *
	 * @return string|null Compact JWS, or null when openssl can not sign.
	 */
	private function signDetached(string $signingInput, string $privateKeyPem): ?string {
		$signature = '';
		$result = openssl_sign($signingInput, $signature, $privateKeyPem, OPENSSL_ALGO_SHA256);
		if ($result === false) {
			return null;
		}

		$header = substr($signingInput, 0, (int)strpos($signingInput, '.'));

		return $header . '..' . DetachedJws::base64UrlEncode(bytes: $signature);
	}//end signDetached()
}//end class
