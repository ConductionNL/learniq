<?php

/**
 * Unit tests for CurriculumCoverageCalculator and CompetencyTree
 * (curriculum-coverage-rollup).
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
 *
 * @spec openspec/changes/archive/2026-09-28-curriculum-coverage-rollup/tasks.md#task-2-curriculumcoveragecalculator
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\CompetencyAlignmentNormaliser;
use OCA\Learniq\Service\CompetencyTree;
use OCA\Learniq\Service\CurriculumCoverageCalculator;
use PHPUnit\Framework\TestCase;

/**
 * One fixture tree exercises every counting and bucketing rule.
 *
 * Tree: domain `dom` (years "Groep 5", subject rekenen) holds leaves k1, k2
 * and an archived leaf; k3 is a root leaf with no years and no subject.
 */
class CurriculumCoverageCalculatorTest extends TestCase {

	private const NOW = '2026-09-27T12:00:00+00:00';

	/**
	 * The service under test.
	 *
	 * @var CurriculumCoverageCalculator
	 */
	private CurriculumCoverageCalculator $calculator;

	/**
	 * Build the calculator with its real collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calculator = new CurriculumCoverageCalculator(
			normaliser: new CompetencyAlignmentNormaliser(),
			tree: new CompetencyTree(),
		);

	}//end setUp()

	/**
	 * The framework: three ordered levels.
	 *
	 * @return array<string, mixed>
	 */
	private function framework(): array {
		return [
			'id'                => 'fw',
			'tenant_id'         => 't1',
			'proficiencyLevels' => [
				['levelId' => 'master', 'order' => 3],
				['levelId' => 'introduce', 'order' => 1],
				['levelId' => 'practise', 'order' => 2],
			],
		];
	}//end framework()

	/**
	 * The goal tree.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function goals(): array {
		return [
			['id' => 'k3', 'parentId' => null, 'code' => 'K3', 'order' => 2, 'applicableYears' => [], 'subjectId' => null],
			['id' => 'k2', 'parentId' => 'dom', 'code' => 'K2', 'order' => 2, 'applicableYears' => [], 'subjectId' => null],
			['id' => 'dom', 'parentId' => null, 'code' => 'D1', 'order' => 1, 'applicableYears' => ['Groep  5 '], 'subjectId' => 'rekenen'],
			['id' => 'k1', 'parentId' => 'dom', 'code' => 'K1', 'order' => 1, 'applicableYears' => [], 'subjectId' => null],
			['id' => 'kx', 'parentId' => 'dom', 'code' => 'KX', 'order' => 3, 'lifecycle' => 'archived'],
		];
	}//end goals()

	/**
	 * Lessons and courses.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function planned(): array {
		return [
			['competencyAlignments' => [['competencyId' => 'k1', 'depth' => 'practise'], ['competencyId' => 'k2', 'depth' => 'introduce']]],
			['lifecycle' => 'retired', 'competencyAlignments' => [['competencyId' => 'k3', 'depth' => 'master']]],
			['competencyIds' => ['k1']],
			['competencyAlignments' => [['competencyId' => 'k1', 'depth' => 'master'], ['competencyId' => 'dom', 'depth' => 'master']]],
			['competencyAlignments' => [['competencyId' => 'k2', 'depth' => 'expert']]],
		];
	}//end planned()

	/**
	 * Assignments and assessments.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function assessed(): array {
		return [
			['lifecycle' => 'closed', 'competencyAlignments' => [['competencyId' => 'k1', 'depth' => 'practise']]],
			['lifecycle' => 'archived', 'competencyAlignments' => [['competencyId' => 'k2', 'depth' => 'master']]],
		];
	}//end assessed()

	/**
	 * Compute the fixture, rows by bucket key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function rows(): array {
		$rows = [];
		foreach ($this->calculator->compute($this->framework(), $this->goals(), $this->planned(), $this->assessed(), self::NOW) as $row) {
			$rows[$this->calculator->bucketKey($row)] = $row;
		}

		return $rows;
	}//end rows()

	/**
	 * Planned and assessed are counted separately; the gap and the
	 * uncovered goal are listed.
	 *
	 * @return void
	 */
	public function testCountsPlannedAndAssessedSeparately(): void {
		$total = $this->rows()['fw|*|all|'];

		self::assertSame(3, $total['goalCount']);
		self::assertSame(2, $total['plannedCount']);
		self::assertSame(1, $total['assessedCount']);
		self::assertSame(['k2'], $total['plannedNotAssessedIds']);
		self::assertSame(['k3'], $total['uncoveredIds']);
		self::assertSame(1, $total['plannedNotAssessedCount']);
		self::assertSame(1, $total['uncoveredCount']);
		self::assertSame(66.7, $total['plannedPercent']);
		self::assertSame(33.3, $total['assessedPercent']);
		self::assertSame('t1', $total['tenant_id']);
		self::assertSame(self::NOW, $total['lastRecomputedAt']);

	}//end testCountsPlannedAndAssessedSeparately()

	/**
	 * Only leaves count, in tree order; a domain reference covers nothing.
	 *
	 * @return void
	 */
	public function testOnlyLeafGoalsCount(): void {
		$total = $this->rows()['fw|*|all|'];

		self::assertSame(['k1', 'k2', 'k3'], array_column($total['goals'], 'competencyId'));

	}//end testOnlyLeafGoalsCount()

