<?php

/**
 * Contract of AssessmentAttemptLimits
 *
 * The public surface of AssessmentAttemptLimits that other components call, so they depend
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
 * Contract of AssessmentAttemptLimits.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AssessmentAttemptLimitsInterface {

	/**
	 * Whether an update changes the learner's answers on their own attempt in
	 * progress after its deadline plus the grace. Scores the server adds to
	 * unchanged answers (the submit save) are not a change. A test that cannot
	 * be read counts as past its deadline, as on the portal.
	 *
	 * @param array<string, mixed> $old The stored attempt.
	 * @param array<string, mixed> $new The attempt as it would be saved.
	 * @param string $uid The acting user.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/assessment/spec.md#scenario-answers-after-the-deadline-are-not-saved
	 */
	public function answersLate(array $old, array $new, string $uid): bool;

	/**
	 * Whether a new attempt may start, and what the server sets on it.
	 *
	 * @param array<string, mixed> $assessment The raw Assessment row.
	 * @param array<string, mixed> $payload The AssessmentResult being created.
	 *
	 * @return array{block: array{reason: string, message: string}|null, stamp: array<string, mixed>}
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-the-in-app-test-screen-enforces-attempts-and-time-on-the-server
	 */
	public function start(array $assessment, array $payload): array;
}//end interface
