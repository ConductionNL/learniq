<?php

/**
 * Learniq exam objection decision guard
 *
 * The exam committee's decision on an objection against a result is saved
 * only with a rationale, and a decision that changes the result only with the
 * new result. The guard runs on ExamObjection.decide.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/specs/exam-board/spec.md#requirement-a-student-objects-against-a-result-and-the-committee-decides-within-its-term
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Refuses a decision without a rationale, or a changed result without its value.
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T1
 */
class ExamObjectionDecisionGuard implements LifecycleGuardInterface {

	/**
	 * The decision outcome that changes the result.
	 *
	 * @var string
	 */
	public const OUTCOME_CHANGED = 'result-changed';

	/**
	 * The decision outcomes the committee chooses from.
	 *
	 * @var list<string>
	 */
	public const OUTCOMES = ['result-upheld', self::OUTCOME_CHANGED];

	/**
	 * The fields the decide transition takes as inputs.
	 *
	 * @var list<string>
	 */
	public const TRANSITION_INPUTS = ['decisionOutcome', 'decisionRationale', 'newValue'];

	/**
	 * Allow the decision when it carries what it needs.
	 *
	 * @param array<string, mixed> $object The objection as it will be saved.
	 * @param string               $action The transition.
	 * @param string               $userId The deciding user.
	 *
	 * @return GuardResult The verdict, with the reason when refused.
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T1
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$refusal = $this->refusal(object: $object);
		if ($refusal === null) {
			return GuardResult::allow();
		}

		return GuardResult::deny($refusal);
	}//end check()

	/**
	 * Why the decision can not be saved, or null when it can.
	 *
	 * @param array<string, mixed> $object The objection.
	 *
	 * @return string|null The reason.
	 */
	private function refusal(array $object): ?string {
		$rationale = ($object['decisionRationale'] ?? '');
		if (is_string($rationale) === false || trim($rationale) === '') {
			return 'A decision on an objection needs a rationale.';
		}

		$outcome = ($object['decisionOutcome'] ?? null);
		if (in_array($outcome, self::OUTCOMES, true) === false) {
			return 'Say whether the result is upheld or changed.';
		}

		$newValue = ($object['newValue'] ?? null);
		if ($outcome === self::OUTCOME_CHANGED && is_numeric($newValue) === false) {
			return 'A decision that changes the result needs the new result.';
		}

		return null;
	}//end refusal()
}//end class
