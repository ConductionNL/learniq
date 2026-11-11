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
	 * Cohort object uuid (== Enrolment.cohortId).
	 *
	 * @var string
	 */
	public readonly string $cohortId;

	/**
	 * Nextcloud user id of the learner.
	 *
	 * @var string
	 */
	public readonly string $learnerId;

	/**
	 * True to add (activate), false to remove (withdraw).
	 *
	 * @var bool
	 */
	public readonly bool $add;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $enrolment The Enrolment payload, with a non-empty string
	 *                                       `cohortId` and a non-empty `learnerId`.
	 * @param bool                $add       True to add (activate), false to remove (withdraw).
	 *
	 * @return void
	 */
	public function __construct(array $enrolment, bool $add) {
		$this->cohortId  = $enrolment['cohortId'];
		$this->learnerId = $enrolment['learnerId'];
		$this->add       = $add;
	}//end __construct()
}//end class
