<?php

/**
 * Contract of SubmissionWindowGuard
 *
 * The public surface of SubmissionWindowGuard that other components call, so they depend
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

use OCA\OpenRegister\Lifecycle\GuardResult;

/**
 * Contract of SubmissionWindowGuard.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SubmissionWindowGuardInterface {

	/**
	 * The late hand-in transition.
	 */
	public const LATE_ACTION = 'submitLate';

	/**
	 * Refusal: After the deadline, on an assignment that takes no late work.
	 */
	public const DENY_LATE_NOT_ACCEPTED = 'The deadline has passed and this assignment does not accept late work.';

	/**
	 * Authorise or deny a hand-in (LifecycleGuardInterface).
	 *
	 * @param array<string,mixed> $object The Submission at its target state.
	 * @param string              $action `submit` or `submitLate`.
	 * @param string              $userId The uid of the caller, '' for a system call.
	 *
	 * @return GuardResult Allow, or deny with the reason shown to the caller.
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-a-learner-hands-in-their-own-work-and-the-teacher-marks-it
	 * @spec openspec/specs/assignments/spec.md#requirement-a-requested-resubmission-has-its-own-deadline
	 */
	public function check(array $object, string $action, string $userId): GuardResult;
}//end interface
