<?php

/**
 * Learniq cohort Talk membership change.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/specs/school-structure/spec.md#requirement-enrolled-learners-sync-as-talk-room-participants-on-cohort-membership-changes
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

/**
 * One learner joining or leaving the Talk conversations linked to a cohort.
 *
 * @spec openspec/specs/school-structure/spec.md#requirement-enrolled-learners-sync-as-talk-room-participants-on-cohort-membership-changes
 */
final class CohortTalkMembershipChange {

	/**
	 * Constructor.
	 *
	 * @param string $cohortId  Cohort object uuid (== Enrolment.cohortId).
	 * @param string $learnerId Nextcloud user id of the learner.
	 * @param bool   $add       True to add (activate), false to remove (withdraw).
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $cohortId,
		public readonly string $learnerId,
		public readonly bool $add,
	) {
	}//end __construct()
}//end class
