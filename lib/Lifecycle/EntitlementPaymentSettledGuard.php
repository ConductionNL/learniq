<?php

/**
 * Learniq Entitlement Payment Settled Guard
 *
 * Lifecycle guard for the Entitlement `grant` transition (pending -> active).
 * Replaces EntitlementOrderPaidGuard (D19, payments-to-shillinq-migration):
 * learniq no longer keeps orders or payment transactions. A school charges
 * through shillinq, which raises a PaymentRequest on the Entitlement itself
 * (`subjectKind: object`, ADR-048 semantic subject) and books the receipt.
 *
 * The guard allows `grant` only when the Entitlement's `paymentRequestRef`
 * names a PaymentRequest in shillinq's register that
 * - exists,
 * - stands on this Entitlement (`subject.register` learniq, `subject.schema`
 *   entitlement, `subject.id` this Entitlement's id), and
 * - is settled: `state` is `captured`. `captured_unapplied` is not settled:
 *   shillinq took the money but could not book the receipt.
 *
 * It FAILS CLOSED: shillinq not installed, no reference, the request not
 * found, a read error, a subject naming another object, or any other state
 * all refuse the grant. Shillinq is read duck-typed through OpenRegister's
 * ObjectService by register and schema slug; learniq references no shillinq
 * class, so learniq installs and runs without it.
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
 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-an-entitlement-is-granted-only-once-shillinq-reports-its-payment-request-settled
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Guards the Entitlement `grant` transition on shillinq's payment state.
 *
 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-an-entitlement-is-granted-only-once-shillinq-reports-its-payment-request-settled
 */
class EntitlementPaymentSettledGuard implements LifecycleGuardInterface {

	/**
	 * Shillinq's app id, its register slug and its PaymentRequest schema slug.
	 */
	public const SHILLINQ_APP = 'shillinq';
	public const SHILLINQ_REGISTER = 'shillinq';
	public const PAYMENT_REQUEST_SCHEMA = 'PaymentRequest';

	/**
	 * The PaymentRequest state that means the money arrived and was booked.
	 */
	public const SETTLED_STATE = 'captured';

	/**
	 * How a PaymentRequest names a learniq Entitlement as its subject.
	 */
	public const SUBJECT_REGISTER = 'learniq';
	public const SUBJECT_SCHEMA = 'entitlement';

	private const DENIAL_NO_SHILLINQ = 'Payments run through shillinq, which is not installed, so no entitlement can be granted.';
	private const DENIAL_NOT_SETTLED = 'This entitlement has no settled payment in shillinq yet.';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access (reads shillinq's register).
	 * @param IAppManager $appManager Tells "shillinq absent" from "not paid".
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IAppManager $appManager,
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
	 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-an-entitlement-is-granted-only-once-shillinq-reports-its-payment-request-settled
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->appManager->isInstalled(self::SHILLINQ_APP) === false) {
			return GuardResult::deny(self::DENIAL_NO_SHILLINQ);
		}

		$request = $this->settledRequest(entitlement: $object);
		if ($request === null) {
			return GuardResult::deny(self::DENIAL_NOT_SETTLED);
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * The settled PaymentRequest standing on this Entitlement, or null.
	 *
	 * @param array<string,mixed> $entitlement The Entitlement.
	 *
	 * @return array<string,mixed>|null
	 */
	private function settledRequest(array $entitlement): ?array {
		$entitlementId = (string)($entitlement['id'] ?? ($entitlement['uuid'] ?? ''));
		$ref = $entitlement['paymentRequestRef'] ?? null;
		if ($entitlementId === '' || is_string($ref) === false || $ref === '') {
			return null;
		}

		try {
			$found = $this->objectService->find(
				id: $ref,
				register: self::SHILLINQ_REGISTER,
				schema: self::PAYMENT_REQUEST_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[EntitlementPaymentSettledGuard] Could not read payment request {ref} for entitlement {id}; refusing: {msg}',
				['ref' => $ref, 'id' => $entitlementId, 'msg' => $exception->getMessage()]
			);
			return null;
		}

		if ($found === null) {
			return null;
		}

		$request = $found->jsonSerialize();
		if ($this->isSettledRequestFor(request: $request, entitlementId: $entitlementId) === false) {
			return null;
		}

		return $request;
	}//end settledRequest()

	/**
	 * Whether a PaymentRequest is settled and stands on the given Entitlement.
	 *
	 * Used by {@see \OCA\Learniq\Listener\ShillinqPaymentSettledListener} too, so the
	 * signal and the guard read the contract the same way.
	 *
	 * @param array<string,mixed> $request The PaymentRequest payload.
	 * @param string $entitlementId The Entitlement id, or '' to accept any learniq Entitlement.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-an-entitlement-is-granted-only-once-shillinq-reports-its-payment-request-settled
	 */
	public function isSettledRequestFor(array $request, string $entitlementId): bool {
		if (($request['state'] ?? null) !== self::SETTLED_STATE) {
			return false;
		}

		$subjectId = $this->subjectEntitlementId(request: $request);
		if ($subjectId === null) {
			return false;
		}

		return $entitlementId === '' || $subjectId === $entitlementId;
	}//end isSettledRequestFor()

	/**
	 * The Entitlement id a PaymentRequest stands on, or null when it stands on anything else.
	 *
	 * @param array<string,mixed> $request The PaymentRequest payload.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-an-entitlement-is-granted-only-once-shillinq-reports-its-payment-request-settled
	 */
	public function subjectEntitlementId(array $request): ?string {
		if (($request['subjectKind'] ?? null) !== 'object') {
			return null;
		}

		$subject = $request['subject'] ?? null;
		if (is_array($subject) === false
			|| ($subject['register'] ?? null) !== self::SUBJECT_REGISTER
			|| ($subject['schema'] ?? null) !== self::SUBJECT_SCHEMA
		) {
			return null;
		}

		$id = $subject['id'] ?? null;
		if (is_string($id) === false || $id === '') {
			return null;
		}

		return $id;
	}//end subjectEntitlementId()
}//end class
