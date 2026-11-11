<?php

/**
 * Learniq Attempt Start Run
 *
 * The facts one start check weighs: the pupil, the test, the enrolment it
 * belongs to, how many attempts the pupil already has and the current time.
 * PortalAssessmentCatalogue builds one per check and hands it to each rule.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
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
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use DateTimeInterface;

/**
 * An immutable start check.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */
final class AttemptStartRun {

	/**
	 * Constructor.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param array<string, mixed> $exam The test, raw.
	 * @param array<string, mixed>|null $enrolment The enrolment it belongs to.
	 * @param int $attemptsUsed How many attempts the pupil already has.
	 * @param DateTimeInterface $now The current time.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly PortalLearner $learner,
		public readonly array $exam,
		public readonly ?array $enrolment,
		public readonly int $attemptsUsed,
		public readonly DateTimeInterface $now,
	) {
	}//end __construct()
}//end class
