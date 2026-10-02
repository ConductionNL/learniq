<?php

/**
 * ConferenceFreeSlotGenerator test.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use DateTimeZone;
use OCA\Learniq\AppInfo\Registrar\CollaborationListenerRegistrar;
use OCA\Learniq\Listener\ConferenceFreeSlotGenerator;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IDateTimeZone;
use OCP\IUserManager;
use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A teacher's availability becomes free times parents can book, once.
 */
class ConferenceFreeSlotGeneratorTest extends TestCase {

	use RegisterSchemaPayloads;

	private const TENANT = '00000000-0000-4000-8000-000000000000';

	private const ROUND_1 = 'aa000001-0000-4000-8000-000000000001';
	private const VERA = 'aa000004-0000-4000-8000-000000000001';
	private const DAAN = 'aa000004-0000-4000-8000-000000000002';
	private const SEM = 'aa000004-0000-4000-8000-000000000003';
	private const GROEP_7 = 'aa000006-0000-4000-8000-000000000007';
	private const GROEP_8 = 'aa000006-0000-4000-8000-000000000008';
	private const HELD = 'aa000002-0000-4000-8000-000000000009';
	private const GONE = 'aa000002-0000-4000-8000-000000000010';

	private RegisterFaithfulStore $store;

	/**
	 * Group 7 (Vera, Daan) with its teacher, group 8 (Sem) with another, a
	 * direct round for both, and one hour of availability for group 7's teacher.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['learner-profile'] = [
			['id' => self::VERA, 'ncUserId' => 'po-leerling-147', 'tenant_id' => self::TENANT],
			['id' => self::DAAN, 'ncUserId' => 'po-leerling-143', 'tenant_id' => self::TENANT],
			['id' => self::SEM, 'ncUserId' => 'po-leerling-180', 'tenant_id' => self::TENANT],
		];
		$this->store->rows['cohort'] = [
			['id' => self::GROEP_7, 'teacherIds' => ['po-leerkracht-09'], 'learnerIds' => ['po-leerling-147', 'po-leerling-143']],
			['id' => self::GROEP_8, 'teacherIds' => ['po-leerkracht-10'], 'learnerIds' => ['po-leerling-180']],
		];
		$this->store->rows['teacher-availability'] = [
			[
				'id' => 'avail-1',
				'conferenceRoundId' => self::ROUND_1,
				'teacherId' => 'po-leerkracht-09',
				'blocks' => [['startsAt' => '2026-10-08T18:00:00+02:00', 'endsAt' => '2026-10-08T18:36:00+02:00']],
				'lifecycle' => 'submitted',
				'tenant_id' => self::TENANT,
			],
			[
				'id' => 'avail-draft',
				'conferenceRoundId' => self::ROUND_1,
				'teacherId' => 'po-leerkracht-10',
				'blocks' => [['startsAt' => '2026-10-08T18:00:00+02:00', 'endsAt' => '2026-10-08T19:00:00+02:00']],
				'lifecycle' => 'draft',
				'tenant_id' => self::TENANT,
			],
		];
		$this->store->rows['conference-slot'] = [];
	}//end setUp()

	/**
	 * Opening booking in a direct round cuts the submitted availability into
	 * free slots of the round's length and buffer, bookable by the invited
	 * pupils of the teacher's own group, with the teacher's name and a label
	 * in the school's time zone. A draft availability gives nothing.
	 *
	 * @return void
	 */
	public function testOpeningBookingWritesTheFreeTimesOfTheTeachersGroup(): void {
		$this->generator()->handle($this->opened(round: $this->round()));

		$slots = $this->store->rows['conference-slot'];
		$this->assertSame(['2026-10-08T18:00:00+02:00', '2026-10-08T18:12:00+02:00', '2026-10-08T18:24:00+02:00'], array_column($slots, 'startsAt'));
		$this->assertSame('2026-10-08T18:10:00+02:00', $slots[0]['endsAt']);
		foreach ($slots as $slot) {
			$this->assertSame('free', $slot['lifecycle']);
			$this->assertSame('po-leerkracht-09', $slot['teacherId']);
			$this->assertSame('Anna de Vries', $slot['teacherName']);
			$this->assertSame([self::VERA, self::DAAN], $slot['eligibleLearnerRefs']);
			$this->assertSame(self::ROUND_1, $slot['conferenceRoundId']);
			$this->assertSame(self::TENANT, $slot['tenant_id']);
			$this->assertArrayNotHasKey('learnerId', $slot);
		}

		$this->assertSame('08-10-2026 18:00-18:10, Anna de Vries', $slots[0]['slotLabel']);

		foreach ($this->store->saves as $save) {
			$this->assertNull(self::schemaError($save['schema'], $save['object']), 'the real conference-slot fragment accepts a free slot');
		}
	}//end testOpeningBookingWritesTheFreeTimesOfTheTeachersGroup()

