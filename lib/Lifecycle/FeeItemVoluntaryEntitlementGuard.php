<?php

/**
 * Learniq FeeItem Voluntary Entitlement Guard
 *
 * Lifecycle guard for the Entitlement schema's `grant` transition (pending ->
 * active). This is the STRUCTURAL enforcement of the Wet vrijwillige
 * ouderbijdrage (in force since 1 August 2021, amending WPO/WVO): non-payment
 * of a voluntary contribution MUST NOT exclude a pupil from the activity it
 * funds, and offering a lesser/substitute activity to non-payers is equally
 * non-compliant (rijksoverheid.nl / vo-raad.nl, "Een alternatief bieden is
 * niet voldoende"). Resolves the Entitlement's feeItemId and refuses the
 * transition unconditionally whenever the linked FeeItem.voluntary is true —
 * regardless of whether shillinq ever reports a payment settled. Because nothing
 * in this capability gates access on a `pending` Entitlement (only `active`
 * ones grant anything), this makes it structurally impossible for a
 * voluntary fee to ever become an access gate through this capability's own
 * mechanism, not merely discouraged by convention.
 *
 * NAMED IN THE SCHEMA: this class — not {@see EntitlementPaymentSettledGuard} — is
 * the value of Entitlement.grant.requires in learniq_register.json.
 * OpenRegister's lifecycle engine accepts exactly one `requires` string per
 * transition (verified precedent: ReportPeriodLockGuard's own docblock/
 * changelog entry — LifecycleAnnotationValidator rejects a non-string
 * `requires` value, so there is no "second requires entry" array shape to add
 * alongside this guard). The `grant` transition needs BOTH the voluntary
 * check (this class) AND the payment check ({@see EntitlementPaymentSettledGuard})
 * to pass, so this class composes EntitlementPaymentSettledGuard by constructor
 * injection and calls its check() after its own voluntary check passes —
 * mirroring ReportPeriodLockGuard's own composition of FraudCaseBlockGuard.
 * The voluntary check runs FIRST and short-circuits, so a voluntary FeeItem
 * is refused regardless of any payment state even if a caller
 * only ever exercises this class directly.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema declaration."
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
 * @spec openspec/specs/payments/spec.md#requirement-a-voluntary-feeitem-must-not-gate-enrolment-or-participation
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the Entitlement `grant` (pending -> active) transition against ever
 * activating for a voluntary FeeItem, then composes EntitlementPaymentSettledGuard
 * for the payment check.
 *
 * @spec openspec/specs/payments/spec.md#scenario-an-entitlement-referencing-a-voluntary-feeitem-can-never-activate
 */
class FeeItemVoluntaryEntitlementGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'This entitlement belongs to a voluntary fee, so it can never be granted.';

	private const LEARNIQ_REGISTER = 'learniq';
	private const FEE_ITEM_SCHEMA = 'fee-item';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param EntitlementPaymentSettledGuard $paymentSettledGuard Composed payment-status guard,
	 *                                                  invoked after the voluntary
	 *                                                  check passes.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly EntitlementPaymentSettledGuard $paymentSettledGuard,
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
	 * @spec openspec/specs/payments/spec.md#scenario-an-entitlement-referencing-a-voluntary-feeitem-can-never-activate
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->allows(entitlement: $object) === false) {
			return GuardResult::deny(self::DENIAL);
		}

		// Voluntary check passed: compose the payment-status check.
		return $this->paymentSettledGuard->check($object, $action, $userId);
	}//end check()

	/**
	 * Refuse the `grant` transition unconditionally for a voluntary FeeItem;
	 * otherwise delegate to the composed EntitlementPaymentSettledGuard.
	 *
	 * @param array<string,mixed> $entitlement The object at its target state, transition inputs merged in.
	 *
	 * @return bool True only when the linked FeeItem is non-voluntary AND the
	 *              payment is settled in shillinq; false blocks the transition (HTTP 422).
	 *
	 * @spec openspec/specs/payments/spec.md#scenario-an-entitlement-referencing-a-voluntary-feeitem-can-never-activate
	 */
	private function allows(array $entitlement): bool {
		$entitlementId = $entitlement['id'] ?? ($entitlement['uuid'] ?? '');
		$feeItemId = $entitlement['feeItemId'] ?? null;

		if (is_string($feeItemId) === false || $feeItemId === '') {
			$this->logger->warning(
				'[FeeItemVoluntaryEntitlementGuard] Entitlement {id} has no feeItemId — denying grant (fail closed).',
				['id' => $entitlementId]
			);
			return false;
		}

		$feeItem = $this->fetchFeeItem(feeItemId: $feeItemId);
		if ($feeItem === null) {
			$this->logger->warning(
				'[FeeItemVoluntaryEntitlementGuard] Entitlement {id} links FeeItem {feeItemId} which was not found — denying grant (fail closed).',
				['id' => $entitlementId, 'feeItemId' => $feeItemId]
			);
			return false;
		}

		if (($feeItem['voluntary'] ?? false) === true) {
			$this->logger->info(
				'[FeeItemVoluntaryEntitlementGuard] Entitlement {id} permanently blocked — linked FeeItem'
				. ' {feeItemId} is voluntary (Wet vrijwillige ouderbijdrage); no payment state can override this.',
				['id' => $entitlementId, 'feeItemId' => $feeItemId]
			);
			return false;
		}

		return true;
	}//end allows()

	/**
	 * Fetch the linked FeeItem by id.
	 *
	 * @param string $feeItemId UUID of the FeeItem.
	 *
	 * @return array<string,mixed>|null The FeeItem data array, or null if not found.
	 */
	private function fetchFeeItem(string $feeItemId): ?array {
		$obj = $this->objectService->find(
			id: $feeItemId,
			register: self::LEARNIQ_REGISTER,
			schema: self::FEE_ITEM_SCHEMA
		);

		if ($obj === null) {
			return null;
		}

		return $obj->jsonSerialize();
	}//end fetchFeeItem()
}//end class
