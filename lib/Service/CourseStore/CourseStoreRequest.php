<?php

/**
 * Learniq Course Store Request
 *
 * One install of a shared course from the course store: the registry object,
 * its package read from the object, and the Nextcloud user installing it.
 *
 * @category Service
 * @package  OCA\Learniq\Service\CourseStore
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
 * @spec openspec/specs/course-management/spec.md#requirement-installing-a-shared-course-creates-an-independent-copy-that-keeps-the-credit
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CourseStore;

/**
 * The registry object, its package and the installer of one course store install.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-installing-a-shared-course-creates-an-independent-copy-that-keeps-the-credit
 */
final class CourseStoreRequest {

	/**
	 * The object's `package`: decoded when it is JSON text, as stored otherwise.
	 *
	 * @var mixed
	 */
	public readonly mixed $package;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $item   The full registry object from the store plane.
	 * @param string               $userId Nextcloud user id of the installer.
	 */
	public function __construct(
		public readonly array $item,
		public readonly string $userId,
	) {
		$package = ($item['package'] ?? null);
		if (is_string($package) === true) {
			$package = json_decode($package, true);
		}

		$this->package = $package;
	}//end __construct()
}//end class
