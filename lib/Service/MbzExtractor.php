<?php

/**
 * Scholiq Moodle Backup (.mbz) Extractor
 *
 * Extracts a Moodle backup archive (`.mbz` — a gzipped tar, NOT a ZIP) to a
 * target directory. Moodle's own `.mbz` format cannot be opened by
 * `ZipArchive`; `PharData` is the PHP primitive for gzipped-tar extraction.
 *
 * Legitimate PHP per ADR-031 §"External-format import": parsing a gzipped
 * tar from an external interchange format cannot be expressed declaratively.
 *
 * Ports (does not re-invent) the zip-slip / decompression-bomb / per-file-
 * size guards `QtiImportService::extractZip()` already hardened for the ZIP
 * path (fixes for #207) to the tar-extraction path, per design.md "Security /
 * Privacy Posture".
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
 * @spec openspec/changes/course-package-import-export/tasks.md#31-add-ocascholiqservicembzextractor-spdx-adr-031-external-format-import-exception-extracts-a-gzipped-tar-mbz-archive-via-phardata-porting-qtiimportserviceextractzips-zip-slip--decompression-bomb--per-file-size-guards-to-the-tar-extraction-path-fixes-for-207-ported-not-re-invented
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use PharData;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Extracts Moodle `.mbz` (gzipped tar) archives with the same hardening
 * `QtiImportService` applies to ZIP packages.
 */
class MbzExtractor
{

    /**
     * #207-equivalent decompression-bomb cap — 256 MB total uncompressed per import.
     */
    private const MAX_TOTAL_BYTES = 256 * 1024 * 1024;

    /**
     * #207-equivalent per-file cap.
     */
    private const MAX_FILE_SIZE_BYTES = 100 * 1024 * 1024;

    /**
     * Extract a Moodle `.mbz` archive to a target directory.
     *
     * @param string $mbzPath   Absolute path to the `.mbz` file.
     * @param string $targetDir Absolute path to the destination directory (created if absent).
     *
     * @return void
     *
     * @throws \RuntimeException When the archive cannot be opened, is not a valid gzipped tar,
     *                            or a security violation is detected (oversize / path traversal).
     *
     * @spec openspec/changes/course-package-import-export/design.md#security--privacy-posture
     */
    public function extract(string $mbzPath, string $targetDir): void
    {
        if (is_dir($targetDir) === false) {
            mkdir(directory: $targetDir, permissions: 0700, recursive: true);
        }

        $targetDirReal = realpath($targetDir);
        if ($targetDirReal === false) {
            throw new RuntimeException("Cannot resolve target directory '{$targetDir}'.");
        }

        // PharData needs a recognised extension to auto-detect gzip compression;
        // work on a private copy outside $targetDir so our scratch files never
        // pollute the extracted package the parser will walk.
        $workDir = sys_get_temp_dir().'/scholiq_mbz_work_'.bin2hex(random_bytes(8));
        mkdir(directory: $workDir, permissions: 0700, recursive: true);
        $tarGzPath = $workDir.'/package.tar.gz';

        try {
            $tar = $this->openDecompressedTar(mbzPath: $mbzPath, tarGzPath: $tarGzPath);

            // Pre-flight pass: total uncompressed size + per-file cap, before writing anything.
            $this->assertWithinSizeLimits(tar: $tar);

            // Extraction pass: zip-slip (tar-slip) protection per entry, mirroring extractZip().
            $this->extractEntries(tar: $tar, targetDirReal: $targetDirReal);
        } finally {
            $this->removeDirectory(dir: $workDir);
        }//end try
    }//end extract()

