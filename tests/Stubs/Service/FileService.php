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
use OCA\OpenRegister\Service\File\FileUpload;
use OCP\Files\File;

/**
 * Mirrors the methods learniq calls, with the signatures of openregister
 * main (lib/Service/FileService.php getFiles() and saveFile()).
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
	 * Save a file to an object's folder, replacing one of the same name.
	 *
	 * @param ObjectEntity           $objectEntity The object.
	 * @param FileUpload|string|null $upload       The file, or its name.
	 * @param mixed                  $content      The content, beside a name.
	 * @param bool                   $share        Whether to share a new file.
	 * @param array                  $tags         Tags for the file.
	 * @param string|null            $fileName     The file name.
	 *
	 * @return File
	 */
	abstract public function saveFile(
		ObjectEntity $objectEntity,
		FileUpload|string|null $upload = null,
		mixed $content = null,
		bool $share = false,
		array $tags = [],
		?string $fileName = null,
	): File;
}//end class
