<?php

/**
 * Learniq GradeEntryLearnerRefStamp unit tests.
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
 * @spec openspec/changes/gradeentry-learnerref-stamp/specs/grading/spec.md#requirement-every-gradeentry-carries-a-server-stamped-learnerref
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Listener\GradeEntryLearnerRefStamp;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for GradeEntryLearnerRefStamp::handle().
 */
class GradeEntryLearnerRefStampTest extends TestCase {

	/**
	 * The fake OpenRegister store behind the real resolver.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * Build the listener over a real LearnerRefResolver and the fake store.
	 *
	 * @param string $slug What the schema resolver answers for the entity.
	 *
	 * @return GradeEntryLearnerRefStamp
	 */
	private function makeStamp(string $slug = 'grade-entry'): GradeEntryLearnerRefStamp {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['learner-profile'] = [
			['id' => 'lp-1', 'ncUserId' => 'pupil-1'],
			['id' => 'lp-2', 'ncUserId' => 'pupil-2'],
		];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);

		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('guardSchemaSlug')->willReturn($slug);

		return new GradeEntryLearnerRefStamp(
			schemaResolver: $schemaResolver,
			learnerRefs: new LearnerRefResolver(objectService: $objectService),
			logger: new NullLogger(),
		);
	}//end makeStamp()

	/**
	 * A creating event for a GradeEntry.
	 *
	 * @param array<string, mixed> $data Payload.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function creating(array $data): ObjectCreatingEvent {
		return new ObjectCreatingEvent(OrEntityFactory::make($data, 'grade-entry'));
	}//end creating()

	/**
	 * An updating event for a GradeEntry.
	 *
	 * @param array<string, mixed> $new New state.
	 * @param array<string, mixed> $old Stored state.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function updating(array $new, array $old): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent(
			OrEntityFactory::make($new, 'grade-entry'),
			OrEntityFactory::make($old, 'grade-entry')
		);
	}//end updating()

	/**
	 * A grade created without learnerRef gets it stamped.
	 *
	 * @return void
	 */
	public function testACreateGetsTheLearnerRefStamped(): void {
		$event = $this->creating(['learnerId' => 'pupil-1', 'value' => 7.5]);
		$this->makeStamp()->handle($event);

		self::assertSame('lp-1', $event->getModifiedData()['learnerRef']);
		self::assertFalse($event->isPropagationStopped());
	}//end testACreateGetsTheLearnerRefStamped()

	/**
	 * A forged learnerRef is replaced by the derived one.
	 *
	 * @return void
	 */
	public function testAForgedLearnerRefIsReplaced(): void {
		$event = $this->creating(['learnerId' => 'pupil-1', 'learnerRef' => 'lp-2']);
		$this->makeStamp()->handle($event);

		self::assertSame('lp-1', $event->getModifiedData()['learnerRef']);
	}//end testAForgedLearnerRefIsReplaced()

	/**
	 * A learner without a profile gets null, and the write goes through.
	 *
	 * @return void
	 */
	public function testALearnerWithoutAProfileStaysOutOfThePortal(): void {
		$event = $this->creating(['learnerId' => 'pupil-9', 'learnerRef' => 'lp-2']);
		$this->makeStamp()->handle($event);

		self::assertArrayHasKey('learnerRef', $event->getModifiedData());
		self::assertNull($event->getModifiedData()['learnerRef']);
		self::assertFalse($event->isPropagationStopped());
	}//end testALearnerWithoutAProfileStaysOutOfThePortal()

	/**
	 * Data another listener already set is kept, and its learnerId wins.
	 *
	 * @return void
	 */
	public function testEarlierModifiedDataIsMergedNotReplaced(): void {
		$event = $this->creating(['learnerId' => 'pupil-9']);
		$event->setModifiedData(['learnerId' => 'pupil-2', 'comment' => 'kept']);
		$this->makeStamp()->handle($event);

		self::assertSame('kept', $event->getModifiedData()['comment']);
		self::assertSame('lp-2', $event->getModifiedData()['learnerRef']);
	}//end testEarlierModifiedDataIsMergedNotReplaced()

	/**
	 * An update re-derives the value from the new state.
	 *
	 * @return void
	 */
	public function testAnUpdateReDerivesFromTheNewLearner(): void {
		$event = $this->updating(
			['id' => 'ge-1', 'learnerId' => 'pupil-2', 'learnerRef' => 'lp-1'],
			['id' => 'ge-1', 'learnerId' => 'pupil-1', 'learnerRef' => 'lp-1']
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
			['id' => 'ge-1', 'learnerId' => 'pupil-1', 'learnerRef' => 'lp-2'],
			['id' => 'ge-1', 'learnerId' => 'pupil-1', 'learnerRef' => 'lp-1']
		);
		$stamp->handle($event);

		self::assertSame('lp-1', $event->getModifiedData()['learnerRef']);
		self::assertFalse($event->isPropagationStopped());
	}//end testAFailedLookupOnUpdateKeepsTheStoredValue()

	/**
	 * A failed lookup on an update that moves the grade to another learner
	 * fails closed: the old value names the wrong profile.
	 *
	 * @return void
	 */
	public function testAFailedLookupOnAMoveFailsClosed(): void {
		$stamp = $this->makeStamp();
		$this->store->failReads = 'database gone';
		$event = $this->updating(
			['id' => 'ge-1', 'learnerId' => 'pupil-2'],
			['id' => 'ge-1', 'learnerId' => 'pupil-1', 'learnerRef' => 'lp-1']
		);
		$stamp->handle($event);

		self::assertNull($event->getModifiedData()['learnerRef']);
	}//end testAFailedLookupOnAMoveFailsClosed()

	/**
	 * A failed lookup on create stamps null and never blocks the write.
	 *
	 * @return void
	 */
	public function testAFailedLookupOnCreateStampsNull(): void {
		$stamp = $this->makeStamp();
		$this->store->failReads = 'database gone';
		$event = $this->creating(['learnerId' => 'pupil-1', 'learnerRef' => 'lp-2']);
		$stamp->handle($event);

		self::assertNull($event->getModifiedData()['learnerRef']);
		self::assertFalse($event->isPropagationStopped());
	}//end testAFailedLookupOnCreateStampsNull()

	/**
	 * Another schema's write is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsUntouched(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['learnerId' => 'pupil-1'], 'final-grade'));
		$this->makeStamp(slug: 'final-grade')->handle($event);

		self::assertSame([], $event->getModifiedData());
		self::assertSame([], $this->store->reads);
	}//end testAnotherSchemaIsUntouched()

	/**
	 * A write another listener already refused is left alone.
	 *
	 * @return void
	 */
	public function testARefusedWriteIsUntouched(): void {
		$event = $this->creating(['learnerId' => 'pupil-1']);
		$event->stopPropagation();
		$this->makeStamp()->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testARefusedWriteIsUntouched()

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

		self::assertContains(ObjectCreatingEvent::class . ' => ' . GradeEntryLearnerRefStamp::class, $registered);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . GradeEntryLearnerRefStamp::class, $registered);
	}//end testTheStampIsRegisteredForCreateAndUpdate()
}//end class
