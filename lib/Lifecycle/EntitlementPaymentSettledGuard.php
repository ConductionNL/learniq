<?php

/**
 * Learniq Entitlement Payment Settled Guard
 *
 * Lifecycle guard for the Entitlement `grant` transition (pending -> active).
 * Replaces EntitlementOrderPaidGuard (D19, payments-to-shillinq-migration):
 * learniq no longer keeps orders or payment transactions. A school raises a
 * fee's contributions in shillinq (the FeeItem is the chargeable, the learner
 * the beneficiary) and shillinq books the receipt.
 *
 * The guard allows `grant` only when the Entitlement's `paymentRequestRef`
 * names a PaymentRequest in shillinq's register that
 * - exists,
 * - charges for this Entitlement's FeeItem (`subject.app` learniq,
 *   `subject.id` the Entitlement's `feeItemId`),
 * - is for this Entitlement's learner (`beneficiary`), and
 * - is settled: `settledAt` is set. That is shillinq's settled signal
 *   (contract extracurricular-fee-to-shillinq v1): written once, the first
 *   time the request counts as paid, and never cleared.
 *
 * It FAILS CLOSED: shillinq not installed, no reference, the request not
 * found, a read error, another fee or learner, or no `settledAt` all refuse
 * the grant. Shillinq is read duck-typed through OpenRegister's ObjectService
 * by register and schema slug; learniq references no shillinq class.
 *
 * Composed by {@see FeeItemVoluntaryEntitlementGuard}, which is the class the
 * Entitlement schema names in `grant.requires` and which refuses a voluntary
 * fee before this guard is asked.
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
 * @spec openspec/specs/payments/spec.md#requirement-an-entitlement-is-granted-only-once-shillinq-reports-its-payment-request-settled
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\Learniq\Service\ContributionBeneficiaryResolver;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Guards the Entitlement `grant` transition on shillinq's settled signal.
 *
 * @spec openspec/specs/payments/spec.md#requirement-an-entitlement-is-granted-only-once-shillinq-reports-its-payment-request-settled
 */
class EntitlementPaymentSettledGuard implements LifecycleGuardInterface {

	/**
	 * Shillinq's app id, its register slug and its PaymentRequest schema slug.
	 */
	public const SHILLINQ_APP = 'shillinq';
	public const SHILLINQ_REGISTER = 'shillinq';
	public const PAYMENT_REQUEST_SCHEMA = 'PaymentRequest';

	private const DENIAL_NO_SHILLINQ = 'Payments run through shillinq, which is not installed, so no entitlement can be granted.';
	private const DENIAL_NOT_SETTLED = 'This entitlement has no settled payment in shillinq yet.';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access (reads shillinq's register).
	 * @param IAppManager $appManager Tells "shillinq absent" from "not paid".
	 * @param ContributionBeneficiaryResolver $contributions Reads the request's chargeable and beneficiary.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IAppManager $appManager,
		private readonly ContributionBeneficiaryResolver $contributions,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Authorise or deny the transition this guard is named on (LifecycleGuardInterface).
	 *
	 * @param array<string,mixed> $object The Entitlement at its target state, transition inputs merged in.
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow, or deny with the reason shown to the caller.
	 *
	 * @spec openspec/specs/payments/spec.md#requirement-an-entitlement-is-granted-only-once-shillinq-reports-its-payment-request-settled
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->appManager->isInstalled(self::SHILLINQ_APP) === false) {
			return GuardResult::deny(self::DENIAL_NO_SHILLINQ);
		}

		if ($this->hasSettledRequest(entitlement: $object) === false) {
			return GuardResult::deny(self::DENIAL_NOT_SETTLED);
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * Whether a settled PaymentRequest for this Entitlement's fee and learner exists.
	 *
	 * @param array<string,mixed> $entitlement The Entitlement.
	 *
	 * @return bool
	 */
	private function hasSettledRequest(array $entitlement): bool {
		$ref = ($entitlement['paymentRequestRef'] ?? null);
		$feeItemId = (string)($entitlement['feeItemId'] ?? '');
		$learnerId = (string)($entitlement['learnerId'] ?? '');
		if (is_string($ref) === false || $ref === '' || $feeItemId === '' || $learnerId === '') {
			return false;
		}

		try {
			$found = $this->objectService->find(
				id: $ref,
				register: self::SHILLINQ_REGISTER,
				schema: self::PAYMENT_REQUEST_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
			if ($found === null) {
				return false;
			}

			$request = $found->jsonSerialize();

			return $this->isSettledFor(request: $request, feeItemId: $feeItemId, learnerId: $learnerId);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[EntitlementPaymentSettledGuard] Could not read payment request {ref}; refusing: {msg}',
				['ref' => $ref, 'msg' => $exception->getMessage()]
			);
			return false;
		}//end try
	}//end hasSettledRequest()

	/**
	 * Whether a PaymentRequest is settled and charges this fee for this learner.
	 *
	 * @param array<string,mixed> $request The PaymentRequest payload.
	 * @param string $feeItemId The Entitlement's FeeItem id.
	 * @param string $learnerId The Entitlement's learner (Nextcloud user id).
	 *
	 * @return bool
	 */
	private function isSettledFor(array $request, string $feeItemId, string $learnerId): bool {
		$settledAt = ($request['settledAt'] ?? null);
		if (is_string($settledAt) === false || $settledAt === '') {
			return false;
		}

		if ($this->contributions->feeItemIdOf(request: $request) !== $feeItemId) {
			return false;
		}

		return $this->contributions->learnerIdOf(request: $request) === $learnerId;
	}//end isSettledFor()
}//end class
