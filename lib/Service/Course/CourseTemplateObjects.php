<?php

/**
 * Learniq course template objects.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Course
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
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Course;

use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Where a course template reads, creates and deletes its OpenRegister objects.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
 */
final class CourseTemplateObjects {

	/**
	 * Constructor.
	 *
	 * @param ObjectService             $objectService OpenRegister reads and deletes.
	 * @param CoursePackageObjectWriter $writer        OpenRegister creates.
	 */
	public function __construct(
		public readonly ObjectService $objectService,
		public readonly CoursePackageObjectWriter $writer,
	) {
	}//end __construct()
}//end class
