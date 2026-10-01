<?php

/**
 * Learniq LvsResultLearnerRefStamp unit tests.
 *
 * @category Tests
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-every-lvsresult-names-its-pupil-by-learnerprofile-reference
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Listener\LvsResultLearnerRefStamp;
use OCA\Learniq\Service\ExchangeImportLanding;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for LvsResultLearnerRefStamp::handle().
 */
class LvsResultLearnerRefStampTest extends TestCase {

	private const TENANT_A = '00000000-0000-4000-8000-00000000000a';
	private const TENANT_B = '00000000-0000-4000-8000-00000000000b';

	/**
	 * The fake OpenRegister store behind the real resolver.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * The ObjectService double over the store.
	 *
	 * @var ObjectService
	 */
	private ObjectService $objectService;

	/**
	 * Build the listener over a real LearnerRefResolver and the fake store.
	 *
	 * @param string $slug What the schema resolver answers for the entity.
	 *
	 * @return LvsResultLearnerRefStamp
	 */
	private function makeStamp(string $slug = 'lvs-result'): LvsResultLearnerRefStamp {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['learner-profile'] = [
			['id' => 'lp-1', 'ncUserId' => 'pupil-1', 'tenant_id' => self::TENANT_A],
			['id' => 'lp-2', 'ncUserId' => 'pupil-2', 'tenant_id' => self::TENANT_A],
			['id' => 'lp-3', 'ncUserId' => 'pupil-1', 'tenant_id' => self::TENANT_B],
		];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			fn (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) => $this->store->save((string)$schema, $object, $uuid)
		);
		$this->objectService = $objectService;

		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('guardSchemaSlug')->willReturn($slug);

