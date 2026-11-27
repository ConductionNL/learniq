<?php

/**
 * Learniq RFC 8785 (JCS) key ordering.
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
 * Sorts a payload's object keys recursively (RFC 8785 JCS) so a signing input
 * is byte-for-byte the same on the sign side and the verify side, regardless
 * of PHP version or array construction order.
 *
 * @spec openspec/specs/portable-learning-record/spec.md#scenario-the-signature-verifies-against-the-tenant-s-existing-public-key
 */
final class JcsCanonicaliser {

	/**
	 * The payload with every JSON-object level's keys sorted; JSON-array
	 * levels (no string key) keep their order.
	 *
	 * @param array<mixed> $payload The payload to canonicalise.
	 *
	 * @return array<mixed> The same data with all object keys sorted.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-the-signature-verifies-against-the-tenant-s-existing-public-key
	 */
	public static function canonicalise(array $payload): array {
		// Detect whether this level is a JSON object (string keys) or JSON array (int keys).
		$isObject = count(array_filter(array_keys($payload), 'is_string')) > 0;

		if ($isObject === true) {
			ksort($payload, SORT_STRING);
		}

		foreach ($payload as $key => $value) {
			if (is_array($value) === true) {
				$payload[$key] = self::canonicalise(payload: $value);
			}
		}

		return $payload;
	}//end canonicalise()

	/**
	 * The canonical JSON of a payload, as it is signed and verified.
	 *
	 * @param array<mixed> $payload The payload.
	 *
	 * @return string|null Canonical JSON, or null when the payload can not be encoded.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-the-signature-verifies-against-the-tenant-s-existing-public-key
	 */
	public static function encode(array $payload): ?string {
		$canonicalised = json_encode(self::canonicalise(payload: $payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($canonicalised === false) {
			return null;
		}

		return $canonicalised;
	}//end encode()
}//end class
