<?php

/**
 * Learniq ConferenceScheduleGenerator characterization test.
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
 * @spec openspec/specs/parent-conferences/spec.md#scenario-conflict-free-generation-from-sign-ups-and-availability
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\ConferenceScheduleGenerator;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Pins every saveObject call ConferenceScheduleGenerator makes for a round
 * moving to `scheduled`, per scenario, against
 * tests/Unit/Listener/golden/ConferenceSchedule.json. With RECORD_GOLDEN=1
 * the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Listener\ConferenceScheduleGenerator
 * @covers \OCA\Learniq\Service\ConferenceSignupRequest
 * @covers \OCA\Learniq\Service\ConferenceSlotPass
 * @uses   \OCA\Learniq\Service\ConferenceBookingMode
 * @uses   \OCA\Learniq\Service\ConferenceSlotSlicer
 * @uses   \OCA\Learniq\Service\ListenerSchemaResolver
 * @uses   \OCA\Learniq\Service\ConferenceSlotPattern
 * @uses   \OCA\Learniq\Support\EntityRow
 */
class ConferenceScheduleGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/ConferenceSchedule.json';

	/**
	 * Each scenario: the stored rows per schema and the round.
	 *
	 * @return array<string, array{0: array<string, list<array<string, mixed>>>, 1: array<string, mixed>}>
	 */
	private static function scenarios(): array {
		$block = static fn (string $from, string $to) => ['startsAt' => '2026-09-01T' . $from . ':00+00:00', 'endsAt' => '2026-09-01T' . $to . ':00+00:00'];
		$avail = static fn (string $teacher, array $blocks, string $lifecycle = 'submitted') => [
			'id' => 'ta-' . $teacher, 'conferenceRoundId' => 'round-1', 'lifecycle' => $lifecycle, 'teacherId' => $teacher, 'blocks' => $blocks,
		];
		$signup = static fn (string $id, array $teachers, string $created, array $extra = []) => array_merge(
			['id' => $id, 'conferenceRoundId' => 'round-1', 'lifecycle' => 'submitted', 'learnerId' => 'learner-' . $id, 'requestedTeacherIds' => $teachers, 'createdAt' => $created],
			$extra
		);
		$slot = static fn (string $id, string $teacher, string $signupId, string $lifecycle, array $at) => array_merge(
			['id' => $id, 'conferenceRoundId' => 'round-1', 'teacherId' => $teacher, 'signupId' => $signupId, 'lifecycle' => $lifecycle],
			$at
		);
		$round = ['id' => 'round-1', 'tenant_id' => 'tenant-a', 'slotDurationMinutes' => 10, 'bufferMinutes' => 0];
		return [
			'two teachers, four signups, one teacher short' => [
				[
					'teacher-availability' => [$avail('teacher-a', [$block('09:00', '09:30')]), $avail('teacher-b', [$block('09:00', '09:20')], 'locked')],
					'conference-signup' => [
						$signup('s3', ['teacher-a', 'teacher-b'], '2026-08-01T08:02'),
						$signup('s1', ['teacher-a', 'teacher-b'], '2026-08-01T08:00', ['learnerRef' => 'ref-1']),
						$signup('s2', ['teacher-a'], '2026-08-01T08:01'),
						$signup('s4', ['teacher-b'], '2026-08-01T08:03', ['lifecycle' => 'waitlisted', 'notes' => 'earlier note']),
					],
				],
				$round,
			],
			'regenerate keeps active slots and frees a cancelled signup' => [
				[
					'teacher-availability' => [$avail('teacher-a', [$block('09:00', '09:30')])],
					'conference-signup' => [
						$signup('s1', ['teacher-a'], '2026-08-01T08:00'),
						$signup('s2', ['teacher-a'], '2026-08-01T08:01', ['lifecycle' => 'cancelled']),
						$signup('s3', ['teacher-a', 'teacher-c'], '2026-08-01T08:02'),
						['uuid' => 's5', 'conferenceRoundId' => 'round-1', 'lifecycle' => 'submitted', 'requestedTeacherIds' => ['teacher-a'], 'createdAt' => '2026-08-01T08:04'],
					],
					'conference-slot' => [
						$slot('slot-1', 'teacher-a', 's1', 'confirmed', $block('09:00', '09:10')),
						$slot('slot-2', 'teacher-a', 's2', 'proposed', $block('09:10', '09:20')),
						$slot('slot-3', 'teacher-a', 's9', 'cancelled', $block('09:20', '09:30')),
					],
				],
				array_merge($round, ['bufferMinutes' => 0]),
			],
			'a signup cannot be in two places at once' => [
				[
					'teacher-availability' => [$avail('teacher-a', [$block('09:00', '09:20')]), $avail('teacher-b', [$block('09:00', '09:10'), $block('09:30', '09:40')])],
					'conference-signup' => [$signup('s1', ['teacher-a', 'teacher-b', 'teacher-a'], '2026-08-01T08:00')],
				],
				array_merge($round, ['tenant_id' => '']),
			],
			'an existing slot blocks that time with another teacher' => [
				[
					'teacher-availability' => [$avail('teacher-b', [$block('09:00', '09:20')])],
					'conference-signup' => [$signup('s1', ['teacher-a', 'teacher-b'], '2026-08-01T08:00')],
					'conference-slot' => [$slot('slot-1', 'teacher-a', 's1', 'proposed', $block('09:00', '09:10'))],
				],
				$round,
			],
			'a buffer separates the slots of one teacher' => [
				[
					'teacher-availability' => [$avail('teacher-a', [$block('09:00', '09:40')])],
					'conference-signup' => [$signup('s1', ['teacher-a'], '2026-08-01T08:00'), $signup('s2', ['teacher-a'], '2026-08-01T08:05'), $signup('s3', ['teacher-a'], '2026-08-01T08:10')],
				],
				array_merge($round, ['bufferMinutes' => 5]),
			],
			'a round without an id writes nothing' => [['conference-signup' => [$signup('s1', ['teacher-a'], '2026-08-01T08:00')]], ['slotDurationMinutes' => 10]],
		];
	}//end scenarios()

	/**
	 * Every scenario's calls equal the recorded golden.
	 *
	 * @return void
	 */
	public function testEveryScenarioMakesTheRecordedCalls(): void {
		$actual = [];
		foreach (self::scenarios() as $name => [$rows, $round]) {
			$actual[$name] = $this->callsFor($rows, $round);
		}

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n";
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		$this->assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testEveryScenarioMakesTheRecordedCalls()

	/**
	 * Run the generator over one store and return the saves it made.
	 *
	 * @param array<string, list<array<string, mixed>>> $rows  The stored rows per schema.
	 * @param array<string, mixed>                      $round The ConferenceRound.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function callsFor(array $rows, array $round): array {
		$calls   = [];
		$service = $this->createMock(ObjectService::class);
		$service->method('findAll')->willReturnCallback(
			static function (array $config) use ($rows): array {
				$filters = array_diff_key($config['filters'], ['register' => true, 'schema' => true]);
				$found   = [];
				foreach (($rows[$config['filters']['schema']] ?? []) as $row) {
					if (array_intersect_assoc($filters, $row) === $filters) {
						$found[] = OrEntityFactory::make($row, (string)$config['filters']['schema']);
					}
				}

				return $found;
			}
		);
		$params = (new ReflectionMethod(ObjectService::class, 'saveObject'))->getParameters();
		$service->method('saveObject')->willReturnCallback(
			static function (...$args) use ($params, &$calls): ObjectEntity {
				$named = [];
				foreach ($args as $i => $value) {
					if ($params[$i]->isDefaultValueAvailable() === true && $params[$i]->getDefaultValue() === $value) {
						continue;
					}

					$named[$params[$i]->getName()] = $value;
				}

				unset($named['object']['@self']);
				$calls[] = ['saveObject' => $named];
				return OrEntityFactory::make($named['object'], (string)$named['schema']);
			}
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(static fn (string $uid): ?string => $uid === 'teacher-a' ? 'Meester A' : null);

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($round);
		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($entity);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('conference-round');
		$event->method('getTo')->willReturn('scheduled');
		$event->method('getFrom')->willReturn('booking-closed');

		(new ConferenceScheduleGenerator($service, new NullLogger(), TransitionScope::resolver(), $users))->handle($event);
		return $calls;
	}//end callsFor()
}//end class
