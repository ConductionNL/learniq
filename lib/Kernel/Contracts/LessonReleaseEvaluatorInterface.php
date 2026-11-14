<?php

/**
 * Contract of LessonReleaseEvaluator
 *
 * The public surface of LessonReleaseEvaluator that other components call, so they depend
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
 * Contract of LessonReleaseEvaluator.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LessonReleaseEvaluatorInterface {

	/**
	 * Evaluate release-gating for one Lesson or Assessment, for one learner.
	 *
	 * Checks, in order: (1) the item's own absolute availability window
	 * (Assessment's materialised `isAvailable` only — Lesson has none); (2)
	 * `availableAfterDays` drip delay, relative to the learner's OWN
	 * `Enrolment.created`; (3) each `releaseConditions` entry, AND-combined.
	 * Returns the first unmet reason found, or `available: true`.
	 *
	 * @param array<string, mixed> $item The Lesson or Assessment row (as returned by ObjectService).
	 * @param string $itemSchema 'lesson' or 'exam'.
	 * @param string $learnerId NC user ID of the requesting learner.
	 * @param array<string, mixed> $enrolment The learner's own Enrolment row for the item's course, or `[]`
	 *                                        when none is resolvable (e.g. an admin/teacher previewing
	 *                                        without holding a personal Enrolment) — the drip gate is
	 *                                        then skipped (no per-learner reference date exists to gate
	 *                                        against).
	 *
	 * @return array{available: bool, reason: string|null, availableAt: string|null}
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-lesson-is-unavailable-until-its-prerequisite-lesson-is-completed
	 * @spec openspec/specs/course-management/spec.md#scenario-a-lesson-is-locked-until-n-days-after-the-learners-own-enrolment-date
	 */
	public function evaluate(array $item, string $itemSchema, string $learnerId, array $enrolment): array;
}//end interface
