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
 * @spec openspec/specs/certification/spec.md#requirement-the-europass-form-is-checkable-on-the-verification-route
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Kernel\Contracts\JwsProofVerifierInterface;

/**
 * Checks a payload's detached JWS proof.
 *
 * @spec openspec/specs/certification/spec.md#requirement-the-europass-form-is-checkable-on-the-verification-route
 */
class JwsProofVerifier implements JwsProofVerifierInterface {

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
	 * @spec openspec/specs/certification/spec.md#requirement-the-europass-form-is-checkable-on-the-verification-route
	 */
	public function verify(array $payload, string $tenantId): bool {
		$jws = $payload['proof']['jws'] ?? null;
		if (is_string($jws) === false || $jws === '') {
			return false;
		}

		$publicKeyPem = $this->publicKeyFor(jws: $jws, tenantId: $tenantId);
		if ($publicKeyPem === null) {
			return false;
		}

		$parsed = DetachedJws::parse(jws: $jws);

		return ($parsed !== null && $parsed->verifies(payload: $payload, publicKeyPem: $publicKeyPem) === true);
	}//end verify()

	/**
	 * The tenant public key the JWS's `kid` names.
	 *
	 * @param string $jws      Compact JWS string.
	 * @param string $tenantId The tenant, '' when unknown.
	 *
	 * @return string|null PEM, or null without a tenant, a readable kid or a matching key.
	 */
	private function publicKeyFor(string $jws, string $tenantId): ?string {
		if ($tenantId === '') {
			return null;
		}

		$kid = $this->extractKidFromJws(jws: $jws);
		if ($kid === null) {
			return null;
		}

		return $this->keys->resolvePublicKeyByFingerprint(tenantId: $tenantId, fingerprint: $kid);
	}//end publicKeyFor()

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

		return $this->kidFromHeader(headerB64: $headerB64);
	}//end extractKidFromJws()

	/**
	 * The `kid` a base64url protected header names.
	 *
	 * @param string $headerB64 Base64url JSON header.
	 *
	 * @return string|null The kid, or null when the header does not decode or names none.
	 */
	private function kidFromHeader(string $headerB64): ?string {
		$headerJson = DetachedJws::base64UrlDecode(encoded: $headerB64);
		if ($headerJson === null) {
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
	}//end kidFromHeader()

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
	 * @spec openspec/specs/certification/spec.md#requirement-the-europass-form-is-checkable-on-the-verification-route
	 */
	public function canonical(array $payload): array {
		return JcsCanonicaliser::canonicalise(payload: $payload);
	}//end canonical()
}//end class
