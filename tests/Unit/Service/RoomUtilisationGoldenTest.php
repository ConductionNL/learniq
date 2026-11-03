<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\OpeningHoursSettings;
use OCA\Learniq\Service\RoomUtilisationService;
use OCA\Learniq\Tests\Support\GoldenFile;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Timetabling\Source\TimetableSource;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Pins forPeriod() (and with it RoomUseTally) against
 * golden/RoomUtilisation.json: windows, filters, closed days, lessons on and
 * over the edges of the opening hours, rooms by id and by code, fill.
 *
 * @covers \OCA\Learniq\Service\RoomUtilisationService
 * @covers \OCA\Learniq\Service\RoomUseTally
 * @uses   \OCA\Learniq\Service\OpeningHoursSettings
 */
class RoomUtilisationGoldenTest extends TestCase {
	use GoldenFile;

	private const GOLDEN = __DIR__ . '/golden/RoomUtilisation.json';

	/**
	 * The stored objects per schema.
	 *
	 * @var array<string,list<array<string,mixed>>>
	 */
	private array $store = [];

	/**
	 * Every window, filter and opening-hours setting answers as recorded.
	 *
	 * @return void
	 */
	public function testForPeriodMatchesGolden(): void {
		$this->store = $this->store();
		$week = (string)json_encode(
			[
				'weekdays' => [
					'monday' => ['opens' => '08:00', 'closes' => '17:00'],
					'tuesday' => ['opens' => '08:30', 'closes' => '16:15'],
					'wednesday' => ['opens' => '08:00', 'closes' => '12:00'],
					'thursday' => ['opens' => '09:00', 'closes' => '17:00'],
				],
				'closedOnStudyDays' => true,
			]
		);

		$cases = [
			'default hours, whole week' => ['', ['2026-04-20', '2026-04-27', null, null]],
			'default hours, two weeks' => ['', ['2026-04-20', '2026-05-04', null, null]],
			'kind lab' => ['', ['2026-04-20', '2026-04-27', 'lab', null]],
			'kind empty' => ['', ['2026-04-20', '2026-04-27', '', '']],
			'building 00X2' => ['', ['2026-04-20', '2026-04-27', null, '00X2']],
			'kind and building' => ['', ['2026-04-20', '2026-04-27', 'classroom', '00X2']],
			'unknown kind' => ['', ['2026-04-20', '2026-04-27', 'pool', null]],
			'custom hours with study days closed' => [$week, ['2026-04-20', '2026-04-27', null, null]],
			'custom hours, open study days' => [str_replace('"closedOnStudyDays":true', '"closedOnStudyDays":false', $week), ['2026-04-20', '2026-04-27', null, null]],
			'end before start' => ['', ['2026-04-27', '2026-04-20', null, null]],
			'same day' => ['', ['2026-04-20', '2026-04-20', null, null]],
			'not a date' => ['', ['2026-02-30', '2026-03-05', null, null]],
			'loose format' => ['', ['2026-4-20', '2026-04-27', null, null]],
			'one day' => ['', ['2026-04-21', '2026-04-22', null, null]],
			'odd hours' => [(string)json_encode(['weekdays' => ['monday' => ['opens' => '07:45', 'closes' => '13'], 'wednesday' => ['opens' => '9:05', 'closes' => '08:00'], 'thursday' => 'closed']]), ['2026-04-20', '2026-04-27', null, null]],
		];

		$actual = [];
		foreach ($cases as $name => [$hours, [$from, $to, $kind, $building]]) {
			$actual[$name] = $this->service(hours: $hours)->forPeriod(from: $from, to: $to, kind: $kind, building: $building);
		}

		$service = $this->service(hours: '');
		foreach (['2026-04-20', '2026-02-29', '2024-02-29', '2026-4-2', ' 2026-04-20', '2026-04-20T10:00'] as $day) {
			$parsed = $service->parseDay(day: $day);
			$actual['parseDay ' . $day] = $parsed?->format('Y-m-d H:i:s');
		}

		$this->assertMatchesGolden(file: self::GOLDEN, actual: $actual);
	}//end testForPeriodMatchesGolden()

