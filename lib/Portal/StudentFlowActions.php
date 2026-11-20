<?php

/**
 * Learniq Student Flow Actions
 *
 * The portal actions of the round 5 learner flows, contributed to the
 * student audience next to the test and hand-in actions. Each is an
 * `endpoint-forward` to a learniq receiver on the pattern of #1096 and #1142:
 * portaliq stamps the pupil's own `learnerRef` (`subjectField`) over any
 * client value, and the receiver enforces every rule and writes as the pupil.
 *
 * Kept out of PortalContributionProvider so that class stays under the size
 * limit, and so each learner flow adds one method here.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
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
 * @spec openspec/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * Student portal actions for the learner flows.
 *
 * @spec openspec/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
 */
class StudentFlowActions {

	/**
	 * Every learner-flow action, in the order the portal lists them.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
	 */
	public function actions(): array {
		return [$this->checkIn(), $this->requestProofOfEnrolment()];
	}//end actions()

	/**
	 * Check in to a lesson with the code on the board
	 * (attendance-self-check-in). The pupil types only the code; learniq finds
	 * the open check-in of the pupil's own lesson it belongs to.
	 *
	 * @return array<string, mixed>
	 */
	private function checkIn(): array {
		return [
			'id' => 'checkIn',
			'type' => 'endpoint-forward',
			'label' => 'Check in to a lesson',
			'endpoint' => '/apps/learniq/api/portal/check-in',
			'method' => 'POST',
			'minTrust' => 'low',
			'fields' => ['code'],
			'subjectField' => 'learnerRef',
			'scopeClaim' => 'learnerRef',
		];
	}//end checkIn()

	/**
	 * Ask for a proof of enrolment, for a side job or a travel card
	 * (school-documents-on-the-portal T4). The learner fills in nothing:
	 * learniq renders her active enrolment of this school year and the proof
	 * joins her documents.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
	 */
	private function requestProofOfEnrolment(): array {
		return [
			'id' => 'requestProofOfEnrolment',
			'type' => 'endpoint-forward',
			'label' => 'Ask for a proof of enrolment',
			'endpoint' => '/apps/learniq/api/portal/proof-of-enrolment',
			'method' => 'POST',
			'minTrust' => 'low',
			'fields' => [],
			'subjectField' => 'learnerRef',
			'scopeClaim' => 'learnerRef',
			'submitLabel' => 'Ask for a proof of enrolment',
			'successMessage' => 'Your proof of enrolment is ready. You find it under your documents.',
		];
	}//end requestProofOfEnrolment()
}//end class
