<?php

/**
 * Contract of PortalAttemptService
 *
 * The public surface of PortalAttemptService that other components call, so they depend
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

use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalOutcome;

/**
 * Contract of PortalAttemptService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalAttemptServiceInterface {

	/**
	 * Save the answer to one question.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param string $attemptId The attempt.
	 * @param string $itemId The question.
	 * @param mixed $response The answer.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function answer(PortalLearner $learner, string $attemptId, string $itemId, mixed $response): PortalOutcome;

	/**
	 * The tests the pupil may start or continue.
	 *
	 * @param PortalLearner $learner The pupil.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function available(PortalLearner $learner): PortalOutcome;

	/**
	 * Start a new attempt, or resume the one in progress.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param string $taskId The test's uuid.
	 * @param mixed $accessCode The code the pupil typed, if any.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function start(PortalLearner $learner, string $taskId, mixed $accessCode): PortalOutcome;

	/**
	 * Hand the attempt in.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param string $attemptId The attempt.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function submit(PortalLearner $learner, string $attemptId): PortalOutcome;
}//end interface
