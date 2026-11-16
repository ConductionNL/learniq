<?php

/**
 * Learniq MarkRubricCompletionGuard unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/assignments/spec.md#requirement-a-teachers-mark-is-not-released-while-a-rubric-criterion-is-unscored
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\MarkRubricCompletionGuard;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for MarkRubricCompletionGuard (Submission.return).
 *
 * @covers \OCA\Learniq\Lifecycle\MarkRubricCompletionGuard
 */
class MarkRubricCompletionGuardTest extends TestCase {

	private const ASSIGNMENT_ID = 'assignment-1';
	private const RUBRIC_ID = 'rubric-1';

	/**
	 * The two-criterion Rubric of the scenario.
	 *
	 * @var array<string,mixed>
	 */
	private array $rubric = [
		'id' => self::RUBRIC_ID,
		'criteria' => [
			['criterionId' => 'structuur', 'label' => 'Structuur', 'weight' => 1, 'levels' => []],
			['criterionId' => 'argumentatie', 'label' => 'Argumentatie', 'weight' => 1, 'levels' => []],
		],
	];

	/**
	 * A guard over the given Assignment and Rubric fixtures (null = not found).
	 *
	 * @param array<string,mixed>|null $assignment The Assignment.
	 * @param array<string,mixed>|null $rubric     The Rubric.
	 * @param bool                     $throws     Whether reading throws.
	 *
	 * @return MarkRubricCompletionGuard
	 */
	private function makeGuard(?array $assignment, ?array $rubric, bool $throws=false): MarkRubricCompletionGuard {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options = null) use ($assignment, $rubric, $throws) {
				$schema = $options?->schema;
				if ($throws === true) {
					throw new RuntimeException('database gone');
				}

				if ($schema === 'assignment' && $assignment !== null && $id === $assignment['id']) {
					return OrEntityFactory::make($assignment, 'assignment');
				}

				if ($schema === 'rubric' && $rubric !== null && $id === $rubric['id']) {
					return OrEntityFactory::make($rubric, 'rubric');
				}

				return null;
			}
		);

		return new MarkRubricCompletionGuard($objectService, $this->createMock(LoggerInterface::class));
	}//end makeGuard()

	/**
	 * A Submission with the given rubric scores.
	 *
	 * @param list<array<string,mixed>> $scores The rubricScores.
	 *
	 * @return array<string,mixed>
	 */
	private function submission(array $scores): array {
		return [
			'id' => 'submission-1',
			'assignmentId' => self::ASSIGNMENT_ID,
			'lifecycle' => 'submitted',
			'rubricScores' => $scores,
		];
	}//end submission()

	/**
	 * Scenario: a teacher forgets one criterion — the return is refused and names it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/assignments/spec.md#scenario-a-teacher-forgets-one-criterion
	 */
	public function testReturnWithAnUnscoredCriterionIsRefusedAndNamesIt(): void {
		$guard = $this->makeGuard(['id' => self::ASSIGNMENT_ID, 'rubricId' => self::RUBRIC_ID], $this->rubric);

		$result = $guard->check($this->submission([['criterionId' => 'structuur', 'levelId' => 'goed', 'points' => 3]]), 'return', 'teacher');

		self::assertFalse($result->isAllowed());
		self::assertSame('Score every rubric criterion before releasing the mark. Unscored: Argumentatie.', $result->getMessage());
	}//end testReturnWithAnUnscoredCriterionIsRefusedAndNamesIt()

	/**
	 * Scenario: every criterion is scored — the mark is released.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/assignments/spec.md#scenario-every-criterion-is-scored
	 */
	public function testReturnWithEveryCriterionScoredIsAllowed(): void {
		$guard = $this->makeGuard(['id' => self::ASSIGNMENT_ID, 'rubricId' => self::RUBRIC_ID], $this->rubric);

		$result = $guard->check(
			$this->submission(
				[
					['criterionId' => 'structuur', 'levelId' => 'goed', 'points' => 3],
					['criterionId' => 'argumentatie', 'levelId' => 'voldoende', 'points' => 2],
				]
			),
			'return',
			'teacher'
		);

		self::assertTrue($result->isAllowed());
	}//end testReturnWithEveryCriterionScoredIsAllowed()

	/**
	 * Nothing scored names every criterion, in rubric order; a label-less one by its id.
	 *
	 * @return void
	 */
	public function testNothingScoredNamesEveryCriterionInRubricOrder(): void {
		$rubric = $this->rubric;
		$rubric['criteria'][] = ['criterionId' => 'bronnen', 'weight' => 1, 'levels' => []];
		$guard = $this->makeGuard(['id' => self::ASSIGNMENT_ID, 'rubricId' => self::RUBRIC_ID], $rubric);

		$result = $guard->check($this->submission([]), 'return', 'teacher');

		self::assertSame('Score every rubric criterion before releasing the mark. Unscored: Structuur, Argumentatie, bronnen.', $result->getMessage());
	}//end testNothingScoredNamesEveryCriterionInRubricOrder()

	/**
	 * An assignment without a rubric has nothing to complete.
	 *
	 * @return void
	 */
	public function testAnAssignmentWithoutARubricAllowsTheReturn(): void {
		$guard = $this->makeGuard(['id' => self::ASSIGNMENT_ID], null);

		self::assertTrue($guard->check($this->submission([]), 'return', 'teacher')->isAllowed());
	}//end testAnAssignmentWithoutARubricAllowsTheReturn()

	/**
	 * A rubric that is named but cannot be read refuses the release (fail closed).
	 *
	 * @return void
	 */
	public function testAnUnreadableRubricRefusesTheReturn(): void {
		$guard = $this->makeGuard(['id' => self::ASSIGNMENT_ID, 'rubricId' => 'gone'], $this->rubric);

		$result = $guard->check($this->submission([]), 'return', 'teacher');

		self::assertFalse($result->isAllowed());
		self::assertSame('The rubric of this assignment cannot be read, so the mark cannot be released.', $result->getMessage());
	}//end testAnUnreadableRubricRefusesTheReturn()

	/**
	 * A read that throws refuses the release instead of letting it through.
	 *
	 * @return void
	 */
	public function testAReadFailureRefusesTheReturn(): void {
		$guard = $this->makeGuard(['id' => self::ASSIGNMENT_ID, 'rubricId' => self::RUBRIC_ID], $this->rubric, true);

		self::assertFalse($guard->check($this->submission([]), 'return', 'teacher')->isAllowed());
	}//end testAReadFailureRefusesTheReturn()
}//end class
