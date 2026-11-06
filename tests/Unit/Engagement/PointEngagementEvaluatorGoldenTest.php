<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Engagement;

use DateTime;
use DateTimeZone;
use OCA\Learniq\Service\PointEngagementEvaluator;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Pins evaluate() for award and level sets that reach every branch, on a
 * fixed clock, against golden/PointEngagement.json, case by case: the result
 * and the reads. With RECORD_GOLDEN=1 the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Service\PointEngagementEvaluator
 */
class PointEngagementEvaluatorGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/PointEngagement.json';

	/**
	 * Every case evaluates as recorded.
	 *
	 * @return void
	 */
	public function testEvaluateMatchesGolden(): void {
		$levels = [
			'none' => [],
			'ladder' => [
				['id' => 'bronze', 'minPoints' => 0],
				['id' => 'silver', 'minPoints' => '10'],
				['uuid' => 'gold-uuid', 'minPoints' => 25.5],
				['id' => 'silver-twin', 'minPoints' => 10],
				['id' => 'open', 'minPoints' => null],
				['id' => 'nameless'],
			],
			'only high' => [['id' => 'platinum', 'minPoints' => 1000]],
			'no ids' => [['minPoints' => 1]],
			'unset threshold first' => [['id' => 'open', 'minPoints' => null], ['id' => 'bronze', 'minPoints' => 0]],
		];
		$awards = [
			'none' => [],
			'today run' => [
				['points' => 5, 'awardedAt' => '2026-11-03T08:00:00+00:00'],
				['points' => '2.5', 'awardedAt' => '2026-11-02'],
				['points' => 3, 'awardedAt' => '2026-11-01T23:59:00+00:00'],
				['points' => 1, 'awardedAt' => '2026-10-28'],
				['points' => 1, 'awardedAt' => '2026-10-29'],
				['points' => 1, 'awardedAt' => '2026-10-29T10:00:00+00:00'],
			],
			'yesterday run' => [
				['points' => 10, 'awardedAt' => '2026-11-02'],
				['points' => 10, 'awardedAt' => '2026-11-01'],
				['points' => 6, 'awardedAt' => '2026-10-31'],
			],
			'broken run' => [
				['points' => 30, 'awardedAt' => '2026-10-31'],
				['points' => 1, 'awardedAt' => '2026-10-20'],
				['points' => 1, 'awardedAt' => '2026-10-21'],
				['points' => 1, 'awardedAt' => '2026-10-22'],
			],
			'odd values' => [
				['points' => null, 'awardedAt' => ''],
				['awardedAt' => 'not a date'],
				['points' => 'x', 'awardedAt' => 20261103],
				['points' => 4],
				['points' => -1, 'awardedAt' => '2026-11-03'],
			],
			'odd values long ago' => [
				['points' => 2, 'awardedAt' => ''],
				['points' => 2, 'awardedAt' => 'not a date'],
				['points' => 2, 'awardedAt' => '2026-10-15'],
			],
		];

		$actual = [];
		foreach ($awards as $awardName => $awardRows) {
			foreach ($levels as $levelName => $levelRows) {
				$reads = [];
				$evaluator = $this->evaluator(awards: $awardRows, levels: $levelRows, reads: $reads);
				$actual[$awardName . ' / ' . $levelName] = ['result' => $evaluator->evaluate(learnerId: 'lea', tenantId: 't-1'), 'reads' => $reads];
			}
		}

		$reads = [];
		$actual['no learner'] = ['result' => $this->evaluator(awards: $awards['today run'], levels: $levels['ladder'], reads: $reads)->evaluate(learnerId: '', tenantId: 't-1'), 'reads' => $reads];

		$actual = json_decode((string)json_encode($actual, (JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)), true);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		self::assertSame(expected: array_keys($golden), actual: array_keys($actual));
		foreach ($golden as $name => $expected) {
			self::assertSame(expected: $expected, actual: $actual[$name], message: $name);
		}
	}//end testEvaluateMatchesGolden()

	/**
	 * The evaluator over these awards and levels, on 3 November 2026 at 10:00 UTC.
	 *
	 * @param list<array<string, mixed>> $awards The learner's awards.
	 * @param list<array<string, mixed>> $levels The tenant's levels.
	 * @param list<array<string, mixed>> $reads  Where the read configs are recorded.
	 *
	 * @return PointEngagementEvaluator
	 */
	private function evaluator(array $awards, array $levels, array &$reads): PointEngagementEvaluator {
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config) use ($awards, $levels, &$reads): array {
				$reads[] = $config;
				if ($config['filters']['schema'] === 'point-award') {
					return $awards;
				}

				return OrEntityFactory::makeMany(rows: $levels, schema: 'engagement-level');
			}
		);
		$clock = $this->createMock(originalClassName: ITimeFactory::class);
		$clock->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-11-03 10:00:00', new DateTimeZone('UTC')));

		return new PointEngagementEvaluator(objectService: $objects, timeFactory: $clock);
	}//end evaluator()
}//end class
