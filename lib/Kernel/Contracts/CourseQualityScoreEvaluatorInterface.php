<?php

/**
 * Contract of CourseQualityScoreEvaluator
 *
 * The public surface of CourseQualityScoreEvaluator that other components call, so they depend
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
 * Contract of CourseQualityScoreEvaluator.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CourseQualityScoreEvaluatorInterface {

	/**
	 * Evaluate the quality score for a (courseId, teacherId, academicYear, period) scope.
	 *
	 * @param string $courseId Course UUID.
	 * @param string|null $teacherId Teacher NC user id, or null for a course-level-only scope.
	 * @param string $academicYear Academic year, e.g. '2025-2026'.
	 * @param string $period Period label, e.g. 'Q1'.
	 *
	 * @return array{responseCount: int, invitationCount: int, averageOverallScore: float|null,
	 *               responseRate: float, lastRecomputedAt: string}
	 *
	 * @spec openspec/specs/course-evaluation/spec.md#scenario-a-new-response-recomputes-the-course-s-quality-score
	 * @spec openspec/specs/course-evaluation/spec.md#scenario-response-rate-reflects-invitations-not-just-responses
	 */
	public function evaluate(string $courseId, ?string $teacherId, string $academicYear, string $period): array;
}//end interface
