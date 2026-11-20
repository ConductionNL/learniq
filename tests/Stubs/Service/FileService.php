<?php

/**
 * Test stub for OpenRegister's FileService (declaration only).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Mirrors the methods learniq calls, with the signatures of openregister
 * main (lib/Service/FileService.php getFiles(), saveFile(), deleteFile()).
 */
abstract class FileService {

	/**
	 * The file nodes attached to an object.
	 *
	 * @param ObjectEntity|string $object          The object or its id.
	 * @param bool|null           $sharedFilesOnly Whether to return only shared files.
	 *
	 * @return array<int, \OCP\Files\Node>
	 */
	abstract public function getFiles(ObjectEntity|string $object, ?bool $sharedFilesOnly = false): array;

	/**
	 * Write a file in an object's folder, replacing one of the same name.
	 *
	 * @param ObjectEntity                                $objectEntity The object.
	 * @param \OCA\OpenRegister\Service\File\FileUpload|string|null $upload       The upload, or the file name.
	 * @param mixed                                       $content      The file content.
	 * @param bool                                        $share        Whether to share a new file.
	 * @param array<int, string>                          $tags         Tags for the file.
	 * @param string|null                                 $fileName     The file name.
	 *
	 * @return \OCP\Files\File
	 */
	abstract public function saveFile(
		ObjectEntity $objectEntity,
		mixed $upload = null,
		mixed $content = null,
		bool $share = false,
		array $tags = [],
		?string $fileName = null,
	): \OCP\Files\File;

	/**
	 * Delete a file by node, path or id.
	 *
	 * @param \OCP\Files\Node|string|int $file   The file.
	 * @param ObjectEntity|null          $object The object it belongs to.
	 *
	 * @return bool
	 */
	abstract public function deleteFile(\OCP\Files\Node|string|int $file, ?ObjectEntity $object = null): bool;
}//end class
