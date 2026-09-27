<?php

/**
 * Learniq Fraud Case Decision Guard
 *
 * Lifecycle guard for the FraudCase schema's `decide` transition
 * (`heard → decided`). Blocks the transition unless `verdict` and
 * `decisionRationale` are set; when `verdict: fraud-proven`, additionally
 * requires a capped sanction (`sanctionType`, `sanctionDurationMonths` ≤ 12,
 * `sanctionScope`) — "up to one-year exclusion" per Universiteit Leiden's
 * fraud process (source 6597) and story 10070.
 *
 * `decidedAt` (now) and `appealDeadline` (`decidedAt` + 42 days, the CBE
 * 6-week appeal window named in journey 1745) are stamped by
 * FraudCaseAppealDeadlineAction on the same transition (learniq#983).
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
 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-fraudcase-decisions-require-a-verdict-rationale-and-when-fraud-is-proven-a-capped-sanction
 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-a-decided-fraudcase-stamps-a-42-day-appeal-deadline
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards the FraudCase `decide` lifecycle transition.
 *
 * Passes only when `verdict` and `decisionRationale` are set; when
 * `verdict === 'fraud-proven'`, also requires `sanctionType`,
 * `sanctionDurationMonths` (integer, at most 12), and `sanctionScope`.
 * `decidedAt` and `appealDeadline` are stamped by FraudCaseAppealDeadlineAction,
 * declared on the same transition: OpenRegister calls guards by value, so a
 * guard can not write onto the object (learniq#983).
 *
 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-fraudcase-decisions-require-a-verdict-rationale-and-when-fraud-is-proven-a-capped-sanction
 */
class FraudCaseDecisionGuard implements LifecycleGuardInterface {

	/**
	 * The transition inputs the caller sends that this guard reads; each is
	 * declared in `inputs` on every transition naming this class.
	 *
	 * @var list<string>
	 */
	public const TRANSITION_INPUTS = [
		'verdict',
		'decisionRationale',
		'sanctionType',
		'sanctionDurationMonths',
		'sanctionScope',
	];

	/**
	 * The verdict value that requires an accompanying sanction.
	 */
	private const FRAUD_PROVEN = 'fraud-proven';

	/**
	 * Maximum allowed sanction duration in months ("up to one-year exclusion").
	 */
	private const MAX_SANCTION_MONTHS = 12;

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
	 * Assert the decision preconditions.
	 *
	 * Called by OpenRegister's LifecycleValidationListener before the `decide`
	 * transition is saved; the verdict, rationale and sanction arrive as the
	 * transition's declared inputs, merged into the object.
	 *
	 * @param array<string,mixed> $object The FraudCase as it would be saved (lifecycle at `decided`).
	 * @param string              $action The transition action (`decide`).
	 * @param string              $userId The caller's uid, or '' without a session.
	 *
	 * @return GuardResult Allow, or deny with what is missing.
	 *
	 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-fraudcase-decisions-require-a-verdict-rationale-and-when-fraud-is-proven-a-capped-sanction
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$caseId = $object['id'] ?? ($object['uuid'] ?? '');
		$verdict = $object['verdict'] ?? '';
		$decisionRationale = $object['decisionRationale'] ?? '';

		if (is_string($verdict) === false || trim($verdict) === ''
			|| is_string($decisionRationale) === false || trim($decisionRationale) === ''
		) {
			$this->logger->info(
				'[FraudCaseDecisionGuard] FraudCase {id} missing verdict and/or decisionRationale — denying decide.',
				['id' => $caseId]
			);
			return GuardResult::deny('A fraud case can only be decided with a verdict and a rationale.');
		}

		if ($verdict === self::FRAUD_PROVEN && $this->hasValidSanction(object: $object) === false) {
			$this->logger->info(
				'[FraudCaseDecisionGuard] FraudCase {id} verdict=fraud-proven but sanction incomplete/invalid — denying decide.',
				['id' => $caseId]
			);
			return GuardResult::deny('A proven fraud needs a sanction type, a scope and a duration of 1 to 12 months.');
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * Whether the object carries a complete, valid sanction (required when fraud-proven).
	 *
	 * @param array<string,mixed> $object The FraudCase property array.
	 *
	 * @return bool True when sanctionType, sanctionScope, and a sanctionDurationMonths
	 *              of at most 12 are all set.
	 *
	 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-fraudcase-decisions-require-a-verdict-rationale-and-when-fraud-is-proven-a-capped-sanction
	 */
	private function hasValidSanction(array $object): bool {
		$sanctionType = $object['sanctionType'] ?? '';
		$sanctionScope = $object['sanctionScope'] ?? '';
		$sanctionDuration = $object['sanctionDurationMonths'] ?? null;

		if (is_string($sanctionType) === false || trim($sanctionType) === '') {
			return false;
		}

		if (is_string($sanctionScope) === false || trim($sanctionScope) === '') {
			return false;
		}

		if (is_numeric($sanctionDuration) === false) {
			return false;
		}

		$months = (int)$sanctionDuration;

		if ($months < 1 || $months > self::MAX_SANCTION_MONTHS) {
			return false;
		}

		return true;
	}//end hasValidSanction()
}//end class