		return new LvsResultLearnerRefStamp(
			schemaResolver: $schemaResolver,
			learnerRefs: new LearnerRefResolver(objectService: $objectService),
			logger: new NullLogger(),
		);
	}//end makeStamp()

	/**
	 * A creating event for an LvsResult.
	 *
	 * @param array<string, mixed> $data Payload.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function creating(array $data): ObjectCreatingEvent {
		return new ObjectCreatingEvent(OrEntityFactory::make($data, 'lvs-result'));
	}//end creating()

	/**
	 * An updating event for an LvsResult.
	 *
	 * @param array<string, mixed> $new New state.
	 * @param array<string, mixed> $old Stored state.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function updating(array $new, array $old): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent(
			OrEntityFactory::make($new, 'lvs-result'),
			OrEntityFactory::make($old, 'lvs-result')
		);
	}//end updating()

	/**
	 * A result created without learnerRef gets it stamped from its tenant.
	 *
	 * @return void
	 */
	public function testACreateGetsTheLearnerRefStamped(): void {
		$event = $this->creating(['learnerId' => 'pupil-1', 'tenant_id' => self::TENANT_A, 'provider' => 'cito']);
		$this->makeStamp()->handle($event);

		self::assertSame('lp-1', $event->getModifiedData()['learnerRef']);
		self::assertFalse($event->isPropagationStopped());
	}//end testACreateGetsTheLearnerRefStamped()

	/**
	 * The profile is the one in the result's own tenant, read without the
	 * session's tenant scoping (the import runs without a session).
	 *
	 * @return void
	 */
	public function testTheProfileComesFromTheResultsTenant(): void {
		$event = $this->creating(['learnerId' => 'pupil-1', 'tenant_id' => self::TENANT_B]);
		$this->makeStamp()->handle($event);

		self::assertSame('lp-3', $event->getModifiedData()['learnerRef']);
		self::assertFalse($this->store->reads[0]['multitenancy']);
		self::assertFalse($this->store->reads[0]['rbac']);
	}//end testTheProfileComesFromTheResultsTenant()

	/**
	 * A result without a tenant falls back to the session's tenant scoping.
	 *
	 * @return void
	 */
	public function testAResultWithoutATenantUsesTheSession(): void {
		$event = $this->creating(['learnerId' => 'pupil-2']);
		$this->makeStamp()->handle($event);

		self::assertSame('lp-2', $event->getModifiedData()['learnerRef']);
		self::assertTrue($this->store->reads[0]['multitenancy']);
	}//end testAResultWithoutATenantUsesTheSession()

	/**
	 * A forged learnerRef is replaced by the derived one.
	 *
	 * @return void
	 */
	public function testAForgedLearnerRefIsReplaced(): void {
		$event = $this->creating(['learnerId' => 'pupil-1', 'tenant_id' => self::TENANT_A, 'learnerRef' => 'lp-2']);
		$this->makeStamp()->handle($event);

		self::assertSame('lp-1', $event->getModifiedData()['learnerRef']);
	}//end testAForgedLearnerRefIsReplaced()

	/**
	 * A learner without a profile gets null, and the write goes through.
	 *
	 * @return void
	 */
	public function testALearnerWithoutAProfileGetsNull(): void {
		$event = $this->creating(['learnerId' => 'pupil-9', 'tenant_id' => self::TENANT_A, 'learnerRef' => 'lp-2']);
		$this->makeStamp()->handle($event);

		self::assertArrayHasKey('learnerRef', $event->getModifiedData());
		self::assertNull($event->getModifiedData()['learnerRef']);
		self::assertFalse($event->isPropagationStopped());
	}//end testALearnerWithoutAProfileGetsNull()

	/**
	 * An update re-derives the value from the new state.
	 *
	 * @return void
	 */
	public function testAnUpdateReDerivesFromTheNewLearner(): void {
		$event = $this->updating(
			['id' => 'lvs-1', 'learnerId' => 'pupil-2', 'tenant_id' => self::TENANT_A, 'learnerRef' => 'lp-1'],
			['id' => 'lvs-1', 'learnerId' => 'pupil-1', 'tenant_id' => self::TENANT_A, 'learnerRef' => 'lp-1']
		);
		$this->makeStamp()->handle($event);

		self::assertSame('lp-2', $event->getModifiedData()['learnerRef']);
	}//end testAnUpdateReDerivesFromTheNewLearner()

	/**
	 * A failed lookup on update keeps the stored learnerRef.
	 *
	 * @return void
	 */
	public function testAFailedLookupOnUpdateKeepsTheStoredValue(): void {
		$stamp = $this->makeStamp();
		$this->store->failReads = 'database gone';
		$event = $this->updating(
			['id' => 'lvs-1', 'learnerId' => 'pupil-1', 'tenant_id' => self::TENANT_A, 'learnerRef' => 'lp-2'],
			['id' => 'lvs-1', 'learnerId' => 'pupil-1', 'tenant_id' => self::TENANT_A, 'learnerRef' => 'lp-1']
		);
		$stamp->handle($event);

		self::assertSame('lp-1', $event->getModifiedData()['learnerRef']);
		self::assertFalse($event->isPropagationStopped());
	}//end testAFailedLookupOnUpdateKeepsTheStoredValue()

	/**
	 * A failed lookup on an update that moves the result to another learner
	 * fails closed.
	 *
	 * @return void
	 */
	public function testAFailedLookupOnAMoveFailsClosed(): void {
		$stamp = $this->makeStamp();
		$this->store->failReads = 'database gone';
		$event = $this->updating(
			['id' => 'lvs-1', 'learnerId' => 'pupil-2', 'tenant_id' => self::TENANT_A],
			['id' => 'lvs-1', 'learnerId' => 'pupil-1', 'tenant_id' => self::TENANT_A, 'learnerRef' => 'lp-1']
		);
		$stamp->handle($event);

		self::assertNull($event->getModifiedData()['learnerRef']);
	}//end testAFailedLookupOnAMoveFailsClosed()

	/**
	 * Another schema's write is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsUntouched(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['learnerId' => 'pupil-1'], 'grade-entry'));
		$this->makeStamp(slug: 'grade-entry')->handle($event);

		self::assertSame([], $event->getModifiedData());
		self::assertSame([], $this->store->reads);
	}//end testAnotherSchemaIsUntouched()

	/**
	 * A write another listener already refused is left alone.
	 *
	 * @return void
	 */
	public function testARefusedWriteIsUntouched(): void {
		$event = $this->creating(['learnerId' => 'pupil-1', 'tenant_id' => self::TENANT_A]);
		$event->stopPropagation();
		$this->makeStamp()->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testARefusedWriteIsUntouched()

	/**
	 * The row the import landing writes for an integriq `lvs-results` job,
	 * put through the real creating event, comes out linked to its pupil.
	 * The landing itself never takes a learnerRef from the delivered file.
	 *
	 * @return void
	 */
	public function testAnImportedResultIsLinkedToItsPupil(): void {
		$stamp = $this->makeStamp();
		$landing = new ExchangeImportLanding(objectService: $this->objectService, time: $this->createMock(ITimeFactory::class));

		$outcome = $landing->land(
			target: 'lvs-results',
			jobId: 'job-1',
			scope: ['tenantId' => self::TENANT_B],
			records: [[
				'recordId' => 'r-1',
				'sourceKind' => 'uwlr',
				'data' => ['provider' => 'cito', 'instrument' => 'Rekenen-Wiskunde', 'moment' => 'M5', 'learnerId' => 'pupil-1', 'learnerRef' => 'lp-2'],
			]]
		);

		self::assertSame(1, $outcome['accepted']);
		$landed = $this->store->saves[0]['object'];
		self::assertSame('lvs-result', $this->store->saves[0]['schema']);
		self::assertArrayNotHasKey('learnerRef', $landed);

		$event = new ObjectCreatingEvent(OrEntityFactory::make($landed, 'lvs-result'));
		$stamp->handle($event);

		self::assertSame('lp-3', $event->getModifiedData()['learnerRef']);
	}//end testAnImportedResultIsLinkedToItsPupil()

	/**
	 * The stamp is wired on both create and update, asserted from the caller.
	 *
	 * @return void
	 */
	public function testTheStampIsRegisteredForCreateAndUpdate(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = $event . ' => ' . $listener;
			}
		);

		(new IntegrityListenerRegistrar())->register(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . LvsResultLearnerRefStamp::class, $registered);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . LvsResultLearnerRefStamp::class, $registered);
	}//end testTheStampIsRegisteredForCreateAndUpdate()
}//end class
