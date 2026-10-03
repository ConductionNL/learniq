<?php

/**
 * Learniq xAPI Request Body
 *
 * Reads the raw body of an xAPI document request. A State or Agent Profile
 * document can be any content type, so the body is taken as bytes rather
 * than through Nextcloud's parsed request parameters.
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
 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * The raw request body, read up to a byte limit.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-run-cmi5--xapi-natively-with-scorm-shim
 */
class XapiRequestBody {

	/**
	 * Read the body, at most `$maxBytes + 1` bytes so the caller can tell an oversized body apart.
	 *
	 * @param int $maxBytes The largest body the caller accepts.
	 *
	 * @return string The body bytes ('' when there is none).
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function read(int $maxBytes): string {
		$body = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
		if ($body === false) {
			return '';
		}

		return $body;
	}//end read()
}//end class
