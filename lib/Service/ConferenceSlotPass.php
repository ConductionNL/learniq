<?php

/**
 * Learniq conference slot assignment pass.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/parent-conferences/spec.md#scenario-conflict-free-generation-from-sign-ups-and-availability
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * The state of one schedule generation pass over a ConferenceRound: the round
 * and tenant stamped on new slots, the per-teacher queues of free slots, and
 * the teacher-requests already satisfied. Assigning a slot consumes it from
 * its queue and marks its request satisfied.
 *
 * @spec openspec/specs/parent-conferences/spec.md#scenario-conflict-free-generation-from-sign-ups-and-availability
 */
final class ConferenceSlotPass {

	/**
	 * Constructor.
	 *
	 * @param string                                       $roundId        The ConferenceRound id.
	 * @param string                                       $tenantId       Tenant scope stamped on new slots.
	 * @param array<string,array<int,array<string,mixed>>> $queues         Per-teacher queues of free slots, in order.
	 * @param array<string,true>                           $activeSlotKeys Satisfied (signupId|teacherId) keys.
	 */
	public function __construct(
		public readonly string $roundId,
		public readonly string $tenantId,
		public array $queues,
		public array $activeSlotKeys,
	) {
	}//end __construct()
}//end class
