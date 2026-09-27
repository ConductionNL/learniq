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
 * Mirrors the one method learniq calls, with the signature of openregister
 * development d611a366 (lib/Service/FileService.php getFiles()).
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
}//end class
