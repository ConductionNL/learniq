<?php

/**
 * Learniq ZIP archive built in memory from named file contents.
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
 * @spec exclude Packaging plumbing, not a behavioural requirement: the ZIP
 *       step the export builders share.
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use ZipArchive;

/**
 * Packs named file contents into a ZIP archive through a temporary file and
 * returns the archive's bytes; an empty string when the archive cannot be
 * made.
 *
 * @spec exclude Packaging plumbing, not a behavioural requirement.
 */
final class InMemoryZip {

	/**
	 * Constructor.
	 *
	 * @param string $tempPrefix Prefix of the temporary file's name.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $tempPrefix,
	) {
	}//end __construct()

	/**
	 * The archive bytes for the files, or '' when it cannot be made.
	 *
	 * @param array<string,string> $files Archive-relative path to content.
	 *
	 * @return string
	 *
	 * @spec exclude Packaging plumbing, not a behavioural requirement.
	 */
	public function build(array $files): string {
		$tmpFile = tempnam(sys_get_temp_dir(), $this->tempPrefix);
		if ($tmpFile === false) {
			return '';
		}

		$zip = new ZipArchive();
		if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
			unlink($tmpFile);
			return '';
		}

		self::addAll(zip: $zip, files: $files);
		$zip->close();

		$zipContent = (string)file_get_contents($tmpFile);
		unlink($tmpFile);

		return $zipContent;
	}//end build()

	/**
	 * Add every file to the open archive.
	 *
	 * @param ZipArchive $zip The open archive.
	 * @param array<string,string> $files Archive-relative path to content.
	 *
	 * @return void
	 */
	private static function addAll(ZipArchive $zip, array $files): void {
		foreach ($files as $filename => $content) {
			$zip->addFromString($filename, $content);
		}
	}//end addAll()
}//end class
