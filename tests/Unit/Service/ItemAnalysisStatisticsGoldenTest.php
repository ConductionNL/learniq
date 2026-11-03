<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\ItemAnalysisService;
use OCA\Learniq\Tests\Support\GoldenFile;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Pins the statistics of an item against golden/ItemAnalysisStatistics.json:
 * which attempts count, the points of each, and the correlation, over attempts
 * built by a fixed formula.
 *
 * @covers \OCA\Learniq\Service\ItemAnalysisService
 */
class ItemAnalysisStatisticsGoldenTest extends TestCase {
	use GoldenFile;

	private const GOLDEN = __DIR__ . '/golden/ItemAnalysisStatistics.json';

	/**
	 * Every set of attempts gives the recorded statistics.
	 *
	 * @return void
	 */
	public function testStatisticsMatchGolden(): void {
		$sets = [
			'scores vary' => static fn (int $i): array => self::attempt(score: $i % 3, other: ($i * 7) % 5),
			'manual score wins' => static fn (int $i): array => self::attempt(score: 0, other: $i % 4, extra: ['manualScore' => $i % 2]),
			'points from the draw' => static fn (int $i): array => self::attempt(score: $i % 3, other: $i % 2) + ['drawnItemRefs' => [['itemId' => 'other', 'points' => 9], ['itemId' => 'item-x', 'points' => 4]]],
			'draw without points' => static fn (int $i): array => self::attempt(score: $i % 2, other: $i % 2) + ['drawnItemRefs' => [['itemId' => 'item-x']]],
			'draw names another item' => static fn (int $i): array => self::attempt(score: $i % 2, other: $i % 3) + ['drawnItemRefs' => [['itemId' => 'other', 'points' => 9]]],
			'some attempts skip the item' => static fn (int $i): array => $i % 4 === 0 ? ['responses' => [['itemId' => 'other', 'autoScore' => 1]]] : self::attempt(score: $i % 2, other: $i % 3),
			'some attempts are not scored' => static fn (int $i): array => $i % 4 === 0 ? ['responses' => [['itemId' => 'item-x', 'response' => ['value' => 'A']]]] : self::attempt(score: $i % 2, other: $i % 3),
			'everyone the same item score' => static fn (int $i): array => self::attempt(score: 1, other: $i % 3),
			'everyone the same rest score' => static fn (int $i): array => self::attempt(score: $i % 2, other: 1),
			'answers not text' => static fn (int $i): array => self::attempt(score: $i % 2, other: $i % 3, extra: ['response' => ['value' => ['A', 'B']]]),
			'too few' => static fn (int $i): array => $i < 5 ? self::attempt(score: $i % 2, other: $i % 3) : ['responses' => []],
		];

		$actual = [];
		foreach ($sets as $name => $attempt) {
			$statistics = $this->service(attempt: $attempt)->computeItemStatistics('item-x', 'assessment-1');
			unset($statistics['computedAt']);
			$actual[$name] = $statistics;
		}

		$this->assertMatchesGolden(file: self::GOLDEN, actual: $actual);
	}//end testStatisticsMatchGolden()

	/**
	 * One attempt at the item and one other item.
	 *
	 * @param int                 $score The item's score.
	 * @param int                 $other The other item's score.
	 * @param array<string,mixed> $extra More fields of the item's response.
	 *
	 * @return array<string,mixed>
	 */
	private static function attempt(int $score, int $other, array $extra = []): array {
		$answer = ['A', 'B', 'C'][$score % 3];

		return [
			'responses' => [
				array_merge(['itemId' => 'item-x', 'response' => ['value' => $answer], 'autoScore' => $score, 'manualScore' => null], $extra),
				['itemId' => 'other', 'response' => ['value' => 'A'], 'autoScore' => $other, 'manualScore' => null],
			],
		];
	}//end attempt()

	/**
	 * The service over one assessment, one item and 30 graded attempts.
	 *
	 * @param callable(int):array<string,mixed> $attempt Builds attempt number i.
	 *
	 * @return ItemAnalysisService
	 */
	private function service(callable $attempt): ItemAnalysisService {
		$results = [];
		for ($i = 0; $i < 30; $i++) {
			$results[] = ['id' => 'r-' . $i, 'assessmentId' => 'assessment-1', 'lifecycle' => 'graded', 'tenant_id' => 't'] + $attempt($i);
		}

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static fn (array $config): array => [
				'exam' => [['id' => 'assessment-1', 'tenant_id' => 't']],
				'item' => [['id' => 'item-x', 'maxScore' => 2]],
				'assessment-result' => $results,
			][$config['filters']['schema']] ?? []
		);

		return new ItemAnalysisService($objects);
	}//end service()
}//end class
