<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Tests\Support\SaveCallShape;
use OCA\Learniq\Service\SegmentService;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\Tests\Support\GoldenFile;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins which settings row the segment is read from (currentSegment(),
 * workspace(), hasSegment() and the uuid setSegment() writes to) against
 * golden/Segment.json, over sets of rows with and without a known segment,
 * update time, uuid and chooser.
 *
 * @covers \OCA\Learniq\Service\SegmentService
 */
class SegmentGoldenTest extends TestCase {
	use GoldenFile;

	private const GOLDEN = __DIR__ . '/golden/Segment.json';

	/**
	 * Every row set answers as recorded.
	 *
	 * @return void
	 */
	public function testCurrentRowMatchesGolden(): void {
		$at = static fn (string $time): array => ['updated' => $time];
		$sets = [
			'no rows' => [],
			'one array row' => [['segment' => 'vo', 'setBy' => 'anna', 'uuid' => 'u-1']],
			'unknown and non-string segments skipped' => [['segment' => 'school'], ['segment' => 7], ['segment' => null], ['nothing' => true]],
			'newest wins' => [
				['segment' => 'po', '@self' => $at('2026-10-01T10:00:00+00:00') + ['id' => 'u-old'], 'setBy' => 'anna'],
				['segment' => 'mbo', '@self' => $at('2026-10-02T10:00:00+02:00') + ['id' => 'u-new'], 'setBy' => 'ghost'],
				['segment' => 'he', '@self' => $at('2026-09-30T10:00:00+00:00'), 'id' => 'u-older'],
			],
			'a tie keeps the first' => [
				['segment' => 'po', '@self' => $at('2026-10-01T10:00:00+00:00'), 'uuid' => 'u-a'],
				['segment' => 'vo', '@self' => $at('2026-10-01T12:00:00+02:00'), 'uuid' => 'u-b'],
			],
			'no time counts as oldest' => [
				['segment' => 'training', 'setBy' => 'anna'],
				['segment' => 'corporate', '@self' => $at('garbage'), 'setBy' => 42],
				['segment' => 'he', '@self' => $at(''), 'setBy' => ''],
			],
			'a later row without time loses' => [
				['segment' => 'vo', '@self' => $at('2026-10-01T10:00:00+00:00'), 'setBy' => 'anna'],
				['segment' => 'po'],
			],
			'a chooser that is no text' => [['segment' => 'vo', 'setBy' => 42]],
			'uuid order' => [['segment' => 'vo', '@self' => ['id' => ''], 'id' => 5, 'uuid' => 'u-last']],
			'entities' => 'entities',
			'read fails' => 'throw',
		];

		$actual = [];
		foreach ($sets as $name => $rows) {
			$actual[$name] = $this->answers(rows: $rows);
		}

		$this->assertMatchesGolden(file: self::GOLDEN, actual: $actual);
	}//end testCurrentRowMatchesGolden()

	/**
	 * What the service answers over one set of rows.
	 *
	 * @param list<mixed>|string $rows The rows, 'entities' for two entity rows, or 'throw'.
	 *
	 * @return array<string,mixed>
	 */
	private function answers(array|string $rows): array {
		$saved = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config) use ($rows): array {
				if ($rows === 'throw') {
					throw new RuntimeException('register down');
				}

				if ($rows === 'entities') {
					return OrEntityFactory::makeMany([['segment' => 'mbo', 'setBy' => 'anna'], ['segment' => 'po']], 'learniqsettings');
				}

				return $rows;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			static function (...$args) use (&$saved) {
				$saved[] = (SaveCallShape::all(args: $args)['uuid'] ?? 'missing');
				return OrEntityFactory::make(['segment' => 'vo'], 'learniqsettings');
			}
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid === 'anna');
		$logger = new CapturingLogger();
		$service = new SegmentService($objects, $logger, $users);

		$answers = ['current' => $service->currentSegment(), 'workspace' => $service->workspace(), 'has' => $service->hasSegment()];
		$service->setSegment(segment: 'vo', actor: 'anna');
		$answers['saved uuid'] = $saved;
		$answers['log'] = array_map(static fn (array $record): string => $record['message'], $logger->records);
		return $answers;
	}//end answers()
}//end class
