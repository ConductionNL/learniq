<?php

/**
 * Learniq JWS Proof Verifier
 *
 * Verifies the detached RS256 JWS in a signed payload's `proof` against the
 * tenant key its `kid` names, with the JCS key ordering CredentialSigningService
 * signs with. Shared by the public verification route for the Open Badges
 * payload and for a Europass file (credentials-europass-edci-export); moved
 * out of CredentialVerifyController unchanged.
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
 * @spec openspec/changes/credentials-europass-edci-export/specs/certification/spec.md#requirement-the-europass-form-is-checkable-on-the-verification-route
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Checks a payload's detached JWS proof.
 *
 * @spec openspec/changes/credentials-europass-edci-export/specs/certification/spec.md#requirement-the-europass-form-is-checkable-on-the-verification-route
 */
class JwsProofVerifier {

	/**
	 * Constructor.
	 *
	 * @param KeyManagementService $keys Resolves the tenant public key by fingerprint.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly KeyManagementService $keys,
	) {
	}//end __construct()

	/**
	 * Whether the payload's `proof.jws` verifies against the tenant key its
	 * `kid` names. Fail-closed: no proof, unknown kid or a bad signature is false.
	 *
	 * @param array<string,mixed> $payload  The signed payload with its `proof`.
	 * @param string              $tenantId The tenant.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/credentials-europass-edci-export/specs/certification/spec.md#requirement-the-europass-form-is-checkable-on-the-verification-route
	 */
	public function verify(array $payload, string $tenantId): bool {
		$jws = $payload['proof']['jws'] ?? null;
		if (is_string($jws) === false || $jws === '' || $tenantId === '') {
			return false;
		}

		$kid = $this->extractKidFromJws(jws: $jws);
		if ($kid === null) {
			return false;
		}

		$publicKeyPem = $this->keys->resolvePublicKeyByFingerprint(tenantId: $tenantId, fingerprint: $kid);
		if ($publicKeyPem === null) {
			return false;
		}

		return $this->verifyJwsSignature(jws: $jws, payload: $payload, publicKeyPem: $publicKeyPem);
	}//end verify()

	/**
	 * Extract the `kid` value from a compact JWS protected header.
	 *
	 * The JWS format produced by CredentialSigningService is:
	 *   <base64url-header>..<base64url-signature>   (detached payload, b64:false)
	 *
	 * @param string $jws Compact JWS string.
	 *
	 * @return string|null The kid value, or null if unparseable.
	 */
	private function extractKidFromJws(string $jws): ?string {
		// JWS with detached payload: "<header>..<signature>" — split on first '.'.
		$dotPos = strpos($jws, '.');
		if ($dotPos === false) {
			return null;
		}

		$headerB64 = substr($jws, 0, $dotPos);
		if ($headerB64 === '') {
			return null;
		}

		// Decode base64url → JSON.
		$padded = str_pad($headerB64, (int)ceil(strlen($headerB64) / 4) * 4, '=');
		$headerJson = base64_decode(strtr($padded, '-_', '+/'), strict: true);
		if ($headerJson === false) {
			return null;
		}

		$header = json_decode($headerJson, associative: true);
		if (is_array($header) === false) {
			return null;
		}

		$kid = $header['kid'] ?? null;
		if (is_string($kid) === false || $kid === '') {
			return null;
		}

		return $kid;
	}//end extractKidFromJws()

	/**
	 * Verify an RS256 JWS signature using the provided public key.
	 *
	 * Recomputes the signing input (<header>.<payload>) and calls openssl_verify.
	 * The payload is the canonicalised (json_encode) OB3 payload WITHOUT the proof
	 * block, matching what CredentialSigningService::signPayload signed.
	 *
	 * @param string $jws Compact JWS string (detached payload, b64:false).
	 * @param array<string,mixed> $payload The full OB3 payload (proof block will be excluded).
	 * @param string $publicKeyPem PEM-encoded RSA public key.
	 *
	 * @return bool True when openssl_verify returns 1 (valid).
	 */
	private function verifyJwsSignature(string $jws, array $payload, string $publicKeyPem): bool {
		// Split: "<header>..<signature>" → header and signature parts.
		$parts = explode('..', $jws, 2);
		if (count($parts) !== 2) {
			return false;
		}

		[$headerB64, $sigB64] = $parts;
		if ($headerB64 === '' || $sigB64 === '') {
			return false;
		}

		// The signing input is header + '.' + canonicalised payload WITHOUT proof.
		// Remove the proof block to match what was signed.
		$payloadToVerify = $payload;
		unset($payloadToVerify['proof']);

		// H6: RFC 8785 (JCS) — sort keys recursively before encoding so that the
		// verify-side signing input matches the sign-side input exactly.
		$payloadToVerify = $this->canonical(payload: $payloadToVerify);

		$canonicalised = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($canonicalised === false) {
			return false;
		}

		$signingInput = $headerB64 . '.' . $canonicalised;

		// Decode base64url signature.
		$padded = str_pad($sigB64, (int)ceil(strlen($sigB64) / 4) * 4, '=');
		$signature = base64_decode(strtr($padded, '-_', '+/'), strict: true);
		if ($signature === false) {
			return false;
		}

		$pubKey = openssl_pkey_get_public($publicKeyPem);
		if ($pubKey === false) {
			return false;
		}

		$result = openssl_verify($signingInput, $signature, $pubKey, OPENSSL_ALGO_SHA256);

		return $result === 1;
	}//end verifyJwsSignature()

	/**
	 * Recursively sort an array's keys (RFC 8785 JCS) for deterministic JSON output.
	 *
	 * Mirrors CredentialSigningService::canonicalisePayload so that the verify-side
	 * signing input is byte-for-byte identical to the sign-side input.
	 *
	 * @param array<string,mixed> $payload The payload to canonicalise.
	 *
	 * @return array<string,mixed> The same data with all object-level keys sorted.
	 *
	 * @spec openspec/changes/credentials-europass-edci-export/specs/certification/spec.md#requirement-the-europass-form-is-checkable-on-the-verification-route
	 */
	public function canonical(array $payload): array {
		$isObject = count(array_filter(array_keys($payload), 'is_string')) > 0;

		if ($isObject === true) {
			ksort($payload, SORT_STRING);
		}

		foreach ($payload as $key => $value) {
			if (is_array($value) === true) {
				$payload[$key] = $this->canonical(payload: $value);
			}
		}

		return $payload;
	}//end canonical()
}//end class
