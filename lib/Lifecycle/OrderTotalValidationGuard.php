<?php

/**
 * Learniq Order Total Validation Guard
 *
 * Lifecycle guard for the Order schema's `finalize` transition (draft ->
 * open). Order.totalAmount is written by the frontend line-editor and MUST
 * NOT be trusted as-is: this guard recomputes the sum of the Order's
 * OrderLine.lineTotal rows via OrderTotalEvaluator and refuses the transition
 * if the stored totalAmount does not match. An Order with zero OrderLines is
 * refused (nothing to finalize).
 *
 * This is a legitimate PHP lifecycle seam per ADR-031 §"Lifecycle guards"
 * plus the same cross-schema-sum exception GradeFormulaEvaluator/
 * BsaProgressEvaluator already establish (see OrderTotalEvaluator's own
 * docblock) — applied here as a validation guard at the finalize transition
 * rather than a continuously materialised field, since Order.totalAmount only
 * needs to be correct at the moment a payer can no longer edit the lines.
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
 * @spec openspec/changes/school-payments/specs/payments/spec.md#requirement-persist-order-and-orderline-as-the-payer-facing-request-for-payment-with-a-validated-total
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\Learniq\Service\OrderTotalEvaluator;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards the Order `finalize` (draft -> open) lifecycle transition.
 *
 * Refuses the transition unless the stored totalAmount exactly equals the
 * sum of the Order's OrderLine.lineTotal rows, and refuses an Order with no
 * OrderLines at all (nothing to finalize).
 *
 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-finalizing-an-order-with-a-mismatched-total-is-refused
 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-finalizing-an-order-with-a-correct-total-succeeds
 */
class OrderTotalValidationGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'The order total does not match the sum of its order lines, or the order has no lines.';

	/**
	 * Floating-point comparison tolerance for currency amounts (half a cent).
	 *
	 * @var float
	 */
	private const AMOUNT_EPSILON = 0.005;

	/**
	 * Constructor.
	 *
	 * @param OrderTotalEvaluator $evaluator Sums an Order's OrderLine.lineTotal rows.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly OrderTotalEvaluator $evaluator,
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
	 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-finalizing-an-order-with-a-mismatched-total-is-refused
	 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-finalizing-an-order-with-a-correct-total-succeeds
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->allows(order: $object) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::DENIAL);
	}//end check()

	/**
	 * Allow the `finalize` transition only when totalAmount matches the sum
	 * of the Order's OrderLines.
	 *
	 * @param array<string,mixed> $order The object at its target state, transition inputs merged in.
	 *
	 * @return bool True if the transition is allowed; false blocks it (HTTP 422).
	 *
	 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-finalizing-an-order-with-a-mismatched-total-is-refused
	 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-finalizing-an-order-with-a-correct-total-succeeds
	 */
	private function allows(array $order): bool {
		$orderId = $order['id'] ?? ($order['uuid'] ?? '');
		$storedTotal = (float)($order['totalAmount'] ?? 0);

		$result = $this->evaluator->evaluate(orderId: (string)$orderId);

		if ($result['lineCount'] === 0) {
			$this->logger->info(
				'[OrderTotalValidationGuard] Order {id} has no OrderLines — refusing finalize (nothing to finalize).',
				['id' => $orderId]
			);
			return false;
		}

		if (abs($result['total'] - $storedTotal) > self::AMOUNT_EPSILON) {
			$this->logger->info(
				'[OrderTotalValidationGuard] Order {id} totalAmount ({stored}) does not match'
				. ' the sum of its OrderLines ({computed}) — refusing finalize.',
				[
					'id' => $orderId,
					'stored' => $storedTotal,
					'computed' => $result['total'],
				]
			);
			return false;
		}

		return true;
	}//end allows()
}//end class
