<?php

/**
 * Learniq Exemption Decision Guard
 *
 * Lifecycle guard for the ExemptionCase schema's `grant`/`reject` transitions
 * (`in-assessment → granted|rejected`). Blocks either transition unless the
 * deciding exam-board member has supplied a `decisionRationale` and a
 * `policyReference` on the transition payload.
 *
 * This is a legitimate PHP lifecycle seam per ADR-031 §"Lifecycle guards":
 * combining an evidence/data-completeness precondition (rationale + policy
 * reference set) with the exam board's individual, reasoned-decision
 * obligation (WHW art. 7.13; Kennispunt MBO's exemption guidance — "exemptions
 * are individual decisions of the exam board... a handreiking supports
 * reasoned, consistent decisions") is not expressible declaratively. Mirrors
 * `ExternalTrainingVerificationGuard`'s precondition-guard shape.
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
 * @spec openspec/specs/exam-board/spec.md#requirement-exemptioncase-decisions-require-a-rationale-and-policy-reference
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards the ExemptionCase `grant` and `reject` lifecycle transitions.
 *
 * Passes only when both `decisionRationale` and `policyReference` are
 * non-empty strings on the transitioning object. Fails closed otherwise.
 *
 * @spec openspec/specs/exam-board/spec.md#requirement-exemptioncase-decisions-require-a-rationale-and-policy-reference
 */
class ExemptionDecisionGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'A decision on an exemption needs a rationale and a policy reference.';

	/**
	 * Keys the caller sends with the transition that this guard reads; each is a
	 * declared `inputs` entry on the transition (tests/Unit/Register/LifecycleTransitionInputsTest.php).
	 *
	 * @var list<string>
	 */
	public const TRANSITION_INPUTS = ['decisionRationale', 'policyReference'];
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
	 * @spec openspec/specs/exam-board/spec.md#requirement-exemptioncase-decisions-require-a-rationale-and-policy-reference
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
	 * Assert the decisionRationale + policyReference precondition.
	 *
	 * Called by OpenRegister's lifecycle engine before executing the
	 * `grant`/`reject` transition on an ExemptionCase object.
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 *
	 * @return bool True when both fields are set; false blocks the transition
	 *              (HTTP 422).
	 *
	 * @spec openspec/specs/exam-board/spec.md#requirement-exemptioncase-decisions-require-a-rationale-and-policy-reference
	 */
	private function allows(array $object): bool {
		$caseId = $object['id'] ?? ($object['uuid'] ?? '');
		$decisionRationale = $object['decisionRationale'] ?? '';
		$policyReference = $object['policyReference'] ?? '';

		if (is_string($decisionRationale) === false || trim($decisionRationale) === ''
			|| is_string($policyReference) === false || trim($policyReference) === ''
		) {
			$this->logger->info(
				'[ExemptionDecisionGuard] ExemptionCase {id} missing decisionRationale and/or policyReference — denying grant/reject.',
				['id' => $caseId]
			);
			return false;
		}

		return true;
	}//end allows()
}//end class
