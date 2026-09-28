<?php

/**
 * Learniq Exchange Import Input
 *
 * Reads the file an import job names into the records learniq's gate hands
 * to integriq: integriq maps them and hands them back for landing.
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
 * @spec openspec/changes/import-records-in-gate-answer/specs/data-exchange/spec.md#requirement-the-gate-hands-the-rows-of-an-import-jobs-file-to-integriq
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use Throwable;

/**
 * An import job's input, as records.
 *
 * The job names its input as `scope.fileId`, a Nextcloud file id. The file is
 * looked up only among the files of the person who asked for the job. Nothing
 * here logs: a refusal is a code, and the caller logs the code only.
 *
 * @spec openspec/changes/import-records-in-gate-answer/specs/data-exchange/spec.md#requirement-the-gate-hands-the-rows-of-an-import-jobs-file-to-integriq
 */
class ExchangeImportInput {

	/**
	 * The import targets whose input learniq hands over, with the kind of
	 * source row each record is.
	 *
	 * @var array<string, string>
	 */
	public const SOURCE_KINDS = [
		'lvs-results' => 'lvs-result',
		'oso' => 'oso-dossier',
		'migration-import' => 'learner-profile',
	];

	/**
	 * The largest file read, in bytes (10 MB).
	 */
	public const MAX_BYTES = 10485760;

	/**
	 * The most rows handed over for one job.
	 */
	public const MAX_RECORDS = 5000;

	public const REFUSAL_MISSING = 'import-input-missing';
	public const REFUSAL_UNREADABLE = 'import-input-unreadable';
	public const REFUSAL_TOO_LARGE = 'import-input-too-large';
	public const REFUSAL_UNPARSEABLE = 'import-input-unparseable';

	/**
	 * Constructor.
	 *
	 * @param IRootFolder $rootFolder Opens the requester's files.
	 * @param IL10N       $l10n       Translates the refusal reasons.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Read a job's file into records.
	 *
	 * A target whose input learniq does not hand over gets no records and no
	 * refusal.
	 *
	 * @param string               $target      The exchange target.
	 * @param array<string, mixed> $scope       The job's scope (`fileId`).
	 * @param string               $requestedBy The person who asked for the job.
	 *
	 * @return array{records: array<int, array{recordId: string, sourceKind: string, data: array<string, mixed>}>, refusal: string|null, reason: string}
	 *
	 * @spec openspec/changes/import-records-in-gate-answer/specs/data-exchange/spec.md#requirement-the-gate-hands-the-rows-of-an-import-jobs-file-to-integriq
	 */
	public function read(string $target, array $scope, string $requestedBy): array {
		if (isset(self::SOURCE_KINDS[$target]) === false) {
			return ['records' => [], 'refusal' => null, 'reason' => ''];
		}

		$fileId = $this->fileIdOf(scope: $scope);
		if ($fileId === null) {
			return $this->refusal(code: self::REFUSAL_MISSING);
		}

		$rows = $this->rowsOf(fileId: $fileId, requestedBy: $requestedBy);
		if (is_string($rows) === true) {
			return $this->refusal(code: $rows);
		}

		$records = [];
		foreach ($rows as $index => $row) {
			$records[] = ['recordId' => $fileId . ':' . ($index + 1), 'sourceKind' => self::SOURCE_KINDS[$target], 'data' => $row];
		}

		return ['records' => $records, 'refusal' => null, 'reason' => ''];
	}//end read()

	/**
	 * The positive file id in the scope.
	 *
	 * @param array<string, mixed> $scope The job's scope.
	 *
	 * @return int|null The id, or null when absent or not a number.
	 */
	private function fileIdOf(array $scope): ?int {
		$fileId = $scope['fileId'] ?? null;
		if (is_string($fileId) === true && ctype_digit($fileId) === true) {
			$fileId = (int)$fileId;
		}

		if (is_int($fileId) === false || $fileId <= 0) {
			return null;
		}

		return $fileId;
	}//end fileIdOf()

