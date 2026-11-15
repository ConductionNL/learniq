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
 * @spec openspec/specs/data-exchange/spec.md#requirement-the-gate-hands-the-rows-of-an-import-jobs-file-to-integriq
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-the-gate-hands-the-rows-of-an-import-jobs-file-to-integriq
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
	 * @spec openspec/specs/data-exchange/spec.md#requirement-the-gate-hands-the-rows-of-an-import-jobs-file-to-integriq
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
		$fileId = self::wholeNumber(value: ($scope['fileId'] ?? null));
		if ($fileId === null || $fileId <= 0) {
			return null;
		}

		return $fileId;
	}//end fileIdOf()

	/**
	 * An integer, or a string of digits as one; null for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int|null
	 */
	private static function wholeNumber(mixed $value): ?int {
		if (is_int($value) === true) {
			return $value;
		}

		if (is_string($value) === true && ctype_digit($value) === true) {
			return (int)$value;
		}

		return null;
	}//end wholeNumber()

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

		return $this->rowsIn(file: $file);
	}//end rowsOf()

	/**
	 * The rows of a file within the size bound, or the refusal code.
	 *
	 * @param File $file The file.
	 *
	 * @return array<int, array<string, mixed>>|string The rows, or a refusal code.
	 */
	private function rowsIn(File $file): array|string {
		try {
			$rows = (new ImportFileRows())->rowsOf(name: $file->getName(), content: (string)$file->getContent());
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
	}//end rowsIn()

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

		$node = $this->nodeOf(fileId: $fileId, requestedBy: $requestedBy);
		if ($node instanceof File === false) {
			return null;
		}

		return $node;
	}//end fileOf()

	/**
	 * The node with this id among the requester's files, or null when their files cannot be opened.
	 *
	 * @param int    $fileId      The Nextcloud file id.
	 * @param string $requestedBy The requester.
	 *
	 * @return mixed
	 */
	private function nodeOf(int $fileId, string $requestedBy): mixed {
		try {
			return $this->rootFolder->getUserFolder($requestedBy)->getFirstNodeById($fileId);
		} catch (Throwable $exception) {
			unset($exception);
			return null;
		}
	}//end nodeOf()

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
