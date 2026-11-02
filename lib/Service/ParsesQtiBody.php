<?php

/**
 * Learniq QTI body parsing.
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
 * @spec openspec/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DOMDocument;

/**
 * Parses a QTI 2.1 item body without letting libxml warnings escape.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
 */
trait ParsesQtiBody {

	/**
	 * Parse a QTI 2.1 item body XML string, suppressing libxml warnings for
	 * a malformed body (returns null instead).
	 *
	 * @param string $qtiBody Raw QTI 2.1 XML body.
	 *
	 * @return DOMDocument|null
	 */
	private function parseQtiBody(string $qtiBody): ?DOMDocument {
		$previousSetting = libxml_use_internal_errors(true);
		$doc = new DOMDocument();
		$loaded = $doc->loadXML($qtiBody);
		libxml_clear_errors();
		libxml_use_internal_errors($previousSetting);

		if ($loaded === false) {
			return null;
		}

		return $doc;
	}//end parseQtiBody()
}//end trait
