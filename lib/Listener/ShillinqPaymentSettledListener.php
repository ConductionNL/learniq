<?php

/**
 * Learniq Shillinq Payment Settled Listener
 *
 * Grants an Entitlement when shillinq reports the PaymentRequest standing on
 * it settled, and revokes it when shillinq voids a settled request (D19,
 * payments-to-shillinq-migration).
 *
 * THE SIGNAL. Shillinq writes a PaymentRequest's `state` with a plain save
 * (PaymentReconciliationService::reconcile()), so OpenRegister dispatches an
 * ObjectUpdatedEvent, not a transition event. This listener recognises the
 * request by its shape, not by a shillinq class: `subjectKind: object`, a
 * `subject` naming register `learniq`, schema `entitlement`, and a
 * `paymentGateway`. Learniq therefore installs and runs without shillinq; the
 * listener simply never sees such an event.
 *
 * - `state` becomes `captured` (was anything else): stamp `paymentRequestRef`
 *   and `paymentSettledAt` on the pending Entitlement and fire `grant`. The
 *   grant guard (FeeItemVoluntaryEntitlementGuard, then
 *   EntitlementPaymentSettledGuard) reads the request back from shillinq's
 *   own register before it allows the transition, so an object that only
 *   looks like a PaymentRequest cannot grant anything.
 * - `state` becomes `voided` (was `captured`): revoke the active Entitlement
 *   that names this request.
 *
 * Never throws into shillinq's write: every failure is logged and left for a
 * person to retry with the Entitlement's own grant action.
 *
 * ADR-031 legitimate exception: a cross-app, cross-object write no schema
 * expression can make.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-a-settled-shillinq-payment-request-grants-its-entitlement-and-a-voided-one-revokes-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Lifecycle\EntitlementPaymentSettledGuard;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns shillinq's settled and voided PaymentRequests into Entitlement transitions.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-a-settled-shillinq-payment-request-grants-its-entitlement-and-a-voided-one-revokes-it
 */
class ShillinqPaymentSettledListener implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const ENTITLEMENT_SCHEMA = 'entitlement';
	private const VOIDED_STATE = 'voided';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param EntitlementPaymentSettledGuard $settledGuard Reads the PaymentRequest contract (subject, state).
	 * @param TransitionEngine $transitionEngine Fires the Entitlement grant and revoke transitions.
	 * @param ITimeFactory $timeFactory Clock for paymentSettledAt.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly EntitlementPaymentSettledGuard $settledGuard,
		private readonly TransitionEngine $transitionEngine,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectUpdatedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @listener-placement inline correctness — the pay action in the portal
	 * returns to a page that reads the Entitlement straight back, and a pupil
	 * who has just paid for a course must find it open. Deferring the grant to
	 * a background job would show "not paid" for as long as the queue takes.
	 * The work is bounded: one Entitlement read, one save and one transition,
	 * and only for the rare update that moves a request to captured or voided.
	 *
	 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-a-settled-shillinq-payment-request-grants-its-entitlement-and-a-voided-one-revokes-it
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		$request = $event->getNewObject()->jsonSerialize();
		if (array_key_exists('paymentGateway', $request) === false) {
			return;
		}

		$entitlementId = $this->settledGuard->subjectEntitlementId(request: $request);
		if ($entitlementId === null) {
			return;
		}

		$newState = (string)($request['state'] ?? '');
		$oldState = $this->oldState(old: $event->getOldObject());
		$requestId = $this->idOf(row: $request, entity: $event->getNewObject());

		try {
			if ($newState === EntitlementPaymentSettledGuard::SETTLED_STATE && $oldState !== $newState) {
				$this->grant(entitlementId: $entitlementId, requestId: $requestId);
				return;
			}

			if ($newState === self::VOIDED_STATE && $oldState === EntitlementPaymentSettledGuard::SETTLED_STATE) {
				$this->revoke(entitlementId: $entitlementId, requestId: $requestId);
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ShillinqPaymentSettledListener] Could not apply payment request {request} to entitlement {id}: {msg}',
				['request' => $requestId, 'id' => $entitlementId, 'msg' => $exception->getMessage()]
			);
		}
	}//end handle()

	/**
	 * Stamp the settled request on a pending Entitlement and fire `grant`.
	 *
	 * @param string $entitlementId The Entitlement the request stands on.
	 * @param string $requestId The shillinq PaymentRequest id.
	 *
	 * @return void
	 */
	private function grant(string $entitlementId, string $requestId): void {
		$entitlement = $this->entitlement(id: $entitlementId);
		if ($entitlement === null || ($entitlement['lifecycle'] ?? 'pending') !== 'pending' || $requestId === '') {
			return;
		}

		$this->objectService->saveObject(
			object: array_merge(
				$entitlement,
				[
					'paymentRequestRef' => $requestId,
					'paymentSettledAt' => $this->timeFactory->getDateTime()->format(\DATE_ATOM),
				]
			),
			register: self::LEARNIQ_REGISTER,
			schema: self::ENTITLEMENT_SCHEMA,
			uuid: $entitlementId,
			_rbac: false,
			_multitenancy: false
		);

		$this->transitionEngine->transition($entitlementId, 'grant');
	}//end grant()

	/**
	 * Revoke the active Entitlement that was granted by this request.
	 *
	 * @param string $entitlementId The Entitlement the request stands on.
	 * @param string $requestId The shillinq PaymentRequest id.
	 *
	 * @return void
	 */
	private function revoke(string $entitlementId, string $requestId): void {
		$entitlement = $this->entitlement(id: $entitlementId);
		if ($entitlement === null
			|| ($entitlement['lifecycle'] ?? '') !== 'active'
			|| ($entitlement['paymentRequestRef'] ?? null) !== $requestId
		) {
			return;
		}

		$this->transitionEngine->transition($entitlementId, 'revoke');
	}//end revoke()

	/**
	 * The Entitlement row, or null when it does not exist.
	 *
	 * @param string $id Entitlement id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function entitlement(string $id): ?array {
		$found = $this->objectService->find(
			id: $id,
			register: self::LEARNIQ_REGISTER,
			schema: self::ENTITLEMENT_SCHEMA,
			_rbac: false,
			_multitenancy: false
		);

		return $found?->jsonSerialize();
	}//end entitlement()

	/**
	 * The request's state before this update, or '' when unknown.
	 *
	 * @param ObjectEntity|null $old The stored object before the update.
	 *
	 * @return string
	 */
	private function oldState(?ObjectEntity $old): string {
		if ($old === null) {
			return '';
		}

		return (string)($old->jsonSerialize()['state'] ?? '');
	}//end oldState()

	/**
	 * The object id: `id`, `uuid`, `@self.id`, or the entity's uuid.
	 *
	 * @param array<string,mixed> $row Serialised row.
	 * @param ObjectEntity $entity The entity.
	 *
	 * @return string
	 */
	private function idOf(array $row, ObjectEntity $entity): string {
		foreach ([($row['id'] ?? null), ($row['uuid'] ?? null), ($row['@self']['id'] ?? null)] as $candidate) {
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}
		}

		return (string)($entity->getUuid() ?? '');
	}//end idOf()
}//end class
