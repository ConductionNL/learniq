<?php

/**
 * Tests for ShillinqPaymentSettledListener (D19, payments-to-shillinq-migration).
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Listener
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

namespace OCA\Learniq\Tests\Unit\Listener;

use DateTime;
use OCA\Learniq\AppInfo\Registrar\BootListenerRegistrar;
use OCA\Learniq\Lifecycle\EntitlementPaymentSettledGuard;
use OCA\Learniq\Listener\ShillinqPaymentSettledListener;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Event\ObjectEventSubscription;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for ShillinqPaymentSettledListener::handle().
 */
class ShillinqPaymentSettledListenerTest extends TestCase {

	/**
	 * The stored Entitlement, or null.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $entitlement = null;

	/**
	 * Saves: object, register, schema, uuid.
	 *
	 * @var array<int, array<string,mixed>>
	 */
	private array $saves = [];

	/**
	 * Fired transitions as "id:action".
	 *
	 * @var array<int, string>
	 */
	private array $transitions = [];

	/**
	 * When set, the transition throws.
	 *
	 * @var bool
	 */
	private bool $transitionFails = false;

	/**
	 * Build the listener.
	 *
	 * @return ShillinqPaymentSettledListener
	 */
	private function makeListener(): ShillinqPaymentSettledListener {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ?ObjectEntity {
				if ($register === 'learniq' && $schema === 'entitlement' && $this->entitlement !== null && $id === $this->entitlement['id']) {
					return OrEntityFactory::make($this->entitlement, 'entitlement');
				}

				return null;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntity {
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				$this->entitlement = $object;

				return OrEntityFactory::make($object, (string)$schema);
			}
		);

		$engine = $this->createMock(TransitionEngine::class);
		$engine->method('transition')->willReturnCallback(
			function (string $objectId, string $action): ObjectEntity {
				if ($this->transitionFails === true) {
					throw new RuntimeException('guard refused');
				}

				$this->transitions[] = $objectId . ':' . $action;

				return OrEntityFactory::make(['id' => $objectId], 'entitlement');
			}
		);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new DateTime('2026-09-27T12:00:00+00:00'));

		$guard = new EntitlementPaymentSettledGuard($objectService, $this->createMock(IAppManager::class), new NullLogger());