    /**
     * Copy the `.mbz` to a private scratch path and open it as a decompressed tar.
     *
     * PharData needs a recognised extension to auto-detect gzip compression,
     * which is why the caller hands over a `.tar.gz` scratch path rather than
     * the original file.
     *
     * @param string $mbzPath   Absolute path to the uploaded Moodle backup.
     * @param string $tarGzPath Scratch path to copy it to.
     *
     * @return PharData The decompressed archive.
     *
     * @throws RuntimeException When the archive cannot be read, opened or decompressed.
     *
     * @spec openspec/changes/course-package-import-export/design.md#security--privacy-posture
     */
    private function openDecompressedTar(string $mbzPath, string $tarGzPath): PharData
    {
        if (copy($mbzPath, $tarGzPath) === false) {
            throw new RuntimeException("Cannot read Moodle backup archive '{$mbzPath}'.");
        }

        try {
            $pharGz = new PharData($tarGzPath);
            $tar    = $pharGz->decompress();
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Cannot open Moodle backup archive '{$mbzPath}': not a valid gzipped tar (".$e->getMessage().').'
            );
        }

        if (($tar instanceof PharData) === false) {
            throw new RuntimeException("Cannot decompress Moodle backup archive '{$mbzPath}'.");
        }

        return $tar;

    }//end openDecompressedTar()

    /**
     * Refuse a decompression bomb BEFORE anything is written to disk.
     *
     * Both caps matter: the per-entry cap stops one huge member, and the total
     * cap stops many small ones adding up. This runs as its own pass precisely
     * so that no bytes have been written when it throws.
     *
     * @param PharData $tar The decompressed archive.
     *
     * @return void
     *
     * @throws RuntimeException When any entry, or the archive as a whole, exceeds its cap.
     *
     * @spec openspec/changes/course-package-import-export/design.md#security--privacy-posture
     */
    private function assertWithinSizeLimits(PharData $tar): void
    {
        $totalUncompressed = 0;
        $iterator          = new RecursiveIteratorIterator($tar, RecursiveIteratorIterator::LEAVES_ONLY);

        foreach ($iterator as $fileInfo) {
            $size = $fileInfo->getSize();
            if ($size > self::MAX_FILE_SIZE_BYTES) {
                throw new RuntimeException(
                    "Moodle backup entry '{$iterator->getSubPathname()}' exceeds maximum allowed file size (".self::MAX_FILE_SIZE_BYTES.' bytes).'
                );
            }

            $totalUncompressed += $size;
        }

        if ($totalUncompressed > self::MAX_TOTAL_BYTES) {
            throw new RuntimeException(
                'Moodle backup archive exceeds maximum allowed uncompressed size ('.self::MAX_TOTAL_BYTES.' bytes).'
            );
        }

    }//end assertWithinSizeLimits()

    /**
     * Write every archive entry into the target directory, refusing any entry
     * that would land outside it (tar-slip), mirroring `extractZip()`.
     *
     * The containment check is made on the REALPATH of the entry's parent
     * directory after it has been created, so a `..` segment or a symlinked
     * parent cannot escape the target.
     *
     * @param PharData $tar           The decompressed archive.
     * @param string   $targetDirReal The resolved absolute target directory.
     *
     * @return void
     *
     * @throws RuntimeException When an entry would extract outside the target directory.
     *
     * @spec openspec/changes/course-package-import-export/design.md#security--privacy-posture
     */
    private function extractEntries(PharData $tar, string $targetDirReal): void
    {
        $iterator = new RecursiveIteratorIterator($tar, RecursiveIteratorIterator::LEAVES_ONLY);

        foreach ($iterator as $fileInfo) {
            $relativePath = $iterator->getSubPathname();
            $destPath     = $targetDirReal.DIRECTORY_SEPARATOR.$relativePath;

            $parentDir = dirname($destPath);
            if (is_dir($parentDir) === false) {
                mkdir(directory: $parentDir, permissions: 0700, recursive: true);
            }

            $resolvedParent = realpath($parentDir);
            if ($resolvedParent === false || str_starts_with($resolvedParent, $targetDirReal) === false) {
                throw new RuntimeException(
                    "Moodle backup entry '{$relativePath}' would extract outside the target directory (tar-slip attack)."
                );
            }

            file_put_contents(filename: $destPath, data: $fileInfo->getContent());
        }//end foreach

    }//end extractEntries()

    /**
     * Recursively remove a directory and its contents.
     *
     * @param string $dir Absolute path to the directory.
     *
     * @return void
     *
     * @spec openspec/changes/course-package-import-export/design.md#security--privacy-posture
     */
    private function removeDirectory(string $dir): void
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
        }

        rmdir($dir);
    }//end removeDirectory()
}//end class
