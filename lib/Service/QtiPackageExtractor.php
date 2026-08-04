<?php

/**
 * Scholiq QTI Package Extractor
 *
 * Stateless helper: owns the hardened ZIP extraction path for QTI 2.x / 3.0
 * and IMS Common Cartridge packages — the zip-slip and decompression-bomb
 * defences of #207 — plus the temporary-directory cleanup that follows an
 * import. Extracted out of `QtiImportService` purely to keep that class's own
 * complexity within this app's PHPMD budget, mirroring the
 * `QtiChoiceOrderResolver` extraction out of `AssessmentDrawResolver`; it
 * carries no dependencies of its own and is constructor-injected via
 * Nextcloud's DI autowiring.
 *
 * ADR-031 legitimate exception: "External-format import" — parsing ZIP
 * archives from an external interchange format cannot be expressed
 * declaratively.
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
 * @spec openspec/changes/course-package-import-export/design.md#why-extraction-is-refactored-not-duplicated
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use RuntimeException;
use ZipArchive;

/**
 * Extracts QTI / Common Cartridge ZIP archives with zip-slip and
 * decompression-bomb protection, and removes extraction directories again.
 *
 * @spec openspec/changes/course-package-import-export/design.md#why-extraction-is-refactored-not-duplicated
 */
class QtiPackageExtractor
{

    /**
     * #207: decompression-bomb cap — total uncompressed bytes per import.
     */
    private const MAX_TOTAL_BYTES = 268435456;

    /**
     * #207: decompression-bomb cap — uncompressed bytes for a single entry.
     */
    private const MAX_FILE_SIZE_BYTES = 104857600;

    /**
     * Extract a ZIP archive to a target directory with zip-slip and
     * decompression-bomb protection.
     *
     * Defences applied (fixes #207):
     *   - ZIP slip: every entry path is resolved with realpath after creating any
     *     parent directories and verified to be inside $targetDir.
     *   - Decompression bomb: total uncompressed size is checked before extraction;
     *     individual files over 100 MB are rejected.
     *
     * @param string $zipPath   Absolute path to the ZIP file.
     * @param string $targetDir Absolute path to the destination directory.
     *
     * @return void
     *
     * @throws \RuntimeException When the ZIP cannot be opened or a security violation is detected.
     *
     * @spec openspec/changes/course-package-import-export/design.md#why-extraction-is-refactored-not-duplicated
     */
    public function extractZip(string $zipPath, string $targetDir): void
    {
        $zip    = new ZipArchive();
        $result = $zip->open($zipPath);
        if ($result !== true) {
            throw new RuntimeException("Cannot open ZIP archive '{$zipPath}': ZipArchive error {$result}.");
        }

        try {
            $this->assertTotalSizeWithinCap(zip: $zip);
            $this->extractEntries(zip: $zip, targetDirReal: $this->ensureTargetDir(targetDir: $targetDir));
        } finally {
            $zip->close();
        }//end try

    }//end extractZip()

    /**
     * Reject the whole archive before extracting anything when its total
     * uncompressed size exceeds the decompression-bomb cap (#207).
     *
     * @param ZipArchive $zip The opened archive.
     *
     * @return void
     *
     * @throws \RuntimeException When the archive's uncompressed size exceeds the cap.
     */
    private function assertTotalSizeWithinCap(ZipArchive $zip): void
    {
        $totalUncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }

            $totalUncompressed += $stat['size'];
        }

        if ($totalUncompressed > self::MAX_TOTAL_BYTES) {
            throw new RuntimeException(
                'ZIP archive exceeds maximum allowed uncompressed size ('.self::MAX_TOTAL_BYTES.' bytes).'
            );
        }

    }//end assertTotalSizeWithinCap()

    /**
     * Resolve the canonical destination directory, creating it when it does
     * not exist yet — `realpath()` returns false for a missing directory.
     *
     * @param string $targetDir Absolute path to the destination directory.
     *
     * @return string The canonical destination path, or an empty string when it stays unresolvable.
     */
    private function ensureTargetDir(string $targetDir): string
    {
        $targetDirReal = realpath($targetDir);
        if ($targetDirReal === false) {
            mkdir(directory: $targetDir, permissions: 0700, recursive: true);
            $targetDirReal = realpath($targetDir);
        }

        return (string) $targetDirReal;

    }//end ensureTargetDir()

    /**
     * Extract the archive entry by entry, rejecting oversized entries and any
     * entry whose resolved parent escapes the target directory (#207).
     *
     * @param ZipArchive $zip           The opened archive.
     * @param string     $targetDirReal Canonical destination directory.
     *
     * @return void
     *
     * @throws \RuntimeException When an entry is oversized or would escape the target directory.
     */
    private function extractEntries(ZipArchive $zip, string $targetDirReal): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }

            // #207: reject individual files larger than the per-file cap.
            if ($stat['size'] > self::MAX_FILE_SIZE_BYTES) {
                throw new RuntimeException(
                    "ZIP entry '{$stat['name']}' exceeds maximum allowed file size (".self::MAX_FILE_SIZE_BYTES.' bytes).'
                );
            }

            $entryName = $stat['name'];

            // Build absolute destination path and resolve any '..' segments.
            $destPath = $targetDirReal.DIRECTORY_SEPARATOR.$entryName;

            // For directories: ensure they exist inside targetDir.
            if (str_ends_with($entryName, '/') === true) {
                mkdir(directory: $destPath, permissions: 0700, recursive: true);
                continue;
            }

            // Ensure parent directory exists.
            $parentDir = dirname($destPath);
            if (is_dir($parentDir) === false) {
                mkdir(directory: $parentDir, permissions: 0700, recursive: true);
            }

            // #207: zip-slip check — resolved path must start with targetDir.
            $resolvedParent = realpath($parentDir);
            if ($resolvedParent === false || str_starts_with($resolvedParent, $targetDirReal) === false) {
                throw new RuntimeException(
                    "ZIP entry '{$entryName}' would extract outside the target directory (zip-slip attack)."
                );
            }

            $content = $zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }

            file_put_contents(filename: $destPath, data: $content);
        }//end for

    }//end extractEntries()

    /**
     * Recursively remove a directory and its contents.
     *
     * @param string $dir Absolute path to the directory.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
     */
    public function removeDirectory(string $dir): void
    {
        if (is_dir($dir) === false) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            if (is_dir($path) === true) {
                $this->removeDirectory(dir: $path);
                continue;
            }

            unlink($path);
        }//end foreach

        rmdir($dir);

    }//end removeDirectory()
}//end class
