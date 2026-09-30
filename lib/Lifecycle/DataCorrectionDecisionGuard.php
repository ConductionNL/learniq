<?php

/**
 * Learniq Data Correction Decision Guard
 *
 * Lifecycle guard for the DataCorrectionRequest `approve`, `reject` and
 * `apply` transitions. Who may decide is the transitions' own
 * authorization list (administrators, team leads, administration
 * managers); this guard adds what a group list can not say: the person who
 * asked for a correction can not approve it, and a request is applied only
 * once the grade entry carries the approved value in a published state.
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
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\Learniq\Service\Grading\CorrectionApprovals;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Guards the decision on, and the application of, a data correction request.
 *
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
 */
class DataCorrectionDecisionGuard implements LifecycleGuardInterface {

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objects OpenRegister object access, to read the grade entry on apply.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objects,
	) {
	}//end __construct()

	/**
	 * Authorise or deny the transition (LifecycleGuardInterface).
	 *
	 * @param array<string,mixed> $object The request at its target state, transition inputs merged in.
	 * @param string              $action `approve`, `reject` or `apply`.
	 * @param string              $userId The uid of the caller, '' without a session.
	 *
	 * @return GuardResult Allow, or deny with the reason shown to the caller.
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-the-requester-cannot-approve
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($action === 'apply') {
			return $this->checkApplied(request: $object);
		}

		if ($userId === '') {
			return GuardResult::deny('Sign in to decide on a correction.');
		}

		if ($action === 'approve' && (string)($object['requestedBy'] ?? '') === $userId) {
			return GuardResult::deny('The person who asked for a correction can not also approve it. A second person has to approve it.');
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * A request is applied only when its grade entry is published again with
	 * the approved value.
	 *
	 * @param array<string,mixed> $request The correction request.
	 *
	 * @return GuardResult
	 */
	private function checkApplied(array $request): GuardResult {
		$entryId = (string)($request['gradeEntryId'] ?? '');
		if ($entryId === '') {
			return GuardResult::deny('This correction names no grade entry.');
		}

		try {
			$entity = $this->objects->find(id: $entryId, register: 'learniq', schema: 'grade-entry', _rbac: false);
		} catch (Throwable) {
			$entity = null;
		}

		$entry = $entity?->jsonSerialize() ?? [];
		if (($entry['lifecycle'] ?? null) !== 'published'
			|| CorrectionApprovals::sameValue(approved: ($request['proposedValue'] ?? null), current: ($entry['value'] ?? null)) === false
		) {
			return GuardResult::deny('A correction is applied when its grade is published again with the approved value.');
		}

		return GuardResult::allow();
	}//end checkApplied()
}//end class
