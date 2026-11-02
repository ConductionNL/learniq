<?php

/**
 * Learniq course store definition.
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
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-store-publish-travels-through-the-store-planes-write-path
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CourseStore;

/**
 * Learniq's side of the course store: its store parameters and the object it
 * sends for a package.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-store-publish-travels-through-the-store-planes-write-path
 */
final class CourseStoreDefinition {

	/**
	 * Constructor.
	 *
	 * @param CourseStoreDescriptor     $descriptor     Learniq's store parameters and publish opt-in.
	 * @param CourseStoreRegistryObject $registryObject Builds the object to send.
	 */
	public function __construct(
		public readonly CourseStoreDescriptor $descriptor,
		public readonly CourseStoreRegistryObject $registryObject,
	) {
	}//end __construct()
}//end class
