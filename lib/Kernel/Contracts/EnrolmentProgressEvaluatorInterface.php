<?php

/**
 * Contract of EnrolmentProgressEvaluator
 *
 * The public surface of EnrolmentProgressEvaluator that other components call, so they depend
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

/**
 * Contract of EnrolmentProgressEvaluator.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EnrolmentProgressEvaluatorInterface {

	/**
	 * Evaluate progressPercent for a learner + course.
	 *
	 * Counts the lessons the learner completed and the course's published
	 * Lessons, then computes a null-safe percentage: 0 (never a
	 * divide-by-zero error) when either count is 0.
	 *
	 * Given the Enrolment, only that enrolment's completions count
	 * (learniq#945): a row whose enrolmentId names it, or a row with no
	 * enrolmentId completed after the enrolment was created. A row tied to
	 * an earlier enrolment never counts, so a retake starts at zero. Each
	 * lesson counts once. Without an Enrolment every row of the learner for
	 * the course counts, as before.
	 *
	 * @param string $learnerId NC user ID of the learner.
	 * @param string $courseId UUID of the Course.
	 * @param array<string, mixed> $enrolment The Enrolment being rolled up, or [].
	 *
	 * @return array{progressPercent: int, completedLessonCount: int, totalPublishedLessonCount: int}
	 *
	 * @spec openspec/specs/enrolment/spec.md#scenario-progress-percentage-is-null-safe-before-any-lesson-completes
	 * @spec openspec/specs/progress-tracking/spec.md#requirement-a-lesson-completion-belongs-to-one-enrolment
	 */
	public function evaluate(string $learnerId, string $courseId, array $enrolment = []): array;
}//end interface
