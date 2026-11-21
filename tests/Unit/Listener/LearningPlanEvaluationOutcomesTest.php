<?php

/**
 * Tests for how LearningPlanEvaluationHandler applies recorded outcomes to goals.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
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
 * @spec openspec/changes/learning-plan/specs/learning-plan/spec.md#requirement-learningplanevaluation-schema-persistence-and-goal-status-update
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\LearningPlanEvaluationHandler;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Only an outcome that maps to a different status changes a goal.
 */
class LearningPlanEvaluationOutcomesTest extends TestCase {

	/**
	 * Apply outcomes through the handler's private step.
	 *
	 * @param array<int, mixed>    $goals      The goals.
	 * @param array<string, mixed> $outcomeMap The outcomes by goal id.
	 *
	 * @return array{goals: array<int, mixed>, changed: bool}
	 */
	private function apply(array $goals, array $outcomeMap): array {
		$handler = new LearningPlanEvaluationHandler(
			$this->createMock(ObjectService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ListenerSchemaResolver::class)
		);
		$method = new \ReflectionMethod($handler, 'applyOutcomesToGoals');
		$method->setAccessible(true);

		return $method->invoke($handler, $goals, $outcomeMap);
	}//end apply()

	/**
	 * Mapped outcomes set the status; unmentioned, `continued`, unknown and
	 * same-status outcomes, and goals without an id, change nothing.
	 *
	 * @return void
	 */
	public function testOnlyAMappedDifferentStatusChangesAGoal(): void {
		$goals = [
			3 => ['goalId' => 'g-met', 'status' => 'open', 'title' => 'Lezen'],
			5 => ['goalId' => 'g-same', 'status' => 'adjusted'],
			6 => ['goalId' => 'g-continued', 'status' => 'open'],
			7 => ['goalId' => 'g-unknown'],
			8 => ['goalId' => 'g-none'],
			9 => ['title' => 'no id'],
			10 => ['goalId' => 'g-dropped'],
			11 => 'not a goal',
		];
		$outcomes = ['g-met' => 'met', 'g-same' => 'adjusted', 'g-continued' => 'continued', 'g-unknown' => 'exploded', 'g-dropped' => 'dropped'];

		$result = $this->apply(goals: $goals, outcomeMap: $outcomes);

		$expected = $goals;
		$expected[3]['status'] = 'met';
		$expected[10]['status'] = 'dropped';
		self::assertSame(['goals' => $expected, 'changed' => true], $result);

		self::assertSame(['goals' => [$goals[5], $goals[6]], 'changed' => false], $this->apply(goals: [$goals[5], $goals[6]], outcomeMap: $outcomes));
		self::assertSame(['goals' => [], 'changed' => false], $this->apply(goals: [], outcomeMap: $outcomes));
	}//end testOnlyAMappedDifferentStatusChangesAGoal()
}//end class
