<?php

/**
 * Tests for RoomUtilisationService and RoomUseTally.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\OpeningHoursSettings;
use OCA\Learniq\Service\RoomUtilisationService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Timetabling\Source\LocalSessionTimetableSource;
use OCA\Learniq\Timetabling\Source\PlanninqTimetableSource;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Hours open on teaching days, hours in use of lessons that were not cancelled,
 * fill, the grid and the lessons without a room.
 */
class RoomUtilisationServiceTest extends TestCase {

	/**
	 * The stored objects per schema.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $store = [];

	/**
	 * Stored opening hours JSON, or '' for the default.
	 *
	 * @var string
	 */
	private string $openingHoursJson = '';

	/**
	 * Build the service over the store, with learniq's own sessions as the source.
	 *
	 * @return RoomUtilisationService
	 */
	private function service(): RoomUtilisationService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];
				$schema = $filters['schema'];
				unset($filters['register'], $filters['schema']);
				$rows = array_filter(
					($this->store[$schema] ?? []),
					static function (array $row) use ($filters): bool {
						foreach ($filters as $key => $value) {
							if (($row[$key] ?? null) !== $value) {
								return false;
							}
						}

						return true;
					}
				);
				return OrEntityFactory::makeMany(array_values($rows), $schema);
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $key === OpeningHoursSettings::CONFIG_KEY ? $this->openingHoursJson : 'auto'
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(false);

		return new RoomUtilisationService(
			$objects,
			new TimetableSourceResolver($config, new LocalSessionTimetableSource($objects), new PlanninqTimetableSource($appManager, $this->createMock(IEventDispatcher::class))),
			new OpeningHoursSettings($config)
		);
	}//end service()

	/**
	 * A lesson on a day from one hour to another.
	 *
	 * @param string              $id    Lesson id.
	 * @param string              $day   `Y-m-d`.
	 * @param string              $start `HH:MM`.
	 * @param string              $end   `HH:MM`.
	 * @param array<string,mixed> $extra Extra fields.
	 *
	 * @return array<string,mixed>
	 */
	private function lesson(string $id, string $day, string $start, string $end, array $extra = []): array {
		return array_merge(
			['id' => $id, 'cohortId' => 'c-1', 'title' => 'Les ' . $id, 'startsAt' => "{$day}T{$start}:00+02:00", 'endsAt' => "{$day}T{$end}:00+02:00", 'lifecycle' => 'scheduled'],
			$extra
		);
	}//end lesson()

	/**
	 * A gym, a lab, a group of 28 and one week with a holiday on Friday.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = [
			'room' => [
				['id' => 'r-gym', 'name' => 'Gymzaal', 'code' => 'H-GYM', 'kind' => 'gym', 'capacity' => 35, 'buildingCode' => '00X1'],
				['id' => 'r-lab', 'name' => 'Practicumlokaal', 'code' => 'H2.10', 'kind' => 'lab', 'capacity' => 28, 'buildingCode' => '00X1'],
			],
			'cohort' => [
				['id' => 'c-1', 'name' => '4H1', 'learnerIds' => array_fill(0, 28, 'x')],
			],
			'report-period' => [
				['id' => 'rp-1', 'holidays' => [['name' => 'Koningsdag', 'startDate' => '2026-04-24', 'endDate' => '2026-04-24']], 'studyDays' => [['date' => '2026-04-22']]],
			],
			'session' => [
				// Monday: the gym in use 8 of the 9 open hours.
				$this->lesson(id: 's-1', day: '2026-04-20', start: '08:00', end: '16:00', extra: ['roomId' => 'r-gym']),
				// Tuesday: a lab lesson that runs past closing counts until 17:00.
				$this->lesson(id: 's-2', day: '2026-04-21', start: '15:00', end: '18:00', extra: ['roomId' => 'r-lab']),
				// Tuesday: a cancelled gym lesson counts nothing.
				$this->lesson(id: 's-3', day: '2026-04-21', start: '09:00', end: '10:00', extra: ['roomId' => 'r-gym', 'lifecycle' => 'cancelled']),
				// Friday is a holiday: counts nothing.
				$this->lesson(id: 's-4', day: '2026-04-24', start: '09:00', end: '10:00', extra: ['roomId' => 'r-gym']),
				// A lesson with only a free-text location.
				$this->lesson(id: 's-5', day: '2026-04-23', start: '09:00', end: '10:00', extra: ['location' => 'Buiten']),
			],
		];
	}//end setUp()

	/**
	 * Holidays add no open hours; cancelled lessons add no use; lessons are clipped to opening hours.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/school-structure/spec.md#scenario-a-deputy-head-checks-the-gyms
	 */
	public function testOpenHoursAndHoursInUse(): void {
		$out = $this->service()->forPeriod(from: '2026-04-20', to: '2026-04-25');

		// Monday to Thursday; Friday is Koningsdag.
		self::assertSame(4, $out['teachingDays']);
		self::assertSame(36.0, $out['openHoursPerRoom']);

		$rooms = array_column($out['rooms'], null, 'roomId');
		self::assertSame(8.0, $rooms['r-gym']['hoursInUse']);
		self::assertSame(2.0, $rooms['r-lab']['hoursInUse']);
		self::assertSame(round(8 / 36, 3), $rooms['r-gym']['occupancy']);
		self::assertSame(0.8, $rooms['r-gym']['fill']);
		self::assertSame(1.0, $rooms['r-lab']['fill']);
		self::assertSame('r-gym', $out['rooms'][0]['roomId'], 'most used first');
	}//end testOpenHoursAndHoursInUse()

	/**
	 * The lesson without a room is counted and named.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/school-structure/spec.md#scenario-unassigned-lessons-are-named
	 */
	public function testLessonsWithoutARoomAreCounted(): void {
		$out = $this->service()->forPeriod(from: '2026-04-20', to: '2026-04-25');

		self::assertSame(1, $out['unassigned']['count']);
		self::assertSame('s-5', $out['unassigned']['sessions'][0]['id']);
	}//end testLessonsWithoutARoomAreCounted()

	/**
	 * The grid holds the share of rooms in use per weekday and hour.
	 *
	 * @return void
	 */
	public function testGrid(): void {
		$out = $this->service()->forPeriod(from: '2026-04-20', to: '2026-04-25');
		$monday = $out['grid'][0];

		self::assertSame(0, $monday['weekday']);
		$byHour = array_column($monday['hours'], 'share', 'hour');
		// One of two rooms in use from 08:00 to 16:00 on the one Monday.
		self::assertSame(0.5, $byHour[8]);
		self::assertSame(0.0, $byHour[16]);
	}//end testGrid()

	/**
	 * Filters narrow the rooms; study days close rooms when the setting says so.
	 *
	 * @return void
	 */
	public function testFiltersAndStudyDays(): void {
		$out = $this->service()->forPeriod(from: '2026-04-20', to: '2026-04-25', kind: 'lab');
		self::assertSame(['r-lab'], array_column($out['rooms'], 'roomId'));

		$this->openingHoursJson = (string)json_encode(
			[
				'weekdays' => ['monday' => ['opens' => '08:00', 'closes' => '17:00'], 'wednesday' => ['opens' => '08:00', 'closes' => '12:00']],
				'closedOnStudyDays' => true,
			]
		);
		$closed = $this->service()->forPeriod(from: '2026-04-20', to: '2026-04-25');
		// Only Monday: Wednesday 22 April is a study day, the rest closed.
		self::assertSame(1, $closed['teachingDays']);
		self::assertSame(9.0, $closed['openHoursPerRoom']);
	}//end testFiltersAndStudyDays()
}//end class