		return new ShillinqPaymentSettledListener($objectService, $guard, $engine, $clock, new NullLogger());
	}//end makeListener()

	/**
	 * An update of a PaymentRequest from $old to $new state.
	 *
	 * @param string $old     State before.
	 * @param string $new     State after.
	 * @param array<string,mixed> $override Fields to override on the request.
	 *
	 * @return ObjectUpdatedEvent
	 */
	private function update(string $old, string $new, array $override = []): ObjectUpdatedEvent {
		$request = array_merge(
			[
				'id' => 'pr-1',
				'subjectKind' => 'object',
				'subject' => ['type' => 'entitlement', 'register' => 'learniq', 'schema' => 'entitlement', 'id' => 'ent-1'],
				'requestType' => 'other',
				'paymentGateway' => 'mollie',
				'amount' => 45.0,
			],
			$override
		);

		return new ObjectUpdatedEvent(
			OrEntityFactory::make(array_merge($request, ['state' => $new]), 'PaymentRequest'),
			OrEntityFactory::make(array_merge($request, ['state' => $old]), 'PaymentRequest')
		);
	}//end update()

	/**
	 * A captured request stamps the pending Entitlement and fires grant.
	 *
	 * @return void
	 */
	public function testACapturedRequestGrantsItsPendingEntitlement(): void {
		$this->entitlement = ['id' => 'ent-1', 'feeItemId' => 'fee-1', 'learnerId' => 'leerling-001', 'lifecycle' => 'pending'];
		$this->makeListener()->handle($this->update(old: 'pending', new: 'captured'));

		self::assertCount(1, $this->saves);
		self::assertSame('pr-1', $this->saves[0]['object']['paymentRequestRef']);
		self::assertSame('2026-09-27T12:00:00+00:00', $this->saves[0]['object']['paymentSettledAt']);
		self::assertSame('ent-1', $this->saves[0]['uuid']);
		self::assertSame(['ent-1:grant'], $this->transitions);
	}//end testACapturedRequestGrantsItsPendingEntitlement()

	/**
	 * A voided request revokes the active Entitlement it granted.
	 *
	 * @return void
	 */
	public function testAVoidedRequestRevokesTheEntitlementItGranted(): void {
		$this->entitlement = ['id' => 'ent-1', 'paymentRequestRef' => 'pr-1', 'lifecycle' => 'active'];
		$this->makeListener()->handle($this->update(old: 'captured', new: 'voided'));

		self::assertSame(['ent-1:revoke'], $this->transitions);
		self::assertSame([], $this->saves);
	}//end testAVoidedRequestRevokesTheEntitlementItGranted()

	/**
	 * A voided request does not revoke an Entitlement another request granted.
	 *
	 * @return void
	 */
	public function testAVoidedRequestLeavesAnotherRequestsEntitlementAlone(): void {
		$this->entitlement = ['id' => 'ent-1', 'paymentRequestRef' => 'pr-2', 'lifecycle' => 'active'];
		$this->makeListener()->handle($this->update(old: 'captured', new: 'voided'));

		self::assertSame([], $this->transitions);
	}//end testAVoidedRequestLeavesAnotherRequestsEntitlementAlone()

	/**
	 * Updates that are not a PaymentRequest settling on a learniq Entitlement are ignored.
	 *
	 * @return void
	 */
	public function testOtherUpdatesAreIgnored(): void {
		$this->entitlement = ['id' => 'ent-1', 'lifecycle' => 'pending'];
		$listener = $this->makeListener();
		// Already captured before: a re-save is not a new settlement.
		$listener->handle($this->update(old: 'captured', new: 'captured'));
		// Captured unapplied is not settled.
		$listener->handle($this->update(old: 'pending', new: 'captured_unapplied'));
		// An invoice request, a request on a dossiq case, and an object without a gateway.
		$listener->handle($this->update(old: 'pending', new: 'captured', override: ['subjectKind' => 'invoice']));
		$listener->handle($this->update(old: 'pending', new: 'captured', override: ['subject' => ['register' => 'dossiq', 'schema' => 'Zaak', 'id' => 'ent-1']]));
		$lookalike = new ObjectUpdatedEvent(
			OrEntityFactory::make(['id' => 'x', 'subjectKind' => 'object', 'subject' => ['register' => 'learniq', 'schema' => 'entitlement', 'id' => 'ent-1'], 'state' => 'captured'], 'thing'),
			null
		);
		$listener->handle($lookalike);

		self::assertSame([], $this->saves);
		self::assertSame([], $this->transitions);
	}//end testOtherUpdatesAreIgnored()

	/**
	 * An Entitlement that is not pending is not granted twice.
	 *
	 * @return void
	 */
	public function testAnActiveEntitlementIsNotGrantedAgain(): void {
		$this->entitlement = ['id' => 'ent-1', 'paymentRequestRef' => 'pr-1', 'lifecycle' => 'active'];
		$this->makeListener()->handle($this->update(old: 'authorized', new: 'captured'));

		self::assertSame([], $this->saves);
		self::assertSame([], $this->transitions);
	}//end testAnActiveEntitlementIsNotGrantedAgain()

	/**
	 * A refused grant never throws into shillinq's write.
	 *
	 * @return void
	 */
	public function testARefusedGrantDoesNotThrow(): void {
		$this->entitlement = ['id' => 'ent-1', 'lifecycle' => 'pending'];
		$this->transitionFails = true;
		$this->makeListener()->handle($this->update(old: 'pending', new: 'captured'));

		self::assertCount(1, $this->saves);
		self::assertSame([], $this->transitions);
	}//end testARefusedGrantDoesNotThrow()

	/**
	 * The listener is subscribed on ObjectUpdatedEvent, asserted from the caller.
	 *
	 * @return void
	 */
	public function testTheListenerIsRegisteredOnObjectUpdatedEvent(): void {
		(new BootListenerRegistrar())->register(dispatcher: $this->createMock(IEventDispatcher::class), appId: 'learniq');

		$subscribed = ObjectEventSubscription::subscribedListeners();
		self::assertContains(ShillinqPaymentSettledListener::class, ($subscribed[ObjectUpdatedEvent::class] ?? []));
		foreach ($subscribed as $listeners) {
			self::assertNotContains('OCA\\Learniq\\Listener\\PaymentTransactionStatusHandler', $listeners);
		}
	}//end testTheListenerIsRegisteredOnObjectUpdatedEvent()
}//end class
