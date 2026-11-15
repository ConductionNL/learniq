<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use DateTimeZone;
use OCA\Learniq\Listener\ConferenceFreeSlotGenerator;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\Tests\Support\GoldenFile;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Lifecycle\TransitionActor;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use OCP\EventDispatcher\Event;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IDateTimeZone;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins which free conference slots handle() writes, for whom, and which
 * pupils' parents may book each, against golden/ConferenceFreeSlot.json:
 * per round every write in order, every learner looked up (with its
 * tenant) and what was logged. A pupil counts only when the teacher
 * teaches their group and the round invited them.
 *
 * @covers \OCA\Learniq\Listener\ConferenceFreeSlotGenerator
 * @uses   \OCA\Learniq\Service\ConferenceBookingMode
 * @uses   \OCA\Learniq\Service\ConferenceSlotSlicer
 * @uses   \OCA\Learniq\Service\ConferenceSlotPattern
 */
class ConferenceFreeSlotGoldenTest extends TestCase {
	use GoldenFile;

	private const GOLDEN = __DIR__ . '/golden/ConferenceFreeSlot.json';

	/**
	 * Calls on the collaborators, in order.
	 *
	 * @var list<mixed>
	 */
	private array $calls = [];

	/**
	 * Every event writes the slots that were recorded.
	 *
	 * @return void
	 */
	public function testHandleMatchesGolden(): void {
		$round = [
			'id' => 'round-1', 'bookingMode' => 'direct', 'tenant_id' => 't-1', 'slotDurationMinutes' => 15, 'bufferMinutes' => 5,
			'cohortIds' => ['c-1', 'c-2', 'c-missing', ''], 'invitedLearnerRefs' => ['ref-anna', 'ref-bert', 'ref-dirk', 7],
		];
		$events = [
			'direct round opens' => [$round, 'booking-open', 'learniq', 'conference-round'],
			'default duration, no buffer' => [['id' => 'round-2', 'bookingMode' => 'direct', 'cohortIds' => 'c-1', 'invitedLearnerRefs' => ['ref-anna']], 'booking-open', 'learniq', 'conference-round'],
			'preference round' => [['bookingMode' => 'preference'] + $round, 'booking-open', 'learniq', 'conference-round'],
			'another state' => [$round, 'closed', 'learniq', 'conference-round'],
			'invitations sent' => [$round, 'invitations-sent', 'learniq', 'conference-round'],
			'another schema' => [$round, 'booking-open', 'learniq', 'conference-slot'],
			'another register' => [$round, 'booking-open', 'other', 'conference-round'],
			'round without id' => [['id' => ''] + $round, 'booking-open', 'learniq', 'conference-round'],
			'write fails' => [['id' => 'round-fail'] + $round, 'booking-open', 'learniq', 'conference-round'],
		];

		$actual = [];
		foreach ($events as $name => [$data, $to, $register, $schema]) {
			$this->calls = [];
			$logger = new CapturingLogger();
			$this->generator(logger: $logger)->handle(event: new ObjectTransitionedEvent(object: OrEntityFactory::make($data, 'conference-round'), move: new TransitionMove(action: 'open-booking', from: 'invitations-sent', to: $to), scope: new RegisterSchemaRef(register: $register, schema: $schema), actor: new TransitionActor(userId: 'planner')));
			$actual[$name] = ['calls' => $this->calls, 'log' => array_map(static fn (array $r): array => [$r['level'], $r['message'], $r['context']], $logger->records)];
		}

		$this->calls = [];
		$this->generator(logger: new CapturingLogger())->handle(event: new Event());
		$actual['not a transition'] = $this->calls;

		$this->assertMatchesGolden(file: self::GOLDEN, actual: $actual);
	}//end testHandleMatchesGolden()

