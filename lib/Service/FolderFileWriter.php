<?php

/**
 * Learniq writes of one file into a Nextcloud user folder.
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
 * @spec exclude Files plumbing, not a behavioural requirement: the folder
 *       walk and the create-or-overwrite the file writers share.
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

/**
 * Makes the folders of a path where they are missing, and creates a file or
 * overwrites the file already there, inside one user folder.
 *
 * @spec exclude Files plumbing, not a behavioural requirement.
 */
final class FolderFileWriter {

	/**
	 * Constructor.
	 *
	 * @param Folder $userFolder The user folder written into.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly Folder $userFolder,
	) {
	}//end __construct()

	/**
	 * Make every folder of the path that does not exist yet, outermost first.
	 *
	 * @param string $path Folder path relative to the user folder.
	 *
	 * @return void
	 *
	 * @spec exclude Files plumbing, not a behavioural requirement.
	 */
	public function ensureFolder(string $path): void {
		$current = '';
		foreach (array_filter(explode('/', $path)) as $segment) {
			$prefix = '';
			if ($current !== '') {
				$prefix = $current . '/';
			}

			$current = $prefix . $segment;
			$this->ensureOne(path: $current);
		}
	}//end ensureFolder()

	/**
	 * Create the file, or overwrite it when a file is there already; a folder
	 * in its place is left alone.
	 *
	 * @param string $path File path relative to the user folder.
	 * @param string $content The file content.
	 *
	 * @return void
	 *
	 * @spec exclude Files plumbing, not a behavioural requirement.
	 */
	public function put(string $path, string $content): void {
		if ($this->userFolder->nodeExists($path) === false) {
			$this->userFolder->newFile($path, $content);
			return;
		}

		$existingNode = $this->userFolder->get($path);
		if ($existingNode instanceof File) {
			$existingNode->putContent($content);
		}
	}//end put()

	/**
	 * Make one folder when it does not exist.
	 *
	 * @param string $path Folder path relative to the user folder.
	 *
	 * @return void
	 */
	private function ensureOne(string $path): void {
		try {
			$this->userFolder->get($path);
		} catch (NotFoundException) {
			$this->userFolder->newFolder($path);
		}
	}//end ensureOne()
}//end class
