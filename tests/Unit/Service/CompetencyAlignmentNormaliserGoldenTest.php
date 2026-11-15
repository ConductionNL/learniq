<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\CompetencyAlignmentNormaliser;
use OCA\Learniq\Tests\Support\GoldenFile;
use PHPUnit\Framework\TestCase;

/**
 * Pins how goal alignments are normalised, refused and read, against
 * golden/CompetencyAlignmentNormaliser.json.
 *
 * @covers \OCA\Learniq\Service\CompetencyAlignmentNormaliser
 * @uses \OCA\Learniq\Support\TextValue
 */
class CompetencyAlignmentNormaliserGoldenTest extends TestCase {
	use GoldenFile;

	private const GOLDEN = __DIR__ . '/golden/CompetencyAlignmentNormaliser.json';

	/**
	 * Every value answers as recorded.
	 *
	 * @return void
	 */
	public function testValuesMatchGolden(): void {
		$normaliser = new CompetencyAlignmentNormaliser();
		$actual = [];
		$raws = [
			'not a list' => 'g-1',
			'null' => null,
			'empty' => [],
			'a scalar entry' => ['g-1'],
			'goal and depth' => [['competencyId' => 'g-1', 'depth' => 'basis']],
			'no depth' => [['competencyId' => 'g-1']],
			'blank depth' => [['competencyId' => 'g-1', 'depth' => '  ']],
			'depth not text' => [['competencyId' => 'g-1', 'depth' => 3]],
			'goal not text' => [['competencyId' => 7, 'depth' => 'basis']],
			'goal needs a trim' => [['competencyId' => ' g-1 ', 'depth' => ' basis ']],
			'several' => [['competencyId' => 'g-1', 'depth' => 'basis'], [], ['competencyId' => 'g-1', 'depth' => null]],
		];
		foreach ($raws as $name => $raw) {
			$actual['normalise: ' . $name] = $normaliser->normalise(raw: $raw);
		}

		$goals = [
			'g-1' => ['code' => 'K1', 'levels' => ['basis', 'gevorderd']],
			'g-2' => ['code' => 'K2', 'levels' => []],
			'123' => ['code' => 'K3', 'levels' => ['basis']],
		];
		$row = static fn (string $goal, ?string $depth): array => ['competencyId' => $goal, 'depth' => $depth];
		$lists = [
			'none' => [],
			'fine' => [$row('g-1', 'basis'), $row('g-2', null)],
			'blank goal' => [$row('', null)],
			'unknown goal' => [$row('g-1', null), $row('g-9', null)],
			'twice' => [$row('g-1', 'basis'), $row('g-1', 'gevorderd')],
			'all-digit goal' => [['competencyId' => '123', 'depth' => null]],
			'unknown depth' => [$row('g-1', 'expert')],
			'depth on a goal without levels' => [$row('g-2', 'basis')],
		];
		foreach ($lists as $name => $alignments) {
			$actual['problem: ' . $name] = $normaliser->problem(alignments: $alignments, goals: $goals);
		}

		$rows = [
			'nothing' => [[], []],
			'alignments' => [['competencyAlignments' => [$row('g-1', 'basis'), $row('g-2', null)], 'competencyIds' => ['g-9']], []],
			'only blank alignments fall back to the list' => [['competencyAlignments' => [$row('', 'basis')], 'competencyIds' => ['g-1', 'g-1', ' g-2 ', 7, '']], []],
			'flat list' => [['competencyIds' => ['g-1', 'g-2']], []],
			'twice keeps the first' => [['competencyAlignments' => [$row('g-1', 'basis'), $row('g-1', 'gevorderd')]], []],
			'all-digit goal' => [['competencyAlignments' => [$row('123', 'oud'), $row('123', 'basis')]], ['123' => ['basis']]],
			'known depth' => [['competencyAlignments' => [$row('g-1', 'basis')]], ['g-1' => ['basis']]],
			'depth no longer known' => [['competencyAlignments' => [$row('g-1', 'oud')]], ['g-1' => ['basis']]],
			'goal without known levels keeps its depth' => [['competencyAlignments' => [$row('g-1', 'oud')]], ['g-2' => ['basis']]],
			'no depth with known levels' => [['competencyAlignments' => [$row('g-1', null)]], ['g-1' => ['basis']]],
		];
		foreach ($rows as $name => [$data, $levels]) {
			$actual['effective: ' . $name] = $normaliser->effectiveAlignments(row: $data, levelsByGoal: $levels);
		}

		$this->assertMatchesGolden(file: self::GOLDEN, actual: $actual);
	}//end testValuesMatchGolden()
}//end class
