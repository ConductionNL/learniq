<?php

/**
 * Contract of PointEngagementEvaluator
 *
 * The public surface of PointEngagementEvaluator that other components call, so they depend
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
 * Contract of PointEngagementEvaluator.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PointEngagementEvaluatorInterface {

	/**
	 * Compute a learner's totalPoints, levelId, and streak days.
	 *
	 * @param string $learnerId Nextcloud user ID of the learner.
	 * @param string $tenantId Tenant identifier the EngagementLevel rows are scoped to.
	 *
	 * @return array{totalPoints: float, levelId: ?string, currentStreakDays: int, longestStreakDays: int, lastActivityDate: ?string}
	 *
	 * @spec openspec/specs/engagement/spec.md#requirement-learner-totals-level-and-streak-are-computed-by-a-php-evaluator-not-a-sum-aggregation
	 */
	public function evaluate(string $learnerId, string $tenantId): array;
}//end interface
