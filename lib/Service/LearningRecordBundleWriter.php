<?php

/**
 * Learniq Learning Record Bundle Writer
 *
 * Owns the nc:files side of a learner's signed learning-record export: JSON
 * encoding, the `Learniq/{tenant}/learning-record-exports` destination
 * convention `CoursePackageImportService::writeBytesToFiles()` established,
 * folder creation, and the create-or-overwrite of the bundle file itself.
 * Extracted out of `LearningRecordExportService` so that class stays a
 * lifecycle guard over composition/signing rather than also being a
 * filesystem writer — and so both classes stay within this app's PHPMD
 * complexity and coupling budget, mirroring the `QtiChoiceOrderResolver`
 * extraction out of `AssessmentDrawResolver`.
 *
 * Fails soft in exactly the shape its caller expects: any failure returns
 * null, which `LearningRecordExportService::check()` turns into an
 * `errorMessage` that blocks the `generate` transition. This app never stores
 * file bytes anywhere other than nc:files.
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
 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-learner-initiated-export-produces-a-signed-dual-shaped-bundle
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Writes a signed learning-record export bundle into the owner's nc:files home.
 *
 * @spec openspec/changes/archive/2026-07-16-portable-learning-record/tasks.md#task-2-3
 */
class LearningRecordBundleWriter {
	/**
	 * Constructor.
	 *
	 * @param IRootFolder $rootFolder NC root folder for writing the signed bundle.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the signed bundle JSON to the owner's nc:files home, mirroring
	 * `CoursePackageImportService::writeBytesToFiles()`'s destination
	 * convention (`Learniq/{tenant}/...`).
	 *
	 * @param array<string,mixed> $bundle The signed bundle (bundle itself, not the JWS).
	 * @param string $ownerUid Nextcloud user id who will own the file.
	 * @param string $tenantId Tenant UUID, used to namespace the destination folder.
	 * @param string $exportId LearningRecordExport UUID, used as the filename.
	 *
	 * @return string|null The nc:files path, or null on failure.
	 *
	 * @spec openspec/changes/archive/2026-07-16-portable-learning-record/tasks.md#task-2-3
	 */
	public function write(array $bundle, string $ownerUid, string $tenantId, string $exportId): ?string {
		if ($ownerUid === '') {
			return null;
		}

		$encoded = json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
		if ($encoded === false) {
			return null;
		}

		try {
			$tenantSegment = 'default';
			if ($tenantId !== '') {
				$tenantSegment = $tenantId;
			}

			$ncBaseDir = 'Learniq/' . $tenantSegment . '/learning-record-exports';
			$ncPath = $ncBaseDir . '/' . $exportId . '.json';

			$files = new FolderFileWriter(userFolder: $this->rootFolder->getUserFolder($ownerUid));
			$files->ensureFolder(path: $ncBaseDir);
			$files->put(path: $ncPath, content: $encoded);

			return '/' . $ncPath;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[LearningRecordBundleWriter] Could not write signed bundle for export {id}: {msg}',
				['id' => $exportId, 'msg' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end write()

}//end class
