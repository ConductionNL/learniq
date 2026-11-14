<?php

/**
 * Golden master for the enrolment forecast over generated schools.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\EnrolmentForecastReader;
use OCA\Learniq\Service\EnrolmentForecastService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Each case builds one school from a seed and compares the whole forecast,
 * without `computedAt`, with the recorded one. Set GOLDEN_RECORD=1 to record.
 *
 * @covers \OCA\Learniq\Service\EnrolmentForecastService
 */
class EnrolmentForecastGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/enrolment-forecast-golden.json';

	private const PROGRAMMES = ['havo' => 5, 'vwo' => 6, 'vmbo' => 4];

	private const COURSES = ['econ', 'bio', 'nat', 'kunst', 'onbekend'];

	/**
	 * The seeds.
	 *
	 * @return array<string, array{0: int}>
	 */
	public static function seeds(): array {
		$cases = [];
		foreach (range(1, 24) as $seed) {
			$cases['seed ' . $seed] = [$seed];
		}

		return $cases;
	}//end seeds()

	/**
	 * The forecast for one generated school matches the recorded one.
	 *
	 * @param int $seed The seed.
	 *
	 * @return void
	 */
	#[DataProvider('seeds')]
	public function testTheForecastMatchesTheRecording(int $seed): void {
		$random = new Randomizer(new Mt19937($seed));
		[$reader, $scenario] = $this->world(random: $random);
		$result = (new EnrolmentForecastService($reader))->compute($scenario);
		unset($result['computedAt']);

		$recorded = is_file(self::FIXTURE) === true ? json_decode((string)file_get_contents(self::FIXTURE), true) : [];
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded['seed ' . $seed] = $result;
			ksort($recorded, SORT_NATURAL);
			$lines = array_map(static fn (string $key, array $value): string => json_encode($key) . ': ' . json_encode($value, JSON_PRESERVE_ZERO_FRACTION), array_keys($recorded), $recorded);
			file_put_contents(self::FIXTURE, "{\n" . implode(",\n", $lines) . "\n}\n");
		}

		self::assertSame(json_encode($recorded['seed ' . $seed] ?? null, JSON_PRESERVE_ZERO_FRACTION), json_encode($result, JSON_PRESERVE_ZERO_FRACTION));
	}//end testTheForecastMatchesTheRecording()

	/**
	 * One generated school and its scenario.
	 *
	 * @param Randomizer $random The seeded randomizer.
	 *
	 * @return array{0: EnrolmentForecastReader, 1: array<string, mixed>}
	 */
	private function world(Randomizer $random): array {
		$groups = [];
		$learners = [];
		foreach (self::PROGRAMMES as $programme => $length) {
			foreach (range(1, $length) as $year) {
				if ($random->getInt(0, 4) === 0) {
					continue;
				}

				$ids = array_map(static fn (int $i): string => $programme . $year . '-' . $i, range(1, $random->getInt(0, 40)));
				$groups[] = ['id' => $programme . $year, 'programmeId' => $programme, 'programmeYear' => $year, 'learnerIds' => $ids];
				$learners = array_merge($learners, array_map(static fn (string $id): array => [$programme, $id], $ids));
			}
		}

		$choices = [];
		foreach ($learners as [$programme, $learnerId]) {
			if ($random->getInt(0, 2) !== 0) {
				continue;
			}

			$picked = array_values(array_filter(self::COURSES, static fn (): bool => $random->getInt(0, 2) === 0));
			$choices[] = ['programmeId' => $random->getInt(0, 9) === 0 ? 'vwo' : $programme, 'learnerId' => $random->getInt(0, 9) === 0 ? 'gone-' . $learnerId : $learnerId, 'selectedElectiveCourseIds' => $picked];
		}

		$rates = [];
		foreach (array_slice($groups, 0, $random->getInt(0, count($groups))) as $group) {
			$up = $random->getInt(50, 95) / 100;
			$repeat = round((1 - $up) * $random->getInt(0, 100) / 100, 2);
			$rates[] = ['programmeId' => $group['programmeId'], 'programmeYear' => $group['programmeYear'], 'upRate' => $up, 'repeatRate' => $repeat, 'leaveRate' => round(1 - $up - $repeat, 2)];
		}

		$scenario = ['name' => 'Golden', 'targetYear' => '2027-2028', 'targetGroupSize' => $random->getInt(10, 32), 'rates' => $rates];
		if ($random->getInt(0, 1) === 1) {
			$scenario['intake'] = [['programmeId' => 'havo', 'expected' => $random->getInt(0, 150)], ['programmeId' => '', 'expected' => 3]];
		}

		$reader = $this->createMock(EnrolmentForecastReader::class);
		$reader->method('programmes')->willReturn(['havo' => ['id' => 'havo', 'name' => 'havo'], 'vwo' => ['id' => 'vwo', 'name' => 'vwo'], 'vmbo' => ['id' => 'vmbo', 'name' => 'vmbo-t']]);
		$reader->method('currentGroups')->willReturn($groups);
		$reader->method('durations')->willReturn(self::PROGRAMMES);
		$reader->method('placedApplications')->willReturn(['havo' => (float)$random->getInt(0, 60), 'vmbo' => 7.5]);
		$reader->method('approvedChoices')->willReturn($choices);
		$reader->method('courseNames')->willReturn(['econ' => 'Economie', 'bio' => 'Biologie', 'nat' => 'Natuurkunde', 'kunst' => 'Kunst']);

		return [$reader, $scenario];
	}//end world()
}//end class
