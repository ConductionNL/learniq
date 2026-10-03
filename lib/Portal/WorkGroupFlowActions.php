<?php

/**
 * Learniq Work Group Flow Actions
 *
 * The student portal actions of the work groups
 * (enrolment-self-join-work-group): list the work groups of the pupil's
 * classes, join or move to one, and leave one. Each is an `endpoint-forward` to
 * PortalWorkGroupController on the receiver pattern of #1096 and #1142:
 * portaliq stamps the pupil's own `learnerRef` over any client value, and
 * learniq enforces every rule and writes as the pupil.
 *
 * Kept out of PortalContributionProvider so that class stays under the size
 * limit.
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
 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * Student portal actions for the work groups.
 *
 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
 */
class WorkGroupFlowActions {

	private const BASE = '/apps/learniq/api/portal/work-groups';

	/**
	 * The work group actions, in the order the portal lists them.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
	 */
	public function actions(): array {
		return [
			$this->forward(id: 'listWorkGroups', label: 'My work groups', path: '', fields: []),
			$this->forward(id: 'joinWorkGroup', label: 'Join', path: '/join', fields: ['groupId']),
			$this->forward(id: 'leaveWorkGroup', label: 'Leave', path: '/leave', fields: ['groupId']),
		];
	}//end actions()

	/**
	 * One endpoint-forward action scoped to the pupil.
	 *
	 * @param string        $id     The action id.
	 * @param string        $label  The button label.
	 * @param string        $path   The path under the work group receiver.
	 * @param array<string> $fields The fields the pupil may send.
	 *
	 * @return array<string, mixed>
	 */
	private function forward(string $id, string $label, string $path, array $fields): array {
		return [
			'id' => $id,
			'type' => 'endpoint-forward',
			'label' => $label,
			'endpoint' => self::BASE . $path,
			'method' => 'POST',
			'minTrust' => 'low',
			'fields' => $fields,
			'subjectField' => 'learnerRef',
			'scopeClaim' => 'learnerRef',
		];
	}//end forward()
}//end class
