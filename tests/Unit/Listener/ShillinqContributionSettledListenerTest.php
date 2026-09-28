<?php

/**
 * Tests for ShillinqContributionSettledListener (D19, payments-to-shillinq-migration).
 *
 * The PaymentRequest fixtures follow shillinq's contract
 * extracurricular-fee-to-shillinq v1: `subject` is the chargeable FeeItem,
 * `beneficiary` the learner, and the signal is the first `settledAt`.
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
 * @spec openspec/specs/payments/spec.md#requirement-a-settled-shillinq-contribution-grants-the-learners-entitlement
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\BootListenerRegistrar;
use OCA\Learniq\Listener\ShillinqContributionSettledListener;
use OCA\Learniq\Service\ContributionBeneficiaryResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectEventSubscription;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for ShillinqContributionSettledListener::handle().
 */
class ShillinqContributionSettledListenerTest extends TestCase {

	/**
	 * OpenRegister-faithful learniq store: entitlements and learner profiles.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

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
	 * Seed a pending entitlement for leerling-001 on fee-1 and her profile.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['entitlement'] = [
			['id' => 'ent-1', 'feeItemId' => 'fee-1', 'learnerId' => 'leerling-001', 'grantedResourceKind' => 'course-access', 'lifecycle' => 'pending'],
			['id' => 'ent-2', 'feeItemId' => 'fee-2', 'learnerId' => 'leerling-001', 'grantedResourceKind' => 'course-access', 'lifecycle' => 'pending'],
			['id' => 'ent-3', 'feeItemId' => 'fee-1', 'learnerId' => 'leerling-002', 'grantedResourceKind' => 'course-access', 'lifecycle' => 'pending'],
		];
		$this->store->rows['learner-profile'] = [
			['id' => 'lp-1', 'ncUserId' => 'leerling-001'],
		];
	}//end setUp()

	/**
	 * Build the listener over the store.
	 *
	 * @return ShillinqContributionSettledListener
	 */
	private function makeListener(): ShillinqContributionSettledListener {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ?ObjectEntity {
				foreach (($this->store->rows[(string)$schema] ?? []) as $row) {
					if (($row['id'] ?? null) === $id) {
						return OrEntityFactory::make($row, (string)$schema);
					}
				}

				return null;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			fn (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntity => $this->store->save((string)$schema, $object, $uuid)
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

		return new ShillinqContributionSettledListener(
			$objectService,
			new ContributionBeneficiaryResolver($objectService),
			$engine,
			new NullLogger()
		);
	}//end makeListener()

	/**
	 * An update of shillinq PaymentRequest pr-1.
	 *
	 * @param array<string,mixed> $old      Fields before.
	 * @param array<string,mixed> $new      Fields after.
	 * @param array<string,mixed> $override Fields to override on both.
	 *
	 * @return ObjectUpdatedEvent
	 */
	private function update(array $old, array $new, array $override = []): ObjectUpdatedEvent {
		$request = array_merge(
			[
				'id' => 'pr-1',
				'subjectKind' => 'object',
				'subject' => ['app' => 'learniq', 'type' => 'fee-item', 'register' => 'learniq', 'schema' => 'fee-item', 'id' => 'fee-1'],
				'beneficiary' => ['type' => 'learner', 'register' => 'learniq', 'schema' => 'learner-profile', 'id' => 'lp-1'],
				'requestType' => 'contribution',
				'amount' => 895.0,
				'state' => 'pending',
			],
			$override
		);

		return new ObjectUpdatedEvent(
			OrEntityFactory::make(array_merge($request, $new), 'PaymentRequest', 'shillinq'),
			OrEntityFactory::make(array_merge($request, $old), 'PaymentRequest', 'shillinq')
		);
	}//end update()

	/**
	 * The entitlement row with the given id.
	 *
	 * @param string $id Entitlement id.
	 *
	 * @return array<string,mixed>
	 */
	private function entitlement(string $id): array {
		foreach ($this->store->rows['entitlement'] as $row) {
			if ($row['id'] === $id) {
				return $row;
			}
		}

		self::fail("no entitlement $id");
	}//end entitlement()

	/**
	 * The settled edge stamps and grants the learner's pending entitlement for that fee only.
	 *
	 * @return void
	 */
	public function testTheSettledEdgeGrantsTheLearnersEntitlementForThatFee(): void {
		$this->makeListener()->handle(
			$this->update(old: [], new: ['state' => 'captured', 'settledAt' => '2026-10-02T09:15:00+00:00', 'settledVia' => 'provider'])
		);

		self::assertSame(['ent-1:grant'], $this->transitions);
		$granted = $this->entitlement('ent-1');
		self::assertSame('pr-1', $granted['paymentRequestRef']);
		self::assertSame('2026-10-02T09:15:00+00:00', $granted['paymentSettledAt']);
		self::assertSame('provider', $granted['paymentSettledVia']);
		self::assertArrayNotHasKey('paymentRequestRef', $this->entitlement('ent-2'));
		self::assertArrayNotHasKey('paymentRequestRef', $this->entitlement('ent-3'));
	}//end testTheSettledEdgeGrantsTheLearnersEntitlementForThatFee()

	/**
	 * A bare Nextcloud user id as beneficiary works too; a waiver settles like a payment.
	 *
	 * @return void
	 */
	public function testABareUserIdBeneficiaryAndAWaiverAlsoGrant(): void {
		$this->makeListener()->handle(
			$this->update(
				old: [],
				new: ['state' => 'captured', 'settledAt' => '2026-10-03T10:00:00+00:00', 'settledVia' => 'waived'],
				override: ['beneficiary' => ['type' => 'learner', 'id' => 'leerling-002']]
			)
		);

		self::assertSame(['ent-3:grant'], $this->transitions);
		self::assertSame('waived', $this->entitlement('ent-3')['paymentSettledVia']);
	}//end testABareUserIdBeneficiaryAndAWaiverAlsoGrant()

	/**
	 * Only the edge counts: a later save of a settled request, or a capture without settledAt, does nothing.
	 *
	 * @return void
	 */
	public function testOnlyTheEdgeCounts(): void {
		$listener = $this->makeListener();
		$listener->handle($this->update(old: ['settledAt' => '2026-10-02T09:15:00+00:00'], new: ['settledAt' => '2026-10-02T09:15:00+00:00', 'amount' => 1.0]));
		$listener->handle($this->update(old: [], new: ['state' => 'captured']));
		$listener->handle($this->update(old: [], new: ['state' => 'captured_unapplied']));

		self::assertSame([], $this->transitions);
	}//end testOnlyTheEdgeCounts()

	/**
	 * Another app's contribution, a non-FeeItem subject or no beneficiary grants nothing.
	 *
	 * @return void
	 */
	public function testOtherSubjectsAndMissingBeneficiariesAreIgnored(): void {
		$listener = $this->makeListener();
		$settled = ['settledAt' => '2026-10-02T09:15:00+00:00', 'settledVia' => 'provider'];
		$listener->handle($this->update(old: [], new: $settled, override: ['subject' => ['app' => 'portaliq', 'register' => 'portaliq', 'schema' => 'activityOffer', 'id' => 'fee-1']]));
		$listener->handle($this->update(old: [], new: $settled, override: ['subject' => ['app' => 'learniq', 'register' => 'learniq', 'schema' => 'course', 'id' => 'fee-1']]));
		$listener->handle($this->update(old: [], new: $settled, override: ['beneficiary' => null]));
		$listener->handle($this->update(old: [], new: $settled, override: ['beneficiary' => ['type' => 'learner', 'register' => 'learniq', 'schema' => 'learner-profile', 'id' => 'lp-unknown']]));

		self::assertSame([], $this->transitions);
	}//end testOtherSubjectsAndMissingBeneficiariesAreIgnored()

	/**
	 * The contract's `FeeItem` spelling of the schema is accepted.
	 *
	 * @return void
	 */
	public function testTheContractsSchemaSpellingIsAccepted(): void {
		$this->makeListener()->handle(
			$this->update(
				old: [],
				new: ['settledAt' => '2026-10-02T09:15:00+00:00'],
				override: ['subject' => ['app' => 'learniq', 'register' => 'learniq', 'schema' => 'FeeItem', 'id' => 'fee-1']]
			)
		);

		self::assertSame(['ent-1:grant'], $this->transitions);
	}//end testTheContractsSchemaSpellingIsAccepted()

	/**
	 * A refused grant never throws into shillinq's write.
	 *
	 * @return void
	 */
	public function testARefusedGrantDoesNotThrow(): void {
		$this->transitionFails = true;
		$this->makeListener()->handle($this->update(old: [], new: ['settledAt' => '2026-10-02T09:15:00+00:00']));

		self::assertSame([], $this->transitions);
		self::assertSame('pr-1', $this->entitlement('ent-1')['paymentRequestRef']);
	}//end testARefusedGrantDoesNotThrow()

	/**
	 * The listener is subscribed on ObjectUpdatedEvent, asserted from the caller.
	 *
	 * @return void
	 */
	public function testTheListenerIsRegisteredOnObjectUpdatedEvent(): void {
		(new BootListenerRegistrar())->register(dispatcher: $this->createMock(IEventDispatcher::class), appId: 'learniq');

		$subscribed = ObjectEventSubscription::subscribedListeners();
		self::assertContains(ShillinqContributionSettledListener::class, ($subscribed[ObjectUpdatedEvent::class] ?? []));
	}//end testTheListenerIsRegisteredOnObjectUpdatedEvent()
}//end class
