<?php

/**
 * Learniq lesson onboarding writers.
 *
 * @category Service
 * @package  OCA\Learniq\Service\LessonOnboarding
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
 * @spec openspec/specs/course-management/spec.md#requirement-a-confirmed-powerpoint-file-becomes-one-lesson-draft-or-waits-when-the-reader-is-missing
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\LessonOnboarding;

use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter;

/**
 * What an import writes with: the object writer, the file writer and the transition engine that marks the row imported.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
 */
final class LessonOnboardingWriters {

	/**
	 * Constructor.
	 *
	 * @param CoursePackageObjectWriter $objectWriter     Creates the lesson and its materials.
	 * @param CoursePackageFileWriter   $fileWriter       Writes image bytes into the teacher's files.
	 * @param TransitionEngine          $transitionEngine Moves the row to imported.
	 */
	public function __construct(
		public readonly CoursePackageObjectWriter $objectWriter,
		public readonly CoursePackageFileWriter $fileWriter,
		public readonly TransitionEngine $transitionEngine,
	) {
	}//end __construct()
}//end class
