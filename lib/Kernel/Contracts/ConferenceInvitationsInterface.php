<?php

/**
 * Contract of ConferenceInvitations
 *
 * The public surface of ConferenceInvitations that other components call, so they depend
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
 * Contract of ConferenceInvitations.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConferenceInvitationsInterface {

	/**
	 * Bring the invitation rows of one round in step with the round and its
	 * conversation times.
	 *
	 * @param array<string, mixed> $round The round.
	 *
	 * @return int How many rows were written.
	 *
	 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/parent-conferences/spec.md#requirement-the-guardian-gets-one-task-per-child-per-open-conference-round
	 */
	public function syncRound(array $round): int;
}//end interface
