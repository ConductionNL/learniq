<?php

/**
 * Contract of EngagementScoreEvaluator
 *
 * The public surface of EngagementScoreEvaluator that other components call, so they depend
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
 * Contract of EngagementScoreEvaluator.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EngagementScoreEvaluatorInterface {

	/**
	 * Evaluate a learner's engagement signals for a course.
	 *
	 * @param string $learnerId NC user ID of the learner.
	 * @param string $courseId UUID of the Course.
	 * @param string|null $previousActivityAt The EngagementScore's prior
	 *                                        lastActivityAt (ISO-8601),
	 *                                        if any — used only for
	 *                                        the recency-gap decay
	 *                                        factor.
	 *
	 * @return array{timeOnTaskMinutes: float, lastActivityAt: string|null, score: int}
	 *
	 * @spec openspec/specs/student-analytics/spec.md#scenario-time-on-task-accumulates-across-statements
	 */
	public function evaluate(string $learnerId, string $courseId, ?string $previousActivityAt = null): array;
}//end interface
