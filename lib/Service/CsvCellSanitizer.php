<?php

/**
 * Learniq CSV Cell Sanitizer
 *
 * Single-purpose collaborator shared by every CSV artefact Learniq emits, so
 * formula-injection neutralisation is applied identically everywhere instead of
 * being re-implemented per builder.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Neutralises spreadsheet formula injection in exported CSV cells.
 *
 * @psalm-api
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
 */
class CsvCellSanitizer {
	/**
	 * Sanitize a single CSV cell to prevent formula-injection attacks.
	 *
	 * Excel and LibreOffice Calc treat cells starting with `=`, `+`, `-`, `@`, `\t`,
	 * or `\r` as formula expressions. Prefixing such values with a tab character
	 * neutralises the injection without altering the visible cell content in most
	 * spreadsheet applications. Fixes #191.
	 *
	 * @param string $value The raw cell value.
	 *
	 * @return string The sanitised cell value.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
	 */
	public function sanitize(string $value): string {
		if ($value === '') {
			return $value;
		}

		// Trim leading whitespace first so we test the true first character.
		$first = $value[0];
		if (in_array($first, ['=', '+', '-', '@', "\t", "\r"], strict: true) === true) {
			return "\t" . $value;
		}

		return $value;
	}//end sanitize()
}//end class