	/**
	 * Running it again (`create-free-slots`) adds nothing that exists, and a
	 * time a booked slot already holds is skipped. A declined time does not
	 * hold the teacher.
	 *
	 * @return void
	 */
	public function testRunningAgainAddsOnlyWhatIsMissing(): void {
		$this->store->rows['conference-slot'] = [
			['id' => self::HELD, 'conferenceRoundId' => self::ROUND_1, 'teacherId' => 'po-leerkracht-09', 'startsAt' => '2026-10-08T18:12:00+02:00', 'endsAt' => '2026-10-08T18:22:00+02:00', 'lifecycle' => 'booked'],
			['id' => self::GONE, 'conferenceRoundId' => self::ROUND_1, 'teacherId' => 'po-leerkracht-09', 'startsAt' => '2026-10-08T18:24:00+02:00', 'endsAt' => '2026-10-08T18:34:00+02:00', 'lifecycle' => 'declined'],
		];

		$generator = $this->generator();
		$generator->handle($this->opened(round: $this->round(), action: 'create-free-slots', from: 'booking-open'));
		$this->assertCount(2, $this->store->saves, 'the 18:00 and the 18:24 time, not the booked 18:12');

		$generator->handle($this->opened(round: $this->round(), action: 'create-free-slots', from: 'booking-open'));
		$this->assertCount(2, $this->store->saves, 'a second run writes nothing');
	}//end testRunningAgainAddsOnlyWhatIsMissing()

	/**
	 * A teacher who teaches none of the round's groups offers times to every
	 * invited pupil.
	 *
	 * @return void
	 */
	public function testATeacherWithoutAGroupInTheRoundOffersEveryInvitedPupil(): void {
		$this->store->rows['cohort'][0]['teacherIds'] = ['someone-else'];

		$this->generator()->handle($this->opened(round: $this->round()));

		$this->assertSame([self::VERA, self::DAAN, self::SEM], $this->store->rows['conference-slot'][0]['eligibleLearnerRefs']);
	}//end testATeacherWithoutAGroupInTheRoundOffersEveryInvitedPupil()

	/**
	 * A round where the school plans the times gets no free slots.
	 *
	 * @return void
	 */
	public function testAPreferenceRoundGetsNoFreeTimes(): void {
		$round = $this->round();
		$round['bookingMode'] = 'preference';
		$this->generator()->handle($this->opened(round: $round));

		$withoutMode = $this->round();
		unset($withoutMode['bookingMode']);
		$this->generator()->handle($this->opened(round: $withoutMode));

		$this->assertSame([], $this->store->saves);
	}//end testAPreferenceRoundGetsNoFreeTimes()

	/**
	 * The listener is wired on transitions, from the registrar the app runs.
	 *
	 * @return void
	 */
	public function testTheRegistrarWiresTheListener(): void {
		$wired = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$wired): void {
				$wired[] = [$event, $listener];
			}
		);

		(new CollaborationListenerRegistrar())->register($context);

		$this->assertContains([ObjectTransitionedEvent::class, ConferenceFreeSlotGenerator::class], $wired);
	}//end testTheRegistrarWiresTheListener()

	/**
	 * The direct round for groups 7 and 8.
	 *
	 * @return array<string, mixed>
	 */
	private function round(): array {
		return [
			'id' => self::ROUND_1,
			'bookingMode' => 'direct',
			'lifecycle' => 'booking-open',
			'cohortIds' => [self::GROEP_7, self::GROEP_8],
			'invitedLearnerRefs' => [self::VERA, self::DAAN, self::SEM],
			'slotDurationMinutes' => 10,
			'bufferMinutes' => 2,
			'tenant_id' => self::TENANT,
		];
	}//end round()

	/**
	 * The round's transition event into `booking-open`.
	 *
	 * @param array<string, mixed> $round The round.
	 * @param string $action The transition.
	 * @param string $from The state before.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function opened(array $round, string $action='open-booking', string $from='invitations-sent'): ObjectTransitionedEvent {
		return new ObjectTransitionedEvent(
			OrEntityFactory::make($round, 'conference-round'),
			$action,
			$from,
			'booking-open',
			'po-leerkracht-09',
			'learniq',
			'conference-round'
		);
	}//end opened()

	/**
	 * The generator over the in-memory register.
	 *
	 * @return ConferenceFreeSlotGenerator
	 */
	private function generator(): ConferenceFreeSlotGenerator {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) {
				foreach (($this->store->rows[(string)$schema] ?? []) as $row) {
					if ($row['id'] === $id) {
						return OrEntityFactory::make($row, (string)$schema);
					}
				}

				return null;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			fn (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) => $this->store->save((string)$schema, $object, $uuid)
		);

		$schemas = $this->createMock(ListenerSchemaResolver::class);
		$schemas->method('eventRegister')->willReturnCallback(static fn (ObjectTransitionedEvent $event): string => $event->getRegister());
		$schemas->method('eventSchema')->willReturnCallback(static fn (ObjectTransitionedEvent $event): string => $event->getSchema());

		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(static fn (string $uid): ?string => ($uid === 'po-leerkracht-09' ? 'Anna de Vries' : null));

		$timeZone = $this->createMock(IDateTimeZone::class);
		$timeZone->method('getDefaultTimeZone')->willReturn(new DateTimeZone('Europe/Amsterdam'));

		return new ConferenceFreeSlotGenerator(
			$objectService,
			$schemas,
			new LearnerRefResolver(objectService: $objectService),
			$users,
			$timeZone,
			new NullLogger()
		);
	}//end generator()
}//end class
