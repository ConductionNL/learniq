<?php

/**
 * Unit tests for CompetencyAlignmentNormaliser (goal-alignment-depth).
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
 * @spec openspec/changes/goal-alignment-depth/tasks.md#task-2-competencyalignmentnormaliser
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\CompetencyAlignmentNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * Pins every list rule of the normaliser: derive, follow, refuse, read.
 */
class CompetencyAlignmentNormaliserTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var CompetencyAlignmentNormaliser
	 */
	private CompetencyAlignmentNormaliser $normaliser;

	/**
	 * Resolved goals as the listener hands them to problem().
	 *
	 * @var array<string, array{code: string, levels: array<int, string>}>
	 */
	private array $goals = [
		'goal-a' => ['code' => 'K1', 'levels' => ['introduce', 'practise', 'master']],
		'goal-b' => ['code' => 'K2', 'levels' => ['introduce', 'practise', 'master']],
		'goal-c' => ['code' => 'WP1', 'levels' => ['nog-niet-competent', 'competent']],
	];

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->normaliser = new CompetencyAlignmentNormaliser();

	}//end setUp()

	/**
	 * Normalising blanks a whitespace depth to null, keeps a missing goal as
	 * an empty id, and ignores a non-array value.
	 *
	 * @return void
	 */
	public function testNormaliseCleansEntries(): void {
		self::assertSame([], $this->normaliser->normalise('not a list'));
		self::assertSame(
			[
				['competencyId' => 'goal-a', 'depth' => null],
				['competencyId' => '', 'depth' => 'master'],
				['competencyId' => 'goal-b', 'depth' => 'practise'],
			],
			$this->normaliser->normalise(
				[
					['competencyId' => ' goal-a ', 'depth' => '  '],
					['depth' => 'master'],
					['competencyId' => 'goal-b', 'depth' => 'practise'],
				]
			)
		);

	}//end testNormaliseCleansEntries()

	/**
	 * The flat list keeps alignment order and drops duplicates and empty ids.
	 *
	 * @return void
	 */
	public function testIdsFromAlignmentsKeepsOrderWithoutDuplicates(): void {
		$alignments = $this->normaliser->normalise(
			[
				['competencyId' => 'goal-b', 'depth' => 'introduce'],
				['competencyId' => 'goal-a', 'depth' => null],
				['competencyId' => 'goal-b', 'depth' => 'master'],
				['depth' => 'master'],
			]
		);

		self::assertSame(['goal-b', 'goal-a'], $this->normaliser->idsFromAlignments($alignments));

	}//end testIdsFromAlignmentsKeepsOrderWithoutDuplicates()

	/**
	 * Editing only the flat list keeps a kept goal's depth, gives a new goal
	 * depth null and drops a removed goal.
	 *
	 * @return void
	 */
	public function testAlignmentsFollowAFlatListEdit(): void {
		$stored = $this->normaliser->normalise(
			[
				['competencyId' => 'goal-a', 'depth' => 'master'],
				['competencyId' => 'goal-b', 'depth' => 'introduce'],
			]
		);

		self::assertSame(
			[
				['competencyId' => 'goal-c', 'depth' => null],
				['competencyId' => 'goal-a', 'depth' => 'master'],
			],
			$this->normaliser->alignmentsFollowIds($stored, ['goal-c', 'goal-a', 'goal-c', ''])
		);

	}//end testAlignmentsFollowAFlatListEdit()

	/**
	 * A valid list, including an open depth, has no problem.
	 *
	 * @return void
	 */
	public function testValidAlignmentsHaveNoProblem(): void {
		$alignments = $this->normaliser->normalise(
			[
				['competencyId' => 'goal-a', 'depth' => 'practise'],
				['competencyId' => 'goal-c', 'depth' => null],
			]
		);

		self::assertNull($this->normaliser->problem($alignments, $this->goals));

	}//end testValidAlignmentsHaveNoProblem()

	/**
	 * A depth from another framework is refused, naming the goal and the
	 * levels its own framework allows.
	 *
	 * @return void
	 */
	public function testUnknownDepthNamesTheAllowedLevels(): void {
		$problem = $this->normaliser->problem(
			$this->normaliser->normalise([['competencyId' => 'goal-c', 'depth' => 'master']]),
			$this->goals
		);

		self::assertNotNull($problem);
		self::assertStringContainsString('WP1', $problem);
		self::assertStringContainsString('nog-niet-competent, competent', $problem);

	}//end testUnknownDepthNamesTheAllowedLevels()

	/**
	 * An unknown goal and a goal aligned twice are refused.
	 *
	 * @return void
	 */
	public function testUnknownAndDuplicateGoalsAreRefused(): void {
		$unknown = $this->normaliser->problem(
			$this->normaliser->normalise([['competencyId' => 'goal-x', 'depth' => null]]),
			$this->goals
		);
		self::assertStringContainsString('does not exist', (string) $unknown);

		$twice = $this->normaliser->problem(
			$this->normaliser->normalise(
				[
					['competencyId' => 'goal-a', 'depth' => 'introduce'],
					['competencyId' => 'goal-a', 'depth' => 'master'],
				]
			),
			$this->goals
		);
		self::assertStringContainsString('K1', (string) $twice);
		self::assertStringContainsString('twice', (string) $twice);

	}//end testUnknownAndDuplicateGoalsAreRefused()

	/**
	 * A row with no alignments reads its flat list as alignments without
	 * depth; a row with alignments reads them, deduplicated.
	 *
	 * @return void
	 */
	public function testEffectiveAlignmentsFallBackToTheFlatList(): void {
		self::assertSame(
			[['competencyId' => 'goal-a', 'depth' => null]],
			$this->normaliser->effectiveAlignments(['competencyIds' => ['goal-a']])
		);

		self::assertSame(
			[['competencyId' => 'goal-b', 'depth' => 'practise']],
			$this->normaliser->effectiveAlignments(
				[
					'competencyIds'        => ['goal-a'],
					'competencyAlignments' => [
						['competencyId' => 'goal-b', 'depth' => 'practise'],
						['competencyId' => 'goal-b', 'depth' => 'master'],
					],
				]
			)
		);

		self::assertSame([], $this->normaliser->effectiveAlignments([]));

	}//end testEffectiveAlignmentsFallBackToTheFlatList()

	/**
	 * A stored depth the framework no longer knows reads as null, and the
	 * link stays.
	 *
	 * @return void
	 */
	public function testStaleDepthReadsAsNullWithoutDroppingTheLink(): void {
		$row = ['competencyAlignments' => [['competencyId' => 'goal-a', 'depth' => 'expert']]];

		self::assertSame(
			[['competencyId' => 'goal-a', 'depth' => null]],
			$this->normaliser->effectiveAlignments($row, ['goal-a' => ['introduce', 'practise', 'master']])
		);
		self::assertSame(
			[['competencyId' => 'goal-a', 'depth' => 'expert']],
			$this->normaliser->effectiveAlignments($row)
		);

	}//end testStaleDepthReadsAsNullWithoutDroppingTheLink()
}//end class
