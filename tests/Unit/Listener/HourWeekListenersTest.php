<?php

/**
 * Learniq internship-hours listener tests.
 *
 * Two listeners stand between a week of hours and the record the school keeps:
 * HourWeekSubmissionStamp says whose week it is, and HourWeekTotalRollup keeps
 * the placement's total equal to the sum of its weeks. Both are asserted over
 * real OpenRegister events and the shipped register, because both of them
 * exist to make a value true that nothing else writes.
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
 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-hours-are-shown-against-the-hours-that-were-agreed
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Listener\HourWeekSubmissionStamp;
use OCA\Learniq\Listener\HourWeekTotalRollup;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the two listeners internship-hours adds.
 */
class HourWeekListenersTest extends TestCase {

	private const TENANT = '11111111-1111-4111-8111-111111111111';

	private const PLACEMENT = 'ee030020-0000-4000-8000-000000000001';

	private const BARE_PLACEMENT = 'ee030020-0000-4000-8000-000000000002';

	private const WEEK = 'ee030040-0000-4000-8000-000000000001';

	/**
	 * The fake OpenRegister store, with the live filter semantics.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * One placement of 640 agreed hours on pupil lp-1, one with no agreed
	 * total, and whatever weeks a test adds.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'bpv-placement' => [
				[
					'id' => self::PLACEMENT,
					'learnerRef' => 'lp-1',
					'practicalTrainerId' => 'po-1',
					'agreedHours' => 640,
					'hoursApprovedTotal' => 0,
					'tenant_id' => self::TENANT,
				],
				[
					'id' => self::BARE_PLACEMENT,
					'learnerRef' => 'lp-2',
					'practicalTrainerId' => 'po-1',
					'tenant_id' => self::TENANT,
				],
			],
			'bpv-hour-week' => [],
		];
	}//end setUp()

	/**
	 * An ObjectService over the store.
	 *
	 * @return ObjectService
	 */
	private function objectService(): ObjectService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			fn (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): object => $this->store->save((string)$schema, $object, ($uuid ?? (string)($object['id'] ?? '')))
		);

		return $objectService;
	}//end objectService()

	/**
	 * The submission stamp, over the store.
	 *
	 * @param string $slug The slug the resolver reports for the entity.
	 *
	 * @return HourWeekSubmissionStamp
	 */
	private function stamp(string $slug = 'bpv-hour-week'): HourWeekSubmissionStamp {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn($slug);

		return new HourWeekSubmissionStamp(
			schemaResolver: $resolver,
			objectService: $this->objectService(),
			logger: new NullLogger()
		);
	}//end stamp()

	/**
	 * The rollup, over the store.
	 *
	 * @param string $slug The slug the resolver reports for the entity.
	 *
	 * @return HourWeekTotalRollup
	 */
	private function rollup(string $slug = 'bpv-hour-week'): HourWeekTotalRollup {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn($slug);

		return new HourWeekTotalRollup(
			schemaResolver: $resolver,
			objectService: $this->objectService(),
			logger: new NullLogger()
		);
	}//end rollup()

	/**
	 * The week the pupil's form sends: the placement, the week and the hours,
	 * and nothing else. Portaliq adds her own `learnerRef` from her claim.
	 *
	 * @param array<string, mixed> $extra Anything beyond the three fields.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function pupilCreate(array $extra=[]): ObjectCreatingEvent {
		return new ObjectCreatingEvent(
			OrEntityFactory::make(
				array_merge(
					[
						'bpvPlacementId' => self::PLACEMENT,
						'isoWeek' => '2026-W39',
						'hoursSubmitted' => 32,
						'learnerRef' => 'lp-1',
					],
					$extra
				),
				'bpv-hour-week'
			)
		);
	}//end pupilCreate()

	/**
	 * The pupil's form sends three fields; the week ends up naming who entered
	 * it, when, which student it is about and which school it belongs to.
	 *
	 * WHY THIS TEST EXISTS. None of the four was written by anything. They are
	 * not in the action's whitelist, correctly, because a client that could
	 * name the student could file hours against somebody else; and no listener
	 * stamped them. `tenant_id` is required by every learniq schema, so every
	 * portal submission would have been refused outright.
	 *
	 * @return void
	 */
	public function testThePupilsWeekLearnsWhoWhenAndWhichSchool(): void {
		$event = $this->pupilCreate();
		$this->stamp()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		$stamped = $event->getModifiedData();
		self::assertSame('lp-1', $stamped['learnerRef']);
		self::assertSame('lp-1', $stamped['submittedBy']);
		self::assertSame(self::TENANT, $stamped['tenant_id']);
		self::assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
			$stamped['submittedAt'],
			'submittedAt must be the date-time the schema declares'
		);
	}//end testThePupilsWeekLearnsWhoWhenAndWhichSchool()

	/**
	 * A learner sent in the body never decides whose week it is: the placement
	 * does. So hours cannot be filed under another student's name even if the
	 * scope stamp were ever bypassed.
	 *
	 * @return void
	 */
	public function testTheStudentComesFromThePlacementNotTheBody(): void {
		$event = $this->pupilCreate(extra: ['learnerRef' => 'lp-99', 'submittedBy' => 'lp-99']);
		$this->stamp()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame('lp-1', $event->getModifiedData()['learnerRef']);
		self::assertSame('lp-1', $event->getModifiedData()['submittedBy']);
	}//end testTheStudentComesFromThePlacementNotTheBody()

	/**
	 * A week whose placement nobody can find is refused, not stored without a
	 * student or a school.
	 *
	 * @return void
	 */
	public function testAWeekWithoutAKnownPlacementIsRefused(): void {
		$event = $this->pupilCreate(extra: ['bpvPlacementId' => 'ee030020-0000-4000-8000-0000000000ff']);
		$this->stamp()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('hour-week-placement-unknown', $event->getErrors()['reason']);

		$empty = $this->pupilCreate(extra: ['bpvPlacementId' => '']);
		$this->stamp()->handle($empty);
		self::assertTrue($empty->isPropagationStopped());
	}//end testAWeekWithoutAKnownPlacementIsRefused()

	/**
	 * A placement that names no school leaves the week unattributable, so the
	 * write is refused rather than stored half-done.
	 *
	 * @return void
	 */
	public function testAPlacementWithoutASchoolRefusesTheWeek(): void {
		$this->store->rows['bpv-placement'][1]['tenant_id'] = '';
		$event = $this->pupilCreate(extra: ['bpvPlacementId' => self::BARE_PLACEMENT]);
		$this->stamp()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('hour-week-owner-missing', $event->getErrors()['reason']);
	}//end testAPlacementWithoutASchoolRefusesTheWeek()

	/**
	 * The trainer's approval is an update, and an update is not a new
	 * submission: the moment the pupil entered it, and that it was she who
	 * did, both stay.
	 *
	 * @return void
	 */
	public function testAnApprovalDoesNotRestampTheSubmission(): void {
		$stored = OrEntityFactory::make(
			[
				'id' => self::WEEK,
				'bpvPlacementId' => self::PLACEMENT,
				'learnerRef' => 'lp-1',
				'isoWeek' => '2026-W39',
				'hoursSubmitted' => 32,
				'submittedBy' => 'lp-1',
				'submittedAt' => '2026-09-28T09:00:00+00:00',
				'tenant_id' => self::TENANT,
			],
			'bpv-hour-week'
		);
		$event = new ObjectUpdatingEvent($stored, $stored);
		$this->stamp()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		$stamp = $event->getModifiedData();
		self::assertArrayNotHasKey('submittedAt', $stamp);
		self::assertArrayNotHasKey('submittedBy', $stamp);
		self::assertSame('lp-1', $stamp['learnerRef']);
	}//end testAnApprovalDoesNotRestampTheSubmission()

	/**
	 * Another app's write is never touched, whatever it carries.
	 *
	 * @return void
	 */
	public function testAnotherSchemasWriteIsLeftAlone(): void {
		$event = $this->pupilCreate();
		$this->stamp(slug: 'excuse-request')->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getModifiedData());
	}//end testAnotherSchemasWriteIsLeftAlone()

	/**
	 * The placement's total is the sum of the approved hours of its weeks, and
	 * a week nobody has approved yet adds nothing.
	 *
	 * @return void
	 */
	public function testTheTotalIsTheSumOfTheApprovedWeeks(): void {
		$this->store->rows['bpv-hour-week'] = [
			['id' => 'w1', 'bpvPlacementId' => self::PLACEMENT, 'hoursSubmitted' => 32, 'hoursApproved' => 30, 'lifecycle' => 'corrected'],
			['id' => 'w2', 'bpvPlacementId' => self::PLACEMENT, 'hoursSubmitted' => 24, 'hoursApproved' => 24, 'lifecycle' => 'approved'],
			// Waiting for the trainer: no approved number, so no contribution.
			['id' => 'w3', 'bpvPlacementId' => self::PLACEMENT, 'hoursSubmitted' => 40, 'lifecycle' => 'submitted'],
			// Another placement's week never counts towards this one.
			['id' => 'w4', 'bpvPlacementId' => self::BARE_PLACEMENT, 'hoursSubmitted' => 8, 'hoursApproved' => 8, 'lifecycle' => 'approved'],
		];

		$week = OrEntityFactory::make($this->store->rows['bpv-hour-week'][0], 'bpv-hour-week');
		$this->rollup()->handle(new ObjectUpdatedEvent($week, $week));

		$placement = $this->placement(id: self::PLACEMENT);
		self::assertSame(54.0, (float)$placement['hoursApprovedTotal']);
		self::assertSame(640, $placement['agreedHours']);
	}//end testTheTotalIsTheSumOfTheApprovedWeeks()

	/**
	 * A placement that agreed no total still gets its sum: the card then shows
	 * the hours and no bar, which is what the change decided.
	 *
	 * @return void
	 */
	public function testAPlacementWithoutAnAgreedTotalStillGetsItsSum(): void {
		$this->store->rows['bpv-hour-week'] = [
			['id' => 'w4', 'bpvPlacementId' => self::BARE_PLACEMENT, 'hoursSubmitted' => 8, 'hoursApproved' => 8, 'lifecycle' => 'approved'],
		];

		$week = OrEntityFactory::make($this->store->rows['bpv-hour-week'][0], 'bpv-hour-week');
		$this->rollup()->handle(new ObjectCreatedEvent($week));

		$placement = $this->placement(id: self::BARE_PLACEMENT);
		self::assertSame(8.0, (float)$placement['hoursApprovedTotal']);
		self::assertArrayNotHasKey('agreedHours', $placement);
	}//end testAPlacementWithoutAnAgreedTotalStillGetsItsSum()

	/**
	 * A total that has not moved is not written again: a write here would
	 * raise another event and walk straight back into this listener.
	 *
	 * @return void
	 */
	public function testAnUnchangedTotalIsNotWrittenAgain(): void {
		$this->store->rows['bpv-placement'][0]['hoursApprovedTotal'] = 30;
		$this->store->rows['bpv-hour-week'] = [
			['id' => 'w1', 'bpvPlacementId' => self::PLACEMENT, 'hoursApproved' => 30, 'lifecycle' => 'approved'],
		];
		$writes = 0;
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) use (&$writes): object {
				$writes++;
				return $this->store->save((string)$schema, $object, ($uuid ?? (string)($object['id'] ?? '')));
			}
		);
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn('bpv-hour-week');

		$week = OrEntityFactory::make($this->store->rows['bpv-hour-week'][0], 'bpv-hour-week');
		(new HourWeekTotalRollup(schemaResolver: $resolver, objectService: $objectService, logger: new NullLogger()))
			->handle(new ObjectUpdatedEvent($week, $week));

		self::assertSame(0, $writes, 'an unchanged total must not be written again');
	}//end testAnUnchangedTotalIsNotWrittenAgain()

	/**
	 * Both listeners are wired from the registrar, on the events they need.
	 * The stamp runs before the write, the rollup after it.
	 *
	 * @return void
	 */
	public function testBothListenersAreRegistered(): void {
		$pairs = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$pairs): void {
				$pairs[] = $event . ' => ' . $listener;
			}
		);

		(new IntegrityListenerRegistrar())->register(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . HourWeekSubmissionStamp::class, $pairs);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . HourWeekSubmissionStamp::class, $pairs);
		self::assertContains(ObjectCreatedEvent::class . ' => ' . HourWeekTotalRollup::class, $pairs);
		self::assertContains(ObjectUpdatedEvent::class . ' => ' . HourWeekTotalRollup::class, $pairs);
	}//end testBothListenersAreRegistered()

	/**
	 * One placement as the store holds it.
	 *
	 * @param string $id The placement uuid.
	 *
	 * @return array<string, mixed>
	 */
	private function placement(string $id): array {
		foreach ($this->store->rows['bpv-placement'] as $row) {
			if (($row['id'] ?? '') === $id) {
				return $row;
			}
		}

		self::fail("placement $id is not in the store");
	}//end placement()
}//end class
