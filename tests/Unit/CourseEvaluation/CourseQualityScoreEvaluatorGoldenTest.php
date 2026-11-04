<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\CourseEvaluation;

use OCA\Learniq\Service\CourseQualityScoreEvaluator;
use OCA\Learniq\Tests\Support\GoldenFile;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Pins what a quality score reads and answers against
 * golden/CourseQualityScoreEvaluator.json.
 *
 * @covers \OCA\Learniq\Service\CourseQualityScoreEvaluator
 */
class CourseQualityScoreEvaluatorGoldenTest extends TestCase {
	use GoldenFile;

	private const GOLDEN = __DIR__ . '/golden/CourseQualityScoreEvaluator.json';

	/**
	 * Every scope reads and answers as recorded.
	 *
	 * @return void
	 */
	public function testScopesMatchGolden(): void {
		$scopes = [
			'course only' => ['c-1', null, '2026-2027', 'Q1'],
			'blank teacher' => ['c-1', '', '2026-2027', 'Q1'],
			'one teacher' => ['c-1', 'jan', '2026-2027', 'Q2'],
			'nothing found' => ['c-9', null, '2026-2027', 'Q1'],
		];
		$actual = [];
		foreach ($scopes as $name => [$course, $teacher, $year, $period]) {
			$calls = [];
			$objects = $this->createMock(ObjectService::class);
			$objects->method('findAll')->willReturnCallback(
				static function (array $config) use (&$calls): array {
					$calls[] = $config;
					if ($config['filters']['courseId'] === 'c-9') {
						return [];
					}

					if ($config['filters']['schema'] === 'evaluation-invitation') {
						return [['id' => 'i-1'], OrEntityFactory::make(['id' => 'i-2'], 'evaluation-invitation'), ['id' => 'i-3']];
					}

					return [['overallScore' => 4], OrEntityFactory::make(['overallScore' => 3.5], 'response'), ['overallScore' => null]];
				}
			);
			$score = (new CourseQualityScoreEvaluator($objects))->evaluate(courseId: $course, teacherId: $teacher, academicYear: $year, period: $period);
			unset($score['lastRecomputedAt']);
			$actual[$name] = ['score' => $score, 'calls' => $calls];
		}

		$this->assertMatchesGolden(file: self::GOLDEN, actual: $actual);
	}//end testScopesMatchGolden()
}//end class
