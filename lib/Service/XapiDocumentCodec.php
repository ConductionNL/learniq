<?php

/**
 * Learniq xAPI Document Codec
 *
 * How the bytes of an xAPI State or Agent Profile document are stored and
 * compared: text as is, anything that is not valid UTF-8 as base64, and the
 * ETag as the quoted SHA-1 of the original bytes (xAPI 1.0.3 Communication
 * 3.1). Also decides whether a body is a JSON object, which a POST merge needs.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Encodes, decodes and fingerprints document bodies.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-run-cmi5--xapi-natively-with-scorm-shim
 */
class XapiDocumentCodec {

	/**
	 * The quoted xAPI ETag of a document body: the SHA-1 of its bytes.
	 *
	 * @param string $contents The raw body.
	 *
	 * @return string The ETag, with quotes.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function etag(string $contents): string {
		return '"' . sha1($contents) . '"';
	}//end etag()

	/**
	 * The stored form of a body.
	 *
	 * @param string $contents The raw body.
	 *
	 * @return array{contents: string, contentEncoding: string} The stored string and how it is encoded.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function encode(string $contents): array {
		if (mb_check_encoding($contents, 'UTF-8') === true) {
			return ['contents' => $contents, 'contentEncoding' => 'utf-8'];
		}

		return ['contents' => base64_encode($contents), 'contentEncoding' => 'base64'];
	}//end encode()

	/**
	 * The original bytes of a stored row.
	 *
	 * @param array<string, mixed> $row The stored row.
	 *
	 * @return string The body.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function decode(array $row): string {
		$contents = (string)($row['contents'] ?? '');
		if (($row['contentEncoding'] ?? 'utf-8') !== 'base64') {
			return $contents;
		}

		return (string)base64_decode($contents, true);
	}//end decode()

	/**
	 * Decode a body as a JSON object, or null when it is anything else.
	 *
	 * @param string $contents The body.
	 *
	 * @return array<string, mixed>|null The object's members, or null.
	 *
	 * @spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function jsonObject(string $contents): ?array {
		$trimmed = ltrim($contents);
		if (str_starts_with($trimmed, '{') === false) {
			return null;
		}

		$decoded = json_decode($trimmed, true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end jsonObject()
}//end class
