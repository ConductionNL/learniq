<?php

/**
 * Learniq Fraud Case Hearing Guard
 *
 * Lifecycle guard for the FraudCase schema's `scheduleHearing` transition
 * (`reported → hearing-scheduled`). Blocks the transition unless a
 * `hearingDate` has been supplied — a scheduled hearing with no date is not
 * meaningfully "scheduled".
 *
 * This is a legitimate PHP lifecycle seam per ADR-031 §"Lifecycle guards": a
 * single data-completeness precondition on the transition payload, mirroring
 * `AttendanceFlagReportGuard`'s "read the transitioning object" shape but
 * without a cross-schema lookup.
 *
 * Per ADR-008 OR emits the audit-trail entry automatically when the
 * transition completes — this guard records nothing itself.
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
 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-persist-exam-board-domain-objects-in-openregister
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards the FraudCase `scheduleHearing` lifecycle transition.
 *
 * Passes only when `hearingDate` is a non-empty string on the transitioning
 * object. Fails closed otherwise.
 *
 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-persist-exam-board-domain-objects-in-openregister
 */
class FraudCaseHearingGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'A hearing date is needed to schedule the hearing.';

	/**
	 * Keys the caller sends with the transition that this guard reads; each is a
	 * declared `inputs` entry on the transition (tests/Unit/Register/LifecycleTransitionInputsTest.php).
	 *
	 * @var list<string>
	 */
	public const TRANSITION_INPUTS = ['hearingDate'];
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger PSR logger for guard rejections.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Authorise or deny the transition this guard is named on (LifecycleGuardInterface).
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow, or deny with the reason shown to the caller.
	 *
	 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-persist-exam-board-domain-objects-in-openregister
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->allows(object: $object) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::DENIAL);
	}//end check()

	/**
	 * Assert the hearingDate precondition.
	 *
	 * Called by OpenRegister's lifecycle engine before executing the
	 * `scheduleHearing` transition on a FraudCase object.
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 *
	 * @return bool True when hearingDate is set; false blocks the transition
	 *              (HTTP 422).
	 *
	 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-persist-exam-board-domain-objects-in-openregister
	 */
	private function allows(array $object): bool {
		$caseId = $object['id'] ?? ($object['uuid'] ?? '');
		$hearingDate = $object['hearingDate'] ?? '';

		if (is_string($hearingDate) === false || trim($hearingDate) === '') {
			$this->logger->info(
				'[FraudCaseHearingGuard] FraudCase {id} missing hearingDate — denying scheduleHearing.',
				['id' => $caseId]
			);
			return false;
		}

		return true;
	}//end allows()
}//end class
