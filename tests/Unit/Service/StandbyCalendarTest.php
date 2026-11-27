<?php

/**
 * StandbyCalendar: a teacher's standby blocks in a window.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\StandbyCalendar;
use OCA\Learniq\Tests\Support\GoldenFile;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

// The unit bootstrap does not load the golden-file helper; the full one does.
require_once __DIR__ . '/../../Support/GoldenFile.php';

/**
 * Pins blocksFor() against golden/StandbyCalendar.json: the blocks and the query per window.
 *
 * @covers \OCA\Learniq\Service\StandbyCalendar
 */
class StandbyCalendarTest extends TestCase {
	use GoldenFile;

	/**
	 * Weekly, one-off, out-of-validity, other teachers' and entity slots over
	 * several windows, a bad window and a failing read, as recorded.
	 *
	 * @return void
	 */
	public function testBlocksMatchGolden(): void {
		$entity = new class() implements \JsonSerializable {
			/**
			 * A slot as an entity serialises.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['uuid' => 'sb-e', 'teacherId' => 'eva', 'weekday' => 'friday', 'startsAt' => '08:30', 'endsAt' => '09:20', 'validFrom' => '2026-09-01', 'validUntil' => '2026-12-31'];
			}//end jsonSerialize()
		};
		$slots = [
			['id' => 'sb-1', 'teacherId' => 'eva', 'weekday' => 'tuesday', 'startsAt' => '10:15', 'endsAt' => '11:05', 'validFrom' => '2026-09-01', 'validUntil' => '2026-12-31', 'vestigingId' => 'v-1'],
			['id' => 'sb-2', 'teacherId' => 'eva', 'date' => '2026-09-30', 'weekday' => 'tuesday', 'startsAt' => '09:00', 'endsAt' => '09:50', 'validFrom' => '2026-09-01', 'validUntil' => '2026-12-31'],
			['id' => 'sb-3', 'teacherId' => 'eva', 'weekday' => 'monday', 'startsAt' => '13:00', 'endsAt' => '13:50', 'validFrom' => '2026-10-01', 'validUntil' => '2026-10-31'],
			['id' => 'sb-4', 'teacherId' => 'bram', 'weekday' => 'tuesday', 'startsAt' => '08:00', 'endsAt' => '08:50', 'validFrom' => '2026-09-01', 'validUntil' => '2026-12-31'],
			['id' => 'sb-5', 'teacherId' => 'eva', 'weekday' => 'wednesday', 'startsAt' => '08:00', 'endsAt' => '08:50', 'validFrom' => '2026-09-01', 'validUntil' => '2026-12-31'],
			$entity,
			'not a slot',
		];

		$actual = [];
		$windows = [
			'one week' => ['2026-09-28T00:00:00+02:00', '2026-10-05T00:00:00+02:00', false],
			'from mid-day' => ['2026-09-29T14:00:00+02:00', '2026-10-01T00:00:00+02:00', false],
			'two weeks into october' => ['2026-10-04T00:00:00+02:00', '2026-10-13T00:00:00+02:00', false],
			'empty window' => ['2026-10-05T00:00:00+02:00', '2026-10-05T00:00:00+02:00', false],
			'a bad date' => ['not a date', '2026-10-05T00:00:00+02:00', false],
			'a failing read' => ['2026-09-28T00:00:00+02:00', '2026-10-05T00:00:00+02:00', true],
		];
		foreach ($windows as $name => [$from, $to, $fails]) {
			$queries = [];
			$objects = $this->createMock(ObjectService::class);
			$objects->method('findAll')->willReturnCallback(
				static function (array $config) use (&$queries, $slots, $fails): array {
					$queries[] = $config;
					if ($fails === true) {
						throw new RuntimeException('down');
					}

					return $slots;
				}
			);
			$actual[$name] = ['blocks' => (new StandbyCalendar($objects))->blocksFor(uid: 'eva', from: $from, to: $to), 'queries' => $queries];
		}

		$this->assertMatchesGolden(__DIR__ . '/golden/StandbyCalendar.json', $actual);
	}//end testBlocksMatchGolden()
}//end class
