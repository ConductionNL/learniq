<?php

/**
 * Learniq Attempt Start Run
 *
 * The pupil and the test one start check is about. PortalAssessmentCatalogue
 * builds one per check and hands it to each start rule, together with the one
 * further fact that rule weighs (the enrolment, the time or the attempt count).
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
	 *
	 * @return void
	 */
	public function __construct(
		public readonly PortalLearner $learner,
		public readonly array $exam,
	) {
	}//end __construct()
}//end class
