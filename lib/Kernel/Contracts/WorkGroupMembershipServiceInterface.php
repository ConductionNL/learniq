<?php

/**
 * Contract of WorkGroupMembershipService
 *
 * The public surface of WorkGroupMembershipService that other components call, so they depend
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
 * Contract of WorkGroupMembershipService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WorkGroupMembershipServiceInterface {

	/**
	 * Join a group, leaving the learner's other group of the same set.
	 *
	 * @param PortalLearner $learner The learner.
	 * @param string        $groupId The WorkGroup uuid.
	 *
	 * @return PortalOutcome 200 `{groupId, left}`, or 403 / 404 / 409 / 422 with a reason.
	 *
	 * @spec openspec/specs/enrolment/spec.md#scenario-a-full-group-takes-nobody-more
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-is-in-one-work-group-per-set
	 */
	public function join(PortalLearner $learner, string $groupId): PortalOutcome;

	/**
	 * Leave a group.
	 *
	 * @param PortalLearner $learner The learner.
	 * @param string        $groupId The WorkGroup uuid.
	 *
	 * @return PortalOutcome 200 `{groupId}`, or a refusal.
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
	 */
	public function leave(PortalLearner $learner, string $groupId): PortalOutcome;

	/**
	 * The learner's overview of their classes' work groups.
	 *
	 * @param PortalLearner $learner The learner.
	 *
	 * @return PortalOutcome 200 `{sets}`.
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
	 */
	public function mine(PortalLearner $learner): PortalOutcome;
}//end interface
