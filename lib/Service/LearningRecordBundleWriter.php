<?php

/**
 * Scholiq Learning Record Bundle Writer
 *
 * Owns the nc:files side of a learner's signed learning-record export: JSON
 * encoding, the `Scholiq/{tenant}/learning-record-exports` destination
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
 * @package  OCA\Scholiq\Service
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
 * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#requirement-a-learner-initiated-export-produces-a-signed-dual-shaped-bundle
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Writes a signed learning-record export bundle into the owner's nc:files home.
 *
 * @spec openspec/changes/portable-learning-record/tasks.md#task-2-3
 */
class LearningRecordBundleWriter
{
    /**
     * Constructor.
     *
     * @param IRootFolder     $rootFolder NC root folder for writing the signed bundle.
     * @param LoggerInterface $logger     PSR logger.
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
     * convention (`Scholiq/{tenant}/...`).
     *
     * @param array<string,mixed> $bundle   The signed bundle (bundle itself, not the JWS).
     * @param string              $ownerUid Nextcloud user id who will own the file.
     * @param string              $tenantId Tenant UUID, used to namespace the destination folder.
     * @param string              $exportId LearningRecordExport UUID, used as the filename.
     *
     * @return string|null The nc:files path, or null on failure.
     *
     * @spec openspec/changes/portable-learning-record/tasks.md#task-2-3
     */
    public function write(array $bundle, string $ownerUid, string $tenantId, string $exportId): ?string
    {
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

            $ncBaseDir = 'Scholiq/'.$tenantSegment.'/learning-record-exports';
            $ncPath    = $ncBaseDir.'/'.$exportId.'.json';

            $userFolder = $this->rootFolder->getUserFolder($ownerUid);
            $this->ensureFolder(userFolder: $userFolder, path: $ncBaseDir);
            $this->putContents(userFolder: $userFolder, path: $ncPath, content: $encoded);

            return '/'.$ncPath;
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[LearningRecordBundleWriter] Could not write signed bundle for export {id}: {msg}',
                ['id' => $exportId, 'msg' => $e->getMessage()]
            );
            return null;
        }//end try

    }//end write()

    /**
     * Create the bundle file, or overwrite it when a previous generation of the
     * same export already wrote one.
     *
     * @param Folder $userFolder The owner's user folder.
     * @param string $path       Relative nc:files path of the bundle file.
     * @param string $content    Encoded bundle JSON.
     *
     * @return void
     */
    private function putContents(Folder $userFolder, string $path, string $content): void
    {
        if ($userFolder->nodeExists($path) === false) {
            $userFolder->newFile($path, $content);
            return;
        }

        $existingNode = $userFolder->get($path);
        if ($existingNode instanceof File) {
            $existingNode->putContent($content);
        }

    }//end putContents()

    /**
     * Ensure a nested nc:files folder path exists under the given folder.
     *
     * @param Folder $userFolder The root user folder.
     * @param string $path       Slash-separated relative path to ensure.
     *
     * @return void
     */
    private function ensureFolder(Folder $userFolder, string $path): void
    {
        $segments = array_filter(explode('/', $path));
        $current  = '';
        foreach ($segments as $segment) {
            $prefix = '';
            if ($current !== '') {
                $prefix = $current.'/';
            }

            $current = $prefix.$segment;

            try {
                $userFolder->get($current);
            } catch (NotFoundException $e) {
                $userFolder->newFolder($current);
            }
        }

    }//end ensureFolder()
}//end class
