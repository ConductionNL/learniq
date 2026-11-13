<?php

/**
 * Learniq Import File Rows
 *
 * Reads the content of an import file as rows: JSON, XML, or else CSV.
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-the-gate-hands-the-rows-of-an-import-jobs-file-to-integriq
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * The rows of an import file's content, by the file's extension.
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-the-gate-hands-the-rows-of-an-import-jobs-file-to-integriq
 */
final class ImportFileRows {

	/**
	 * Rows of a file by its extension: JSON, XML, or else CSV.
	 *
	 * @param string $name    The file name.
	 * @param string $content The content.
	 *
	 * @return array<int, array<string, mixed>>|null The rows, or null when unreadable.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-the-gate-hands-the-rows-of-an-import-jobs-file-to-integriq
	 */
	public function rowsOf(string $name, string $content): ?array {
		if (trim($content) === '') {
			return null;
		}

		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if ($extension === 'json') {
			return $this->parseJson(content: $content);
		}

		if ($extension === 'xml') {
			return $this->parseXml(content: $content);
		}

		return $this->parseCsv(content: $content);
	}//end rowsOf()

	/**
	 * JSON: a list of objects, `{records: [...]}`, or one object.
	 *
	 * @param string $content The content.
	 *
	 * @return array<int, array<string, mixed>>|null The rows.
	 */
	private function parseJson(string $content): ?array {
		$decoded = json_decode($content, true);
		if (is_array($decoded) === false) {
			return null;
		}

		$rows = array_values(array_filter(self::recordList(decoded: $decoded), 'is_array'));
		if ($rows === []) {
			return null;
		}

		return $rows;
	}//end parseJson()

	/**
	 * The records of decoded JSON: its `records` list, the list itself, or the one object.
	 *
	 * @param array<mixed> $decoded The decoded JSON.
	 *
	 * @return array<mixed>
	 */
	private static function recordList(array $decoded): array {
		if (isset($decoded['records']) === true && is_array($decoded['records']) === true) {
			$decoded = $decoded['records'];
		}

		if (array_is_list($decoded) === false) {
			$decoded = [$decoded];
		}

		return $decoded;
	}//end recordList()

	/**
	 * XML: the whole document is one record (a received dossier).
	 *
	 * @param string $content The content.
	 *
	 * @return array<int, array<string, mixed>>|null The one row.
	 */
	private function parseXml(string $content): ?array {
		$previous = libxml_use_internal_errors(true);
		// LIBXML_NONET: never fetch a DTD or entity over the network.
		$xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if ($xml === false) {
			return null;
		}

		$row = json_decode((string)json_encode($xml), true);
		if (is_array($row) === false || $row === []) {
			return null;
		}

		return [$row];
	}//end parseXml()

	/**
	 * CSV with a header row; the delimiter is `;`, `,` or a tab, whichever the header uses most.
	 *
	 * @param string $content The content.
	 *
	 * @return array<int, array<string, mixed>>|null The rows.
	 */
	private function parseCsv(string $content): ?array {
		$lines = self::nonBlankLines(content: $content);
		if ($lines === null || count($lines) < 2) {
			return null;
		}

		$delimiter = $this->delimiterOf(header: $lines[0]);
		$header = array_map('trim', str_getcsv($lines[0], $delimiter, '"', ''));
		$rows = [];
		foreach (array_slice($lines, 1) as $line) {
			$rows[] = self::csvRow(header: $header, cells: str_getcsv($line, $delimiter, '"', ''));
		}

		return $rows;
	}//end parseCsv()

	/**
	 * The lines of the content without a byte-order mark, blank lines left out.
	 *
	 * @param string $content The content.
	 *
	 * @return array<int, string>|null
	 */
	private static function nonBlankLines(string $content): ?array {
		$lines = preg_split('/\r\n|\r|\n/', ltrim($content, "\xEF\xBB\xBF"));
		if ($lines === false) {
			return null;
		}

		return array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
	}//end nonBlankLines()

	/**
	 * One CSV row keyed by the header; a blank header column is dropped, a missing cell is ''.
	 *
	 * @param array<int, string>   $header The trimmed header fields.
	 * @param array<int, mixed>    $cells  The row's cells.
	 *
	 * @return array<string, string>
	 */
	private static function csvRow(array $header, array $cells): array {
		$row = [];
		foreach ($header as $position => $field) {
			if ($field === '') {
				continue;
			}

			$row[$field] = trim((string)($cells[$position] ?? ''));
		}

		return $row;
	}//end csvRow()

	/**
	 * The delimiter a header line uses most.
	 *
	 * @param string $header The header line.
	 *
	 * @return string The delimiter.
	 */
	private function delimiterOf(string $header): string {
		$counts = [';' => substr_count($header, ';'), ',' => substr_count($header, ','), "\t" => substr_count($header, "\t")];
		arsort($counts);
		return (string)array_key_first($counts);
	}//end delimiterOf()
}//end class
