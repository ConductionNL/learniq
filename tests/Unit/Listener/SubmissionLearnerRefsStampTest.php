<?php

/**
 * Tests for SubmissionLearnerRefsStamp.
 *
 * The listener runs over a real LearnerRefResolver and an OpenRegister-faithful
 * store, so a lookup on the wrong property finds nothing, the way it does live.
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
 * @spec openspec/specs/assignments/spec.md#requirement-every-submission-carries-server-stamped-learnerrefs
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Listener\SubmissionLearnerRefsStamp;
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
 * Tests for SubmissionLearnerRefsStamp::handle().
 */
class SubmissionLearnerRefsStampTest extends TestCase {

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
	 * @return SubmissionLearnerRefsStamp
	 */
	private function makeStamp(string $slug = 'submission'): SubmissionLearnerRefsStamp {
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

		return new SubmissionLearnerRefsStamp(
			schemaResolver: $schemaResolver,
			learnerRefs: new LearnerRefResolver(objectService: $objectService),
			logger: new NullLogger(),
		);
	}//end makeStamp()

	/**
	 * A creating event for a Submission.
	 *
	 * @param array<string, mixed> $data Payload.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function creating(array $data): ObjectCreatingEvent {
		return new ObjectCreatingEvent(OrEntityFactory::make($data, 'submission'));
	}//end creating()

	/**
	 * An updating event for a Submission.
	 *
	 * @param array<string, mixed> $new New state.
	 * @param array<string, mixed> $old Stored state.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function updating(array $new, array $old): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent(
			OrEntityFactory::make($new, 'submission'),
			OrEntityFactory::make($old, 'submission')
		);
	}//end updating()

	/**
	 * A solo submission gets its learner's profile UUID.
	 *
	 * @return void
	 */
	public function testASoloSubmissionGetsTheLearnersProfileStamped(): void {
		$event = $this->creating(['assignmentId' => 'as-1', 'learnerIds' => ['pupil-1']]);
		$this->makeStamp()->handle($event);

		self::assertSame(['lp-1'], $event->getModifiedData()['learnerRefs']);
		self::assertFalse($event->isPropagationStopped());
		self::assertFalse($this->store->reads[0]['rbac']);
	}//end testASoloSubmissionGetsTheLearnersProfileStamped()

	/**
	 * A group submission gets one ref per learner that has a profile.
	 *
	 * @return void
	 */
	public function testAGroupSubmissionGetsARefPerLearnerWithAProfile(): void {
		$event = $this->creating(['assignmentId' => 'as-1', 'learnerIds' => ['pupil-1', 'pupil-9', 'pupil-2', 'pupil-1']]);
		$this->makeStamp()->handle($event);

		self::assertSame(['lp-1', 'lp-2'], $event->getModifiedData()['learnerRefs']);
	}//end testAGroupSubmissionGetsARefPerLearnerWithAProfile()

	/**
	 * learnerRefs sent by the client is replaced by the derived list.
	 *
	 * @return void
	 */
	public function testForgedLearnerRefsAreReplaced(): void {
		$event = $this->creating(['learnerIds' => ['pupil-1'], 'learnerRefs' => ['lp-2']]);
		$this->makeStamp()->handle($event);

		self::assertSame(['lp-1'], $event->getModifiedData()['learnerRefs']);
	}//end testForgedLearnerRefsAreReplaced()

	/**
	 * A learner without a profile adds nothing, so the submission stays out of the portal.
	 *
	 * @return void
	 */
	public function testALearnerWithoutAProfileStaysOutOfThePortal(): void {
		$event = $this->creating(['learnerIds' => ['pupil-9'], 'learnerRefs' => ['lp-2']]);
		$this->makeStamp()->handle($event);

		self::assertSame([], $event->getModifiedData()['learnerRefs']);
		self::assertFalse($event->isPropagationStopped());
	}//end testALearnerWithoutAProfileStaysOutOfThePortal()

	/**
	 * An update that changes the learners re-derives the list.
	 *
	 * @return void
	 */
	public function testAnUpdateReDerivesFromTheNewLearners(): void {
		$event = $this->updating(
			['id' => 'sub-1', 'learnerIds' => ['pupil-2'], 'learnerRefs' => ['lp-1']],
			['id' => 'sub-1', 'learnerIds' => ['pupil-1'], 'learnerRefs' => ['lp-1']]
		);
		$this->makeStamp()->handle($event);

		self::assertSame(['lp-2'], $event->getModifiedData()['learnerRefs']);
	}//end testAnUpdateReDerivesFromTheNewLearners()

	/**
	 * A failed lookup on an update with the same learners keeps the stored list.
	 *
	 * @return void
	 */
	public function testAFailedLookupOnUpdateKeepsTheStoredList(): void {
		$stamp = $this->makeStamp();
		$this->store->failReads = 'database gone';
		$event = $this->updating(
			['id' => 'sub-1', 'learnerIds' => ['pupil-1'], 'learnerRefs' => ['lp-2']],
			['id' => 'sub-1', 'learnerIds' => ['pupil-1'], 'learnerRefs' => ['lp-1']]
		);
		$stamp->handle($event);

		self::assertSame(['lp-1'], $event->getModifiedData()['learnerRefs']);
		self::assertFalse($event->isPropagationStopped());
	}//end testAFailedLookupOnUpdateKeepsTheStoredList()

	/**
	 * A failed lookup on an update that changes the learners fails closed.
	 *
	 * @return void
	 */
	public function testAFailedLookupOnAChangeOfLearnersFailsClosed(): void {
		$stamp = $this->makeStamp();
		$this->store->failReads = 'database gone';
		$event = $this->updating(
			['id' => 'sub-1', 'learnerIds' => ['pupil-2']],
			['id' => 'sub-1', 'learnerIds' => ['pupil-1'], 'learnerRefs' => ['lp-1']]
		);
		$stamp->handle($event);

		self::assertSame([], $event->getModifiedData()['learnerRefs']);
	}//end testAFailedLookupOnAChangeOfLearnersFailsClosed()

	/**
	 * A failed lookup on create stamps an empty list and never blocks the write.
	 *
	 * @return void
	 */
	public function testAFailedLookupOnCreateStampsAnEmptyList(): void {
		$stamp = $this->makeStamp();
		$this->store->failReads = 'database gone';
		$event = $this->creating(['learnerIds' => ['pupil-1'], 'learnerRefs' => ['lp-2']]);
		$stamp->handle($event);

		self::assertSame([], $event->getModifiedData()['learnerRefs']);
		self::assertFalse($event->isPropagationStopped());
	}//end testAFailedLookupOnCreateStampsAnEmptyList()

	/**
	 * Another schema's write is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsUntouched(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['learnerIds' => ['pupil-1']], 'assignment'));
		$this->makeStamp(slug: 'assignment')->handle($event);

		self::assertSame([], $event->getModifiedData());
		self::assertSame([], $this->store->reads);
	}//end testAnotherSchemaIsUntouched()

	/**
	 * A write another listener already refused is left alone.
	 *
	 * @return void
	 */
	public function testARefusedWriteIsUntouched(): void {
		$event = $this->creating(['learnerIds' => ['pupil-1']]);
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

		self::assertContains(ObjectCreatingEvent::class . ' => ' . SubmissionLearnerRefsStamp::class, $registered);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . SubmissionLearnerRefsStamp::class, $registered);
	}//end testTheStampIsRegisteredForCreateAndUpdate()
}//end class
