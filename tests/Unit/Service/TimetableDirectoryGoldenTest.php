<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Learniq\Service\TimetableDirectory;
use OCA\Learniq\Tests\Support\GoldenFile;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Timetabling\Source\TimetableSource;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins lessonsOf() for cohorts, teachers and rooms, roomsOfCohorts() and
 * labels() against golden/TimetableDirectory.json, with every source call
 * in the order it was made.
 *
 * @covers \OCA\Learniq\Service\TimetableDirectory
 */
class TimetableDirectoryGoldenTest extends TestCase {
	use GoldenFile;

	private const GOLDEN = __DIR__ . '/golden/TimetableDirectory.json';

	/**
	 * Source calls, in order.
	 *
	 * @var list<mixed>
	 */
	private array $calls = [];

	/**
	 * Every lookup answers as recorded, with the same source calls.
	 *
	 * @return void
	 */
	public function testLookupsMatchGolden(): void {
		$directory = $this->directory(failing: false);
		$actual = [];
		$lookups = [
			['cohort', 'c-1'], ['cohort', ''], ['teacher', 'jan'], ['teacher', 'piet'], ['teacher', 'nobody'], ['teacher', '7'],
			['room', 'r-1'], ['room', 'r-2'], ['room', 'r-nocode'], ['room', 'r-none'], ['other', 'r-1'],
		];
		foreach ($lookups as [$kind, $id]) {
			$this->calls = [];
			$lessons = $directory->lessonsOf(kind: $kind, id: $id, from: '2026-11-02T00:00:00+00:00', to: '2026-11-09T00:00:00+00:00');
			$actual['lessonsOf ' . $kind . ' ' . $id] = ['lessons' => $lessons, 'calls' => $this->calls];
		}

		foreach ([[], ['c-1'], ['c-2', 'c-1'], ['c-3']] as $cohortIds) {
			$this->calls = [];
			$rooms = $directory->roomsOfCohorts(cohortIds: $cohortIds);
			$actual['roomsOfCohorts ' . implode(',', $cohortIds)] = ['rooms' => $rooms, 'calls' => $this->calls];
		}

		$this->calls = [];
		$actual['roomsOfCohorts when the source fails'] = ['rooms' => $this->directory(failing: true)->roomsOfCohorts(cohortIds: ['c-1']), 'calls' => $this->calls];

		foreach (['cohort', 'teacher', 'room', 'other'] as $kind) {
			$actual['labels ' . $kind] = $directory->labels(kind: $kind);
		}

		$codes = $directory->roomCodes();
		$actual['roomCodes'] = ['ids' => array_map('strval', array_keys($codes)), 'codes' => array_values($codes)];
		$actual['policyRows'] = $directory->policyRows();
		$actual['enrolmentsOf'] = $directory->enrolmentsOf(uid: 'anna');

		$this->assertMatchesGolden(file: self::GOLDEN, actual: $actual);
	}//end testLookupsMatchGolden()

	/**
	 * The directory over fixed rows, with a source that echoes what it was asked.
	 *
	 * @param bool $failing Whether the source throws.
	 *
	 * @return TimetableDirectory
	 */
	private function directory(bool $failing): TimetableDirectory {
		$store = [
			'cohort' => [
				['id' => 'c-1', 'name' => '4H1', 'teacherIds' => ['jan', 'piet']],
				['uuid' => 'c-2', 'name' => '5V2', 'teacherIds' => ['jan', 7]],
				['id' => 'c-3', 'teacherIds' => 'jan'],
				['name' => 'zonder id', 'teacherIds' => ['']],
			],
			'room' => [
				['id' => 'r-1', 'name' => 'Lokaal 1', 'code' => 'A1'],
				['uuid' => 'r-2', 'name' => 'Lokaal 2', 'code' => 'A2'],
				['id' => 'r-nocode', 'name' => 'Kamer'],
				['name' => 'zonder id', 'code' => 'Z9'],
			],
			'enrolment' => [['id' => 'e-1', 'learnerId' => 'anna']],
			'timetable-visibility-policy' => [['id' => 'p-1', 'role' => 'learner']],
		];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config, ...$rest) use ($store): array {
				$filters = $config['filters'];
				$this->calls[] = ['findAll' => $filters, 'rbac' => ($rest[0] ?? 'default')];
				$rows = ($store[$filters['schema']] ?? []);
				if ($filters['schema'] === 'room') {
					return OrEntityFactory::makeMany($rows, 'room');
				}

				return $rows;
			}
		);

		$source = $this->createMock(TimetableSource::class);
		$source->method('name')->willReturn('fake');
		$source->method('sessionsForCohorts')->willReturnCallback(
			function (array $cohortIds, ?string $from, ?string $to) use ($failing): array {
				$this->calls[] = ['sessionsForCohorts' => $cohortIds, 'window' => $this->windowOf(from: $from, to: $to)];
				if ($failing === true) {
					throw new RuntimeException('down');
				}

				$out = [];
				foreach ($cohortIds as $cohortId) {
					$out[] = ['id' => 's-' . $cohortId, 'cohortId' => $cohortId, 'roomId' => 'r-1'];
					$out[] = ['id' => 's-' . $cohortId . '-ref', 'cohortId' => $cohortId, 'roomReference' => 'A2'];
					$out[] = ['id' => 's-' . $cohortId . '-both', 'cohortId' => $cohortId, 'roomId' => 'r-nocode', 'roomReference' => 'A1'];
					$out[] = ['cohortId' => $cohortId, 'roomReference' => 'ZZ'];
					$out[] = ['id' => 's-shared', 'cohortId' => $cohortId, 'roomId' => ''];
				}

				return $out;
			}
		);
		$source->method('sessionsForTeacher')->willReturnCallback(
			function (string $userId, ?string $from, ?string $to): array {
				$this->calls[] = ['sessionsForTeacher' => $userId, 'window' => [$from, $to]];
				return [['id' => 's-c-1', 'cover' => true], ['id' => 's-cover-' . $userId, 'cover' => true], ['title' => 'zonder id']];
			}
		);

		$resolver = $this->createMock(TimetableSourceResolver::class);
		$resolver->method('current')->willReturnCallback(
			function () use ($source): TimetableSource {
				$this->calls[] = 'current';
				return $source;
			}
		);

		return new TimetableDirectory($objects, $resolver);
	}//end directory()

	/**
	 * A fixed window as written; a clock-based one as its length in days.
	 *
	 * @param string|null $from Window start.
	 * @param string|null $to   Window end.
	 *
	 * @return array<int|string,mixed>
	 */
	private function windowOf(?string $from, ?string $to): array {
		if (str_starts_with((string)$from, '2026-11-02') === true) {
			return [$from, $to];
		}

		$days = ((new DateTimeImmutable((string)$to))->getTimestamp() - (new DateTimeImmutable((string)$from))->getTimestamp()) / 86400;
		return ['days' => $days];
	}//end windowOf()
}//end class
