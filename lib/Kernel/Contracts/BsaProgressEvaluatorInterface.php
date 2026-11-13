<?php

/**
 * Contract of BsaProgressEvaluator
 *
 * The public surface of BsaProgressEvaluator that other components call, so they depend
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
 * Contract of BsaProgressEvaluator.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BsaProgressEvaluatorInterface {

	/**
	 * Compute a learner's ectsEarned within a Programme's course scope.
	 *
	 * Fetches every Course belonging to the Programme (via
	 * Course.programmeIds), then every `passed: true` FinalGrade for the
	 * learner, and sums the ectsCredits of the courses referenced by those
	 * FinalGrades. A FinalGrade whose courseId is not in the Programme's
	 * course list (e.g. a programme-level FinalGrade with no courseId) does
	 * not contribute. A null Course.ectsCredits contributes 0, never an
	 * error.
	 *
	 * @param string $programmeId UUID of the Programme to scope courses to.
	 * @param string $learnerId Nextcloud user ID of the learner.
	 *
	 * @return array{ectsEarned: float}
	 *
	 * @spec openspec/specs/study-progress/spec.md#requirement-credit-earned-and-at-risk-detection-are-declared-calculations-not-a-timedjob
	 */
	public function evaluate(string $programmeId, string $learnerId): array;
}//end interface
