<?php

/**
 * Learniq Portal Attempt Start
 *
 * One pupil's request to start a timed test, as PortalAttemptService::start()
 * works through it: who asked, for which test, with which code, and what the
 * steps read on the way (the extra time and the attempts so far). Built and
 * read only inside PortalAttemptService, for the length of one start.
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
 * One start of a portal attempt, step by step.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */
final class PortalAttemptStart {

	/**
	 * The pupil.
	 *
	 * @var PortalLearner
	 */
	public PortalLearner $learner;

	/**
	 * The test's uuid, as the pupil asked for it.
	 *
	 * @var string
	 */
	public string $taskId;

	/**
	 * The test, raw.
	 *
	 * @var array<string, mixed>
	 */
	public array $exam;

	/**
	 * The code the pupil typed, if any.
	 *
	 * @var mixed
	 */
	public mixed $accessCode;

	/**
	 * The pupil's extra time on this test.
	 *
	 * @var float
	 */
	public float $extra;

	/**
	 * The pupil's attempts at this test so far.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $attempts;
}//end class
