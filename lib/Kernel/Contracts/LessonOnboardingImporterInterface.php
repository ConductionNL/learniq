<?php

/**
 * Contract of LessonOnboardingImporter
 *
 * The public surface of LessonOnboardingImporter that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

use OCA\Learniq\Service\LessonOnboarding\OnboardingImportException;

/**
 * Contract of LessonOnboardingImporter.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LessonOnboardingImporterInterface {

	/**
	 * Import one detected row into a course.
	 *
	 * @param string $userId The confirming teacher.
	 * @param string $rowId The LessonOnboardingFile uuid.
	 * @param string $courseId The target Course uuid.
	 *
	 * @return array{lessonId: string, lessonName: string, courseId: string, blocks: int, materials: int, notes: list<string>}
	 *
	 * @throws OnboardingImportException When the row, the course or the file refuses the import.
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-lesson-plan-with-two-headings-and-an-image
	 */
	public function import(string $userId, string $rowId, string $courseId): array;
}//end interface
