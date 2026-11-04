<?php

/**
 * Learniq CurriculumCoverageCalculator golden tests.
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
 * @spec openspec/specs/competency/spec.md#requirement-coverage-counts-leaf-goals-planned-and-assessed-separately
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\CompetencyAlignmentNormaliser;
use OCA\Learniq\Service\CompetencyTree;
use OCA\Learniq\Service\CurriculumCoverageCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins compute() for seeded random frameworks against
 * tests/Unit/Service/golden/CurriculumCoverage.json. With RECORD_GOLDEN=1 the
 * file is written instead of compared.
 *
 * @covers \OCA\Learniq\Service\CurriculumCoverageCalculator
 * @covers \OCA\Learniq\Service\CoverageBuckets
 * @uses   \OCA\Learniq\Service\CompetencyAlignmentNormaliser
 * @uses   \OCA\Learniq\Service\CompetencyTree
 */
class CurriculumCoverageGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/CurriculumCoverage.json';

	/**
	 * The generator state.
	 *
	 * @var integer
	 */
	private int $seed = 1;

	/**
	 * Seeds and goal counts.
	 *
	 * @return array<string,array{0:int,1:int}>
	 */
	public static function sets(): array {
		return [
			'no goals' => [1, 0],
			'one goal' => [2, 1],
			'small tree' => [3, 6],
			'medium tree' => [4, 14],
			'large tree' => [5, 30],
			'another large tree' => [6, 30],
		];
	}//end sets()

	/**
	 * compute() matches the recorded set.
	 *
	 * @param int $seed  Generator seed.
	 * @param int $goals Goal count.
	 *
	 * @return void
	 */
	#[DataProvider('sets')]
	public function testComputeMatchesGolden(int $seed, int $goals): void {
		$this->seed = $seed;
		$framework  = [
			'id' => 'fw-' . $seed,
			'proficiencyLevels' => [['levelId' => 'master', 'order' => 3], ['levelId' => 'introduce'], ['levelId' => 'practise', 'order' => 2], 'junk', ['order' => 9]],
		];
		if ($seed % 2 === 0) {
			$framework['tenant_id'] = 't-' . $seed;
		}

		$goalRows = $this->goals(count: $goals);
		$ids      = array_column($goalRows, 'id');
		$actual   = (new CurriculumCoverageCalculator(new CompetencyAlignmentNormaliser(), new CompetencyTree()))
			->compute($framework, $goalRows, $this->references(ids: $ids), $this->references(ids: $ids), '2026-05-06T07:08:09+00:00');

		$name   = (string)$this->dataName();
		$golden = [];
		if (file_exists(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		$actual = json_decode((string)json_encode($actual, JSON_PRESERVE_ZERO_FRACTION), true);
		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::GOLDEN, json_encode($golden, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
		}

		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], $actual);
	}//end testComputeMatchesGolden()

	/**
	 * The next number from the seeded generator, from 0 to $max - 1.
	 *
	 * @param int $max Upper bound (exclusive).
	 *
	 * @return int
	 */
	private function next(int $max): int {
		$this->seed = ((($this->seed * 1103515245) + 12345) % 2147483648);
		return (intdiv($this->seed, 65536) % $max);
	}//end next()

	/**
	 * A random goal tree.
	 *
	 * @param int $count Goals.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function goals(int $count): array {
		$years    = ['Groep 5', 'groep  6 ', 'Groep 10', '1', 'Groep 5'];
		$subjects = [null, 'rekenen', 'taal', 'Engels', null];
		$goals    = [];
		for ($i = 0; $i < $count; $i++) {
			$goal = ['id' => 'g-' . $i, 'parentId' => null, 'code' => 'C' . $this->next(50), 'order' => $this->next(5), 'applicableYears' => [], 'subjectId' => $subjects[$this->next(5)]];
			if ($i > 0 && $this->next(3) !== 0) {
				$goal['parentId'] = 'g-' . $this->next($i);
			}

			for ($y = $this->next(3); $y > 0; $y--) {
				$goal['applicableYears'][] = $years[$this->next(5)];
			}

			if ($this->next(9) === 0) {
				$goal['lifecycle'] = 'archived';
			}

			$goals[] = $goal;
		}

		return $goals;
	}//end goals()

	/**
	 * Random planned or assessed references to the goals.
	 *
	 * @param list<string> $ids The goal ids.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function references(array $ids): array {
		$depths = ['master', 'introduce', 'practise', 'expert', null];
		$rows   = [];
		for ($r = 0; $r < (count($ids) + 2); $r++) {
			$row = ['competencyAlignments' => []];
			for ($a = $this->next(4); $a > 0 && $ids !== []; $a--) {
				$row['competencyAlignments'][] = ['competencyId' => $ids[$this->next(count($ids))], 'depth' => $depths[$this->next(5)]];
			}

			if ($this->next(4) === 0 && $ids !== []) {
				$row = ['competencyIds' => [$ids[$this->next(count($ids))], 'unknown']];
			}

			if ($this->next(6) === 0) {
				$row['lifecycle'] = ['retired', 'archived', 'closed', 'active'][$this->next(4)];
			}

			$rows[] = $row;
		}

		return $rows;
	}//end references()
}//end class
