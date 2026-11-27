<?php

/**
 * Learniq Review Panel Gate
 *
 * The review panel branch of {@see AdmissionsDecisionGuard}: for a place,
 * waitlist or reject transition it reads the application's round and the
 * advice given on the application, and hands both to
 * {@see ReviewPanelDecision}. The waiting list's promote is not a panel
 * decision: the panel already decided "waiting list", and a freed seat moves
 * the application on without new advice.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
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
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\Learniq\Service\AdmissionPanelReads;

/**
 * Refuses an admission decision the round's review panel has not reached.
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
 */
final class ReviewPanelGate {

	/**
	 * The decision transitions a review panel decides (not promote).
	 *
	 * @var string[]
	 */
	private const PANEL_DECISIONS = ['place', 'waitlist', 'reject'];

	/**
	 * Constructor.
	 *
	 * @param AdmissionPanelReads $reads Reads the round and the advice.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AdmissionPanelReads $reads,
	) {
	}//end __construct()

	/**
	 * Why the decision may not be recorded, or null when the panel allows it
	 * (or the round names no panel, or the transition is no panel decision).
	 *
	 * @param array<string,mixed> $object The application at its target state.
	 * @param string              $action The transition action.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
	 */
	public function refusal(array $object, string $action): ?string {
		if (in_array($action, self::PANEL_DECISIONS, true) === false) {
			return null;
		}

		$round = $this->reads->object(schema: 'admissions-round', id: (string)($object['admissionsRoundId'] ?? ''));
		$panel = new ReviewPanelDecision(panelMemberIds: ($round['panelMemberIds'] ?? []));
		if ($panel->members() === []) {
			return null;
		}

		$applicationId = (string)($object['id'] ?? ($object['uuid'] ?? ''));

		return $panel->refusal(
			adviceRows: $this->reads->adviceOn(applicationId: $applicationId),
			target: (string)($object['lifecycle'] ?? '')
		);
	}//end refusal()
}//end class
