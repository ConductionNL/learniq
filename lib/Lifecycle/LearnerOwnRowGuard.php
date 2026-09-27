<?php

/**
 * Learniq Learner Own Row Guard
 *
 * Lifecycle guard for the learner-side transitions that have no business rule
 * of their own: granting and revoking a LearningRecordShare, and starting and
 * ending a ProctoringSession. Any signed-in user may create these rows
 * (OpenRegister checks create without the object, so a schema cannot narrow
 * it), so the transition is where a row made in someone else's name is
 * refused: the caller must be the row's `learnerId`. A share must also be of
 * an export that belongs to that same learner, so nobody shares someone
 * else's record by naming themselves on the share.
 *
 * Referenced from `transitions.*.requires` in learniq_register.json.
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
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-a-learner-runs-the-transitions-on-their-own-rows
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Lets only the learner named on the row run its learner-side transitions.
 *
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-a-learner-runs-the-transitions-on-their-own-rows
 */
class LearnerOwnRowGuard implements LifecycleGuardInterface {

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Resolves the export a share points at.
	 * @param LearnerCaller $learnerCaller Decides whether the caller is the row's learner.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LearnerCaller $learnerCaller,
	) {
	}//end __construct()

	/**
	 * Allow the transition when the caller is the row's learner.
	 *
	 * Administrators and system calls (no session, `$userId` empty) are not
	 * refused.
	 *
	 * @param array<string, mixed> $object The row as it would be saved.
	 * @param string               $action The transition being applied.
	 * @param string               $userId The caller, or '' without a session.
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-a-learner-runs-the-transitions-on-their-own-rows
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->learnerCaller->isNamed(userId: $userId, named: ($object['learnerId'] ?? null)) === false) {
			return GuardResult::deny(sprintf('Only the learner this record belongs to can %s it.', $action));
		}

		$exportId = ($object['learningRecordExportId'] ?? null);
		if (is_string($exportId) === true && $exportId !== '' && $this->exportLearner(exportId: $exportId) !== ($object['learnerId'] ?? null)) {
			return GuardResult::deny('A share must be of an export of the same learner.');
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * The learner of the export a share points at.
	 *
	 * @param string $exportId The LearningRecordExport id.
	 *
	 * @return string|null The export's learnerId, or null when it cannot be found.
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-a-learner-runs-the-transitions-on-their-own-rows
	 */
	private function exportLearner(string $exportId): ?string {
		$export = $this->objectService->find(id: $exportId, register: 'learniq', schema: 'learning-record-export');
		if ($export === null) {
			return null;
		}

		$learnerId = ($export->jsonSerialize()['learnerId'] ?? null);
		if (is_string($learnerId) === false) {
			return null;
		}

		return $learnerId;
	}//end exportLearner()
}//end class