	/**
	 * The generator over fixed availability, slots, groups and pupils.
	 *
	 * @param CapturingLogger $logger The logger.
	 *
	 * @return ConferenceFreeSlotGenerator
	 */
	private function generator(CapturingLogger $logger): ConferenceFreeSlotGenerator {
		$block = static fn (string $from, string $to): array => ['startsAt' => $from, 'endsAt' => $to];
		$store = [
			'teacher-availability' => [
				['conferenceRoundId' => 'round-1', 'lifecycle' => 'submitted', 'teacherId' => 'jan', 'blocks' => [$block('2026-10-08T18:00:00+02:00', '2026-10-08T19:00:00+02:00')]],
				['conferenceRoundId' => 'round-1', 'lifecycle' => 'locked', 'teacherId' => 'jan', 'blocks' => [$block('2026-10-08T18:30:00+02:00', '2026-10-08T19:10:00+02:00')]],
				['conferenceRoundId' => 'round-1', 'lifecycle' => 'submitted', 'teacherId' => 'piet', 'blocks' => [$block('2026-10-08T16:00:00Z', '2026-10-08T16:40:00Z')]],
				['conferenceRoundId' => 'round-1', 'lifecycle' => 'submitted', 'teacherId' => 'kees', 'blocks' => [$block('2026-10-09T09:00:00+02:00', '2026-10-09T09:20:00+02:00')]],
				['conferenceRoundId' => 'round-1', 'lifecycle' => 'submitted', 'teacherId' => '', 'blocks' => [$block('2026-10-09T09:00:00+02:00', '2026-10-09T10:00:00+02:00')]],
				['conferenceRoundId' => 'round-1', 'lifecycle' => 'draft', 'teacherId' => 'jan', 'blocks' => [$block('2026-10-10T09:00:00+02:00', '2026-10-10T10:00:00+02:00')]],
				['conferenceRoundId' => 'round-2', 'lifecycle' => 'submitted', 'teacherId' => 'jan', 'blocks' => [$block('2026-10-08T18:00:00+02:00', '2026-10-08T18:25:00+02:00')]],
				['conferenceRoundId' => 'round-fail', 'lifecycle' => 'submitted', 'teacherId' => 'jan', 'blocks' => [$block('2026-10-08T18:00:00+02:00', '2026-10-08T18:20:00+02:00')]],
			],
			'conference-slot' => [
				['conferenceRoundId' => 'round-1', 'teacherId' => 'jan', 'startsAt' => '2026-10-08T18:20:00+02:00', 'endsAt' => '2026-10-08T18:35:00+02:00', 'lifecycle' => 'booked'],
				['conferenceRoundId' => 'round-1', 'teacherId' => 'piet', 'startsAt' => '2026-10-08T16:00:00Z', 'endsAt' => '2026-10-08T16:15:00Z', 'lifecycle' => 'cancelled'],
			],
		];
		$cohorts = [
			'c-1' => ['id' => 'c-1', 'teacherIds' => ['jan'], 'learnerIds' => ['anna', 'bert', 'ghost', 'carla']],
			'c-2' => ['id' => 'c-2', 'teacherIds' => ['piet', 'jan'], 'learnerIds' => ['bert', 'dirk']],
		];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($store): array {
				$filters = $config['filters'];
				$this->calls[] = ['findAll' => $filters, 'limit' => $config['limit'] ?? null];
				$rows = ($store[$filters['schema']] ?? []);
				unset($filters['register'], $filters['schema']);
				$rows = array_values(array_filter($rows, static fn (array $row): bool => array_intersect_key($row, $filters) == $filters));
				return array_merge(array_slice($rows, 0, 1), OrEntityFactory::makeMany(array_slice($rows, 1), 'row'));
			}
		);
		$objects->method('find')->willReturnCallback(
			function ($id) use ($cohorts) {
				$this->calls[] = ['find' => $id];
				return isset($cohorts[$id]) === true ? OrEntityFactory::make($cohorts[$id], 'cohort') : null;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->calls[] = ['save' => $object];
				if ($object['conferenceRoundId'] === 'round-fail') {
					throw new RuntimeException('register refused');
				}

				return OrEntityFactory::make($object, 'conference-slot');
			}
		);

		$schemas = $this->createMock(ListenerSchemaResolver::class);
		$schemas->method('eventRegister')->willReturnCallback(static fn (ObjectTransitionedEvent $event): string => $event->getRegister());
		$schemas->method('eventSchema')->willReturnCallback(static fn (ObjectTransitionedEvent $event): string => $event->getSchema());

		$profiles = $this->createMock(LearnerRefResolver::class);
		$profiles->method('resolveInTenant')->willReturnCallback(
			function (string $learnerId, string $tenantId): ?string {
				$this->calls[] = ['resolveInTenant' => [$learnerId, $tenantId]];
				if ($learnerId === 'ghost' || $tenantId !== 't-1') {
					return null;
				}

				return 'ref-' . $learnerId;
			}
		);

		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(static fn (string $uid): ?string => ['jan' => 'Jan de Vries', 'piet' => ''][$uid] ?? null);

		$timeZone = $this->createMock(IDateTimeZone::class);
		$timeZone->method('getTimeZone')->willReturn(new DateTimeZone('Europe/Amsterdam'));

		return new ConferenceFreeSlotGenerator($objects, $schemas, $profiles, $users, $timeZone, $logger);
	}//end generator()
}//end class
