<?php

/**
 * Learniq PortalFileEntries
 *
 * The files attached to one learniq record, as entries of portaliq's documents
 * provider contract: an id, a title, the day, the type and size, whether it is
 * new, and where the bytes live (for portaliq's server only; it never sends
 * that part to the browser).
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/guardian-reports-and-documents/specs/portal-contribution/spec.md#requirement-a-guardian-reads-her-childrens-published-reports-and-the-schools-papers-on-one-page
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a record's attached files into documents entries.
 *
 * @spec openspec/changes/guardian-reports-and-documents/specs/portal-contribution/spec.md#requirement-a-guardian-reads-her-childrens-published-reports-and-the-schools-papers-on-one-page
 */
class PortalFileEntries {

	private const REGISTER = 'learniq';

	/**
	 * The field that names a paper, per schema; the file name when it is empty.
	 */
	private const TITLE_FIELDS = [
		'report-card' => 'periodName',
		'school-document' => 'title',
	];

	/**
	 * How long a paper reads "Nieuw": fourteen days after its file was saved.
	 */
	private const NEW_FOR_SECONDS = 1209600;

	/**
	 * Constructor.
	 *
	 * @param FileService     $fileService OpenRegister files (system context: portaliq proved the row first).
	 * @param LoggerInterface $logger      Logs a listing that failed.
	 */
	public function __construct(
		private readonly FileService $fileService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The entries of every file attached to one record.
	 *
	 * @param string               $schema The record's schema slug.
	 * @param array<string, mixed> $row    The record as read.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/guardian-reports-and-documents/specs/portal-contribution/spec.md#requirement-a-guardian-reads-her-childrens-published-reports-and-the-schools-papers-on-one-page
	 */
	public function forRecord(string $schema, array $row): array {
		$paper = [
			'schema' => $schema,
			'id' => (string)($row['id'] ?? ''),
			'title' => trim((string)($row[self::TITLE_FIELDS[$schema] ?? ''] ?? '')),
		];

		return array_map(
			fn (File $file): array => $this->entry(file: $file, paper: $paper),
			$this->filesOf(id: $paper['id'])
		);
	}//end forRecord()

	/**
	 * One file as an entry.
	 *
	 * @param File                  $file  The attached file.
	 * @param array<string, string> $paper The record's schema, id and title.
	 *
	 * @return array<string, mixed>
	 */
	private function entry(File $file, array $paper): array {
		$fileId = (string)$file->getId();
		$saved  = (int)$file->getMTime();

		return [
			'id' => $fileId,
			'title' => $this->titleOf(file: $file, title: $paper['title']),
			'date' => date('c', $saved),
			'mimeType' => $file->getMimetype(),
			'size' => (int)$file->getSize(),
			'isNew' => $saved >= (time() - self::NEW_FOR_SECONDS),
			'file' => ['register' => self::REGISTER, 'schema' => $paper['schema'], 'id' => $paper['id'], 'fileId' => $fileId],
		];
	}//end entry()

	/**
	 * The record's name for the paper; a nameless one reads as its file name.
	 *
	 * @param File   $file  The attached file.
	 * @param string $title The record's name for it, or ''.
	 *
	 * @return string
	 */
	private function titleOf(File $file, string $title): string {
		if ($title !== '') {
			return $title;
		}

		return pathinfo($file->getName(), PATHINFO_FILENAME);
	}//end titleOf()

	/**
	 * The files attached to a record; none when it has no id or the listing fails.
	 *
	 * @param string $id The record's uuid.
	 *
	 * @return array<int, File>
	 */
	private function filesOf(string $id): array {
		if ($id === '') {
			return [];
		}

		try {
			$nodes = $this->fileService->getFiles(object: $id);
		} catch (Throwable $e) {
			$this->logger->warning('Learniq: the files of a portal paper could not be listed', ['reason' => $e->getMessage()]);
			return [];
		}

		return array_values(array_filter($nodes, static fn ($node): bool => $node instanceof File));
	}//end filesOf()
}//end class