	/**
	 * The rows of the requester's file, or the refusal code.
	 *
	 * @param int    $fileId      The Nextcloud file id.
	 * @param string $requestedBy The requester.
	 *
	 * @return array<int, array<string, mixed>>|string The rows, or a refusal code.
	 */
	private function rowsOf(int $fileId, string $requestedBy): array|string {
		$file = $this->fileOf(fileId: $fileId, requestedBy: $requestedBy);
		if ($file === null) {
			return self::REFUSAL_UNREADABLE;
		}

		if ($file->getSize() > self::MAX_BYTES) {
			return self::REFUSAL_TOO_LARGE;
		}

		try {
			$rows = $this->parse(name: $file->getName(), content: (string)$file->getContent());
		} catch (Throwable $exception) {
			unset($exception);
			return self::REFUSAL_UNREADABLE;
		}

		if ($rows === null) {
			return self::REFUSAL_UNPARSEABLE;
		}

		if (count($rows) > self::MAX_RECORDS) {
			return self::REFUSAL_TOO_LARGE;
		}

		return $rows;
	}//end rowsOf()

	/**
	 * The file among the requester's own files.
	 *
	 * @param int    $fileId      The Nextcloud file id.
	 * @param string $requestedBy The requester.
	 *
	 * @return File|null The file, or null when there is no person or they cannot open it.
	 */
	private function fileOf(int $fileId, string $requestedBy): ?File {
		if ($requestedBy === '' || $requestedBy === 'system') {
			return null;
		}

		try {
			$node = $this->rootFolder->getUserFolder($requestedBy)->getFirstNodeById($fileId);
		} catch (Throwable $exception) {
			unset($exception);
			return null;
		}

		if ($node instanceof File === false) {
			return null;
		}

		return $node;
	}//end fileOf()

	/**
	 * Rows of a file by its extension: JSON, XML, or else CSV.
	 *
	 * @param string $name    The file name.
	 * @param string $content The content.
	 *
	 * @return array<int, array<string, mixed>>|null The rows, or null when unreadable.
	 */
	private function parse(string $name, string $content): ?array {
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
	}//end parse()

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

		if (isset($decoded['records']) === true && is_array($decoded['records']) === true) {
			$decoded = $decoded['records'];
		}

		if (array_is_list($decoded) === false) {
			$decoded = [$decoded];
		}

		$rows = array_values(array_filter($decoded, 'is_array'));
		if ($rows === []) {
			return null;
		}

		return $rows;
	}//end parseJson()

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
		$lines = preg_split('/\r\n|\r|\n/', ltrim($content, "\xEF\xBB\xBF"));
		if ($lines === false) {
			return null;
		}

		$lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
		if (count($lines) < 2) {
			return null;
		}

		$delimiter = $this->delimiterOf(header: $lines[0]);
		$header = array_map('trim', str_getcsv($lines[0], $delimiter, '"', ''));
		$rows = [];
		foreach (array_slice($lines, 1) as $line) {
			$cells = str_getcsv($line, $delimiter, '"', '');
			$row = [];
			foreach ($header as $position => $field) {
				if ($field === '') {
					continue;
				}

				$row[$field] = trim((string)($cells[$position] ?? ''));
			}

			$rows[] = $row;
		}

		return $rows;
	}//end parseCsv()

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

	/**
	 * A refusal with no records and the reason people read.
	 *
	 * @param string $code The refusal code.
	 *
	 * @return array{records: array<int, array{recordId: string, sourceKind: string, data: array<string, mixed>}>, refusal: string|null, reason: string}
	 */
	private function refusal(string $code): array {
		$reasons = [
			self::REFUSAL_MISSING => $this->l10n->t('This import names no file, so there is nothing to import.'),
			self::REFUSAL_UNREADABLE => $this->l10n->t('The file of this import cannot be opened by the person who asked for it.'),
			self::REFUSAL_TOO_LARGE => $this->l10n->t(
				'The file of this import is larger than 10 MB or has more than 5,000 rows. Split it and import each part.'
			),
			self::REFUSAL_UNPARSEABLE => $this->l10n->t('The file of this import cannot be read as CSV, JSON or XML.'),
		];
		return ['records' => [], 'refusal' => $code, 'reason' => ($reasons[$code] ?? $code)];
	}//end refusal()
}//end class
