<?php

/**
 * Learniq detached-payload compact JWS.
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

/**
 * A compact RS256 JWS with a detached, unencoded payload (`b64:false`,
 * RFC 7797): `<base64url header>..<base64url signature>`. The signing input
 * is the header, a dot, and the payload's canonical JSON without its `proof`.
 *
 * @spec openspec/specs/portable-learning-record/spec.md#scenario-the-signature-verifies-against-the-tenant-s-existing-public-key
 */
final class DetachedJws {

	/**
	 * Constructor.
	 *
	 * @param string $header    Base64url protected header.
	 * @param string $signature Base64url signature.
	 */
	public function __construct(
		public readonly string $header,
		public readonly string $signature,
	) {
	}//end __construct()

	/**
	 * Split a compact JWS into its header and signature.
	 *
	 * @param string $jws Compact JWS string.
	 *
	 * @return self|null Null when it is not `<header>..<signature>` with both parts present.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-the-signature-verifies-against-the-tenant-s-existing-public-key
	 */
	public static function parse(string $jws): ?self {
		$parts = explode('..', $jws, 2);
		if (count($parts) !== 2) {
			return null;
		}

		[$headerB64, $sigB64] = $parts;
		if ($headerB64 === '' || $sigB64 === '') {
			return null;
		}

		return new self(header: $headerB64, signature: $sigB64);
	}//end parse()

	/**
	 * Base64url-encode bytes without padding (RFC 7515).
	 *
	 * @param string $bytes Raw bytes.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-the-signature-verifies-against-the-tenant-s-existing-public-key
	 */
	public static function base64UrlEncode(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}//end base64UrlEncode()

	/**
	 * Strictly decode an unpadded base64url string.
	 *
	 * @param string $encoded Base64url text.
	 *
	 * @return string|null Raw bytes, or null when it is not valid base64url.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-the-signature-verifies-against-the-tenant-s-existing-public-key
	 */
	public static function base64UrlDecode(string $encoded): ?string {
		$padded = str_pad($encoded, (int)ceil(strlen($encoded) / 4) * 4, '=');
		$decoded = base64_decode(strtr($padded, '-_', '+/'), strict: true);
		if ($decoded === false) {
			return null;
		}

		return $decoded;
	}//end base64UrlDecode()

	/**
	 * Whether the signature verifies over the payload (its `proof` left out)
	 * against an RSA public key.
	 *
	 * @param array<string,mixed> $payload      The payload believed to have been signed.
	 * @param string              $publicKeyPem PEM-encoded RSA public key.
	 *
	 * @return bool True when openssl_verify returns 1.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-the-signature-verifies-against-the-tenant-s-existing-public-key
	 */
	public function verifies(array $payload, string $publicKeyPem): bool {
		// The signing input never included `proof`: strip it before re-canonicalising.
		unset($payload['proof']);
		$canonicalised = JcsCanonicaliser::encode(payload: $payload);
		if ($canonicalised === null) {
			return false;
		}

		$signature = self::base64UrlDecode(encoded: $this->signature);
		if ($signature === null) {
			return false;
		}

		$pubKey = openssl_pkey_get_public($publicKeyPem);
		if ($pubKey === false) {
			return false;
		}

		return openssl_verify($this->header . '.' . $canonicalised, $signature, $pubKey, OPENSSL_ALGO_SHA256) === 1;
	}//end verifies()
}//end class