	/**
	 * The service over the store, with the given opening hours JSON ('' for the default).
	 *
	 * @param string $hours Stored opening hours.
	 *
	 * @return RoomUtilisationService
	 */
	private function service(string $hours): RoomUtilisationService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];
				$schema = $filters['schema'];
				unset($filters['register'], $filters['schema']);
				$rows = array_filter(
					($this->store[$schema] ?? []),
					static fn (array $row): bool => array_intersect_key($row, $filters) == $filters
				);
				if ($schema === 'cohort') {
					return array_values($rows);
				}

				return OrEntityFactory::makeMany(array_values($rows), $schema);
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($hours);

		$source = $this->createMock(TimetableSource::class);
		$source->method('name')->willReturn('fake');
		$source->method('sessionsForCohorts')->willReturnCallback(
			fn (array $cohortIds, ?string $from, ?string $to): array => array_merge(
				[['id' => 'probe', 'title' => json_encode([$cohortIds, $from, $to]), 'startsAt' => '2026-04-20T09:00:00+02:00', 'endsAt' => '2026-04-20T10:00:00+02:00']],
				$this->store['session']
			)
		);
		$resolver = $this->createMock(TimetableSourceResolver::class);
		$resolver->method('current')->willReturn($source);

		return new RoomUtilisationService($objects, $resolver, new OpeningHoursSettings($config));
	}//end service()

	/**
	 * Rooms, cohorts, report periods and lessons across two weeks.
	 *
	 * @return array<string,list<array<string,mixed>>>
	 */
	private function store(): array {
		return [
			'room' => [
				['id' => 'r-gym', 'name' => 'Gymzaal', 'code' => 'H-GYM', 'kind' => 'gym', 'capacity' => 35, 'buildingCode' => '00X1'],
				['id' => 'r-lab', 'name' => 'Practicumlokaal', 'code' => 'H2.10', 'kind' => 'lab', 'capacity' => 28, 'buildingCode' => '00X1'],
				['uuid' => 'r-uuid', 'name' => 'Lokaal 3', 'code' => 'B0.03', 'kind' => 'classroom', 'capacity' => 0, 'buildingCode' => '00X2'],
				['id' => 'r-small', 'name' => 'Kamer', 'kind' => 'classroom', 'capacity' => 10, 'buildingCode' => '00X2'],
				['name' => 'Zonder id', 'code' => 'NOID', 'kind' => 'classroom', 'buildingCode' => '00X2'],
			],
			'cohort' => [
				['id' => 'c-1', 'name' => '4H1', 'learnerIds' => array_fill(0, 28, 'x')],
				['uuid' => 'c-2', 'name' => '5V2', 'learnerIds' => array_fill(0, 12, 'y')],
				['id' => 'c-3', 'name' => 'leeg'],
				['name' => 'zonder id', 'learnerIds' => ['z']],
			],
			'report-period' => [
				['id' => 'rp-1', 'holidays' => [['name' => 'Koningsdag', 'startDate' => '2026-04-24', 'endDate' => '2026-04-24'], ['startDate' => '2026-04-29', 'endDate' => '2026-05-01'], ['startDate' => 'bad']], 'studyDays' => [['date' => '2026-04-22'], ['date' => '']]],
				['id' => 'rp-2', 'holidays' => [['startDate' => '2026-05-02', 'endDate' => '2026-04-30']]],
			],
			'session' => [
				['id' => 's-1', 'cohortId' => 'c-1', 'title' => 'Gym', 'startsAt' => '2026-04-20T08:00:00+02:00', 'endsAt' => '2026-04-20T16:00:00+02:00', 'roomId' => 'r-gym', 'lifecycle' => 'scheduled'],
				['id' => 's-2', 'cohortId' => 'c-1', 'startsAt' => '2026-04-21T15:00:00+02:00', 'endsAt' => '2026-04-21T18:00:00+02:00', 'roomId' => 'r-lab'],
				['id' => 's-3', 'cohortId' => 'c-1', 'startsAt' => '2026-04-21T09:00:00+02:00', 'endsAt' => '2026-04-21T10:00:00+02:00', 'roomId' => 'r-gym', 'lifecycle' => 'cancelled'],
				['id' => 's-4', 'cohortId' => 'c-1', 'startsAt' => '2026-04-24T09:00:00+02:00', 'endsAt' => '2026-04-24T10:00:00+02:00', 'roomId' => 'r-gym'],
				['id' => 's-5', 'cohortId' => 'c-2', 'title' => 'Buiten', 'startsAt' => '2026-04-23T09:00:00+02:00', 'endsAt' => '2026-04-23T10:00:00+02:00', 'location' => 'Buiten'],
				['id' => 's-6', 'cohortId' => 'c-2', 'startsAt' => '2026-04-22T07:00:00+02:00', 'endsAt' => '2026-04-22T09:30:00+02:00', 'roomReference' => 'B0.03', 'source' => 'planninq'],
				['id' => 's-7', 'cohortId' => 'c-3', 'startsAt' => '2026-04-23T10:15:00+02:00', 'endsAt' => '2026-04-23T11:45:00+02:00', 'roomReference' => 'H2.10'],
				['id' => 's-8', 'cohortId' => 'c-9', 'startsAt' => '2026-04-23T13:20:00+02:00', 'endsAt' => '2026-04-23T14:05:00+02:00', 'roomId' => 'r-small'],
				['id' => 's-9', 'cohortId' => 'c-1', 'startsAt' => '2026-04-23T13:00:00+02:00', 'endsAt' => '2026-04-24T09:00:00+02:00', 'roomId' => 'r-gym'],
				['id' => 's-10', 'cohortId' => 'c-1', 'startsAt' => '2026-04-23T13:00:00+02:00', 'endsAt' => '2026-04-23T12:00:00+02:00', 'roomId' => 'r-gym'],
				['id' => 's-11', 'cohortId' => 'c-1', 'startsAt' => '2026-04-23T05:00:00+02:00', 'endsAt' => '2026-04-23T07:00:00+02:00', 'roomId' => 'r-gym'],
				['id' => 's-12', 'cohortId' => 'c-1', 'startsAt' => 'garbage', 'endsAt' => '2026-04-23T07:00:00+02:00', 'roomId' => 'r-gym'],
				['id' => 's-13', 'cohortId' => 'c-1', 'startsAt' => '2026-04-23T09:00:00+02:00', 'roomId' => 'r-gym'],
				['id' => 's-14', 'cohortId' => 'c-1', 'startsAt' => '2026-04-20T10:00:00+02:00', 'endsAt' => '2026-04-20T11:00:00+02:00', 'roomId' => 'r-unknown'],
				['id' => 's-15', 'cohortId' => 'c-2', 'startsAt' => '2026-04-27T08:10:00+02:00', 'endsAt' => '2026-04-27T09:00:00+02:00', 'roomId' => 'r-uuid', 'roomReference' => 'H-GYM'],
				['id' => 's-16', 'startsAt' => '2026-04-28T11:00:00+02:00', 'endsAt' => '2026-04-28T12:30:00+02:00'],
				['title' => 'geen id', 'startsAt' => '2026-04-28T11:00:00+02:00', 'endsAt' => '2026-04-28T12:30:00+02:00', 'roomReference' => 'NOPE'],
				['id' => 's-18', 'cohortId' => 'c-1', 'startsAt' => '2026-04-20T08:00:00Z', 'endsAt' => '2026-04-20T09:00:00Z', 'roomId' => 'r-lab'],
				['id' => 's-19', 'cohortId' => 'c-2', 'startsAt' => '2026-04-30T09:00:00+02:00', 'endsAt' => '2026-04-30T10:00:00+02:00', 'roomId' => 'r-lab'],
				['id' => 's-20', 'cohortId' => 'c-2', 'startsAt' => '2026-04-22T11:20', 'endsAt' => '2026-04-22T12:40', 'roomId' => 'r-small'],
				['id' => 's-21', 'cohortId' => 'c-2', 'startsAt' => '2026-04-21T08:00:00+02:00', 'endsAt' => '2026-04-22T15:00:00+02:00', 'roomId' => 'r-small'],
				['id' => 's-22', 'cohortId' => 'c-2', 'startsAt' => '2026-04-21T07:00:00+02:00', 'endsAt' => '2026-04-21T08:00:00+02:00', 'roomId' => 'r-lab'],
			],
		];
	}//end store()
}//end class
