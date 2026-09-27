<?php

/**
 * Learniq Shillinq Contribution Settled Listener
 *
 * Grants a learner's Entitlement when shillinq reports the school contribution
 * for it settled (D19, payments-to-shillinq-migration; shillinq contract
 * extracurricular-fee-to-shillinq v1).
 *
 * THE SIGNAL. Shillinq stamps `settledAt` and `settledVia` on a PaymentRequest
 * once, the first time it counts as paid, and never clears them. OpenRegister
 * dispatches ObjectUpdatedEvent for that save with the old and the new object.
 * The signal is the edge: the old object has no `settledAt`, the new one has,
 * and `subject.app` is learniq. The listener keys on that and on nothing else;
 * BootListenerRegistrar narrows the subscription to register `shillinq`,
 * schema `PaymentRequest`. No shillinq class is referenced, so without shillinq
 * the listener is never called.
 *
 * WHAT IT DOES. The request's `subject` is the chargeable FeeItem and its
 * `beneficiary` the learner. Every pending Entitlement for that FeeItem and
 * learner gets `paymentRequestRef`, `paymentSettledAt` and `paymentSettledVia`
 * stamped and its `grant` transition fired. The grant guard
 * (FeeItemVoluntaryEntitlementGuard, then EntitlementPaymentSettledGuard) reads
 * the request back from shillinq's own register before it allows the
 * transition. A voluntary fee has no Entitlement that can be granted, so its
 * settled contribution changes nothing here, which is what the Wet vrijwillige
 * ouderbijdrage requires.
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
 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-a-settled-shillinq-contribution-grants-the-learners-entitlement
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ContributionBeneficiaryResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns shillinq's settled contributions into Entitlement grants.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-a-settled-shillinq-contribution-grants-the-learners-entitlement
 */
class ShillinqContributionSettledListener implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const ENTITLEMENT_SCHEMA = 'entitlement';

	/**
	 * A learner holds at most a handful of entitlements for one fee.
	 */
	private const MAX_ENTITLEMENTS = 10;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param ContributionBeneficiaryResolver $contributions Reads the request's chargeable and beneficiary.
	 * @param TransitionEngine $transitionEngine Fires the Entitlement grant transition.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ContributionBeneficiaryResolver $contributions,
		private readonly TransitionEngine $transitionEngine,
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
	 * @listener-placement inline correctness — the portal's pay action returns
	 * to a page that reads the entitlement straight back, and a learner whose
	 * course fee was just paid must find the course open. Deferring the grant
	 * to a background job would show "not paid" for as long as the queue takes.
	 * The work is bounded: it runs only on the one save that first stamps
	 * settledAt, reads at most ten entitlements, and saves and grants those.
	 *
	 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-a-settled-shillinq-contribution-grants-the-learners-entitlement
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		$new = $this->dataOf(entity: $event->getNewObject());
		$old = $this->dataOf(entity: $event->getOldObject());
		if ($this->isSettledEdge(new: $new, old: $old) === false) {
			return;
		}

		$feeItemId = $this->contributions->feeItemIdOf(request: $new);
		if ($feeItemId === null) {
			return;
		}

		$requestId = (string)($event->getNewObject()->getUuid() ?? ($new['id'] ?? ''));
		try {
			$learnerId = $this->contributions->learnerIdOf(request: $new);
			if ($learnerId === null || $requestId === '') {
				$this->logger->info(
					'[ShillinqContributionSettledListener] Settled contribution {request} names no learniq learner; nothing to grant.',
					['request' => $requestId]
				);
				return;
			}

			foreach ($this->pendingEntitlements(feeItemId: $feeItemId, learnerId: $learnerId) as $entitlement) {
				$this->grant(entitlement: $entitlement, requestId: $requestId, request: $new);
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ShillinqContributionSettledListener] Could not apply settled contribution {request}: {msg}',
				['request' => $requestId, 'msg' => $exception->getMessage()]
			);
		}//end try
	}//end handle()

	/**
	 * Whether this update is the settled edge: no settledAt before, one now.
	 *
	 * @param array<string,mixed> $new The request after the update.
	 * @param array<string,mixed> $old The request before the update.
	 *
	 * @return bool
	 */
	private function isSettledEdge(array $new, array $old): bool {
		return empty($new['settledAt']) === false && empty($old['settledAt']) === true;
	}//end isSettledEdge()

	/**
	 * Stamp the settled request on one pending Entitlement and fire `grant`.
	 *
	 * @param array<string,mixed> $entitlement The pending Entitlement.
	 * @param string $requestId The shillinq PaymentRequest id.
	 * @param array<string,mixed> $request The settled PaymentRequest.
	 *
	 * @return void
	 */
	private function grant(array $entitlement, string $requestId, array $request): void {
		$entitlementId = (string)($entitlement['id'] ?? ($entitlement['uuid'] ?? ''));
		if ($entitlementId === '') {
			return;
		}

		try {
			$this->objectService->saveObject(
				object: array_merge(
					$entitlement,
					[
						'paymentRequestRef' => $requestId,
						'paymentSettledAt' => (string)$request['settledAt'],
						'paymentSettledVia' => ($request['settledVia'] ?? null),
					]
				),
				register: self::LEARNIQ_REGISTER,
				schema: self::ENTITLEMENT_SCHEMA,
				uuid: $entitlementId,
				_rbac: false,
				_multitenancy: false
			);
			$this->transitionEngine->transition($entitlementId, 'grant');
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ShillinqContributionSettledListener] Could not grant entitlement {id} for request {request}: {msg}',
				['id' => $entitlementId, 'request' => $requestId, 'msg' => $exception->getMessage()]
			);
		}//end try
	}//end grant()

	/**
	 * The learner's pending Entitlements for one FeeItem.
	 *
	 * @param string $feeItemId The chargeable FeeItem.
	 * @param string $learnerId The learner's Nextcloud user id.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private function pendingEntitlements(string $feeItemId, string $learnerId): array {
		$rows = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::ENTITLEMENT_SCHEMA,
					'feeItemId' => $feeItemId,
					'learnerId' => $learnerId,
					'lifecycle' => 'pending',
				],
				'limit' => self::MAX_ENTITLEMENTS,
			],
			_rbac: false,
			_multitenancy: false
		);

		$pending = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				$row = $row->jsonSerialize();
			}

			$pending[] = $row;
		}

		return $pending;
	}//end pendingEntitlements()

	/**
	 * An entity's object data, or [] when there is none.
	 *
	 * @param ObjectEntity|null $entity The entity.
	 *
	 * @return array<string,mixed>
	 */
	private function dataOf(?ObjectEntity $entity): array {
		if ($entity === null) {
			return [];
		}

		$data = $entity->getObject();
		if (is_array($data) === false) {
			return [];
		}

		return $data;
	}//end dataOf()
}//end class