	/**
	 * An archived goal is left out; a retired lesson and an archived
	 * assessment do not count; a closed assignment does.
	 *
	 * @return void
	 */
	public function testArchivedGoalsAndRetiredReferencesAreLeftOut(): void {
		$goals = array_column($this->rows()['fw|*|all|']['goals'], null, 'competencyId');

		self::assertArrayNotHasKey('kx', $goals);
		self::assertFalse($goals['k3']['planned']);
		self::assertFalse($goals['k2']['assessed']);
		self::assertTrue($goals['k1']['assessed']);

	}//end testArchivedGoalsAndRetiredReferencesAreLeftOut()

	/**
	 * Leaves inherit the domain's years and subject; the label is
	 * canonicalised.
	 *
	 * @return void
	 */
	public function testYearsAndSubjectsInheritFromTheNearestAncestor(): void {
		$rows = $this->rows();

		self::assertSame(['k1', 'k2'], array_column($rows['fw|groep 5|subject|rekenen']['goals'], 'competencyId'));
		self::assertSame('groep 5', $rows['fw|groep 5|subject|rekenen']['year']);
		self::assertSame(['k3'], array_column($rows['fw|groep 5|none|']['goals'], 'competencyId'));

		$tree = new CompetencyTree();
		$built = $tree->build($this->goals());
		self::assertSame(['groep 5'], $tree->effectiveYears($built, 'k1'));
		self::assertSame('rekenen', $tree->effectiveSubject($built, 'k2'));
		self::assertSame([], $tree->effectiveYears($built, 'k3'));
		self::assertNull($tree->effectiveSubject($built, 'k3'));

	}//end testYearsAndSubjectsInheritFromTheNearestAncestor()

	/**
	 * Every year × subject bucket exists, total first; a goal with no years
	 * counts in every year.
	 *
	 * @return void
	 */
	public function testBucketsCoverEveryYearAndSubjectPlusTotals(): void {
		$rows = $this->rows();

		self::assertSame(
			['fw|*|all|', 'fw|*|subject|rekenen', 'fw|*|none|', 'fw|groep 5|all|', 'fw|groep 5|subject|rekenen', 'fw|groep 5|none|'],
			array_keys($rows)
		);
		self::assertSame(3, $rows['fw|groep 5|all|']['goalCount']);
		self::assertSame(2, $rows['fw|*|subject|rekenen']['goalCount']);
		self::assertSame(1, $rows['fw|*|none|']['goalCount']);

	}//end testBucketsCoverEveryYearAndSubjectPlusTotals()

	/**
	 * The deepest depth wins by level order; a depth the framework does
	 * not declare reads as none and never beats a real one.
	 *
	 * @return void
	 */
	public function testDeepestDepthWinsAndStaleDepthReadsAsNone(): void {
		$total = $this->rows()['fw|*|all|'];
		$goals = array_column($total['goals'], null, 'competencyId');

		self::assertSame('master', $goals['k1']['plannedDepth']);
		self::assertSame(3, $goals['k1']['plannedRefs']);
		self::assertSame('introduce', $goals['k2']['plannedDepth']);
		self::assertSame(2, $goals['k2']['plannedRefs']);
		self::assertSame(
			[['depth' => 'introduce', 'count' => 1], ['depth' => 'master', 'count' => 1]],
			$total['plannedByDepth']
		);
		self::assertSame([['depth' => 'practise', 'count' => 1]], $total['assessedByDepth']);

	}//end testDeepestDepthWinsAndStaleDepthReadsAsNone()

	/**
	 * A framework with no goals still has exactly one total row.
	 *
	 * @return void
	 */
	public function testEmptyFrameworkStillGetsATotalRow(): void {
		$rows = $this->calculator->compute(['id' => 'fw-empty', 'proficiencyLevels' => []], [], [], [], self::NOW);

		self::assertCount(1, $rows);
		self::assertNull($rows[0]['year']);
		self::assertSame('all', $rows[0]['subjectScope']);
		self::assertSame(0, $rows[0]['goalCount']);
		self::assertSame(0.0, $rows[0]['plannedPercent']);
		self::assertSame(0.0, $rows[0]['assessedPercent']);

	}//end testEmptyFrameworkStillGetsATotalRow()

	/**
	 * A course with only a flat goal list counts as planned without depth.
	 *
	 * @return void
	 */
	public function testFlatOnlyReferencesCountWithoutDepth(): void {
		$rows = $this->calculator->compute(
			['id' => 'fw2', 'proficiencyLevels' => [['levelId' => 'competent', 'order' => 1]]],
			[['id' => 'g', 'code' => 'G1']],
			[['competencyIds' => ['g', 'g']]],
			[],
			self::NOW
		);

		self::assertSame(1, $rows[0]['plannedCount']);
		self::assertNull($rows[0]['goals'][0]['plannedDepth']);
		self::assertSame(1, $rows[0]['goals'][0]['plannedRefs']);
		self::assertSame([['depth' => null, 'count' => 1]], $rows[0]['plannedByDepth']);

	}//end testFlatOnlyReferencesCountWithoutDepth()

	/**
	 * A parent cycle and a canonical label never break the tree reader.
	 *
	 * @return void
	 */
	public function testTreeIsCycleSafeAndLabelsAreCanonical(): void {
		$tree  = new CompetencyTree();
		$built = $tree->build(
			[
				['id' => 'a', 'parentId' => 'b', 'applicableYears' => []],
				['id' => 'b', 'parentId' => 'a', 'applicableYears' => []],
			]
		);

		self::assertSame([], $tree->effectiveYears($built, 'a'));
		self::assertSame('leerjaar 2', $tree->canonicalYear("  Leerjaar\t2 "));
		self::assertSame('', $tree->canonicalYear(7));

	}//end testTreeIsCycleSafeAndLabelsAreCanonical()
}//end class
