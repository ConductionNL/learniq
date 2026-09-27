<?php

/**
 * Tests for the AssessmentResult submit action that writes the auto scores.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle\Action
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle\Action;

use OCA\Learniq\Lifecycle\Action\AssessmentAutoScoreAction;
use OCA\Learniq\Tests\Unit\Lifecycle\AssessmentScoringHandlerTest;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * OpenRegister calls guards by value, so the auto scores the old guard wrote
 * into its context now come from this action's return value (learniq#983).
 * OpenRegister's executor is not loadable here; the test drives the action
 * directly against the copied interface.
 */
class AssessmentAutoScoreActionTest extends TestCase {

	/**
	 * Build the action over the given Assessment fixture.
	 *
	 * @param array<string,mixed>|null $assessment The parent Assessment, or null when unreachable.
	 *
	 * @return AssessmentAutoScoreAction
	 */
	private function makeAction(?array $assessment): AssessmentAutoScoreAction {
		$items = [
			'item-choice' => ['id' => 'item-choice', 'interactionType' => 'choice', 'correctResponse' => 'b', 'maxScore' => 2],
			'item-essay' => ['id' => 'item-essay', 'interactionType' => 'extendedText', 'correctResponse' => null, 'maxScore' => 5],
		];

		return new AssessmentAutoScoreAction(AssessmentScoringHandlerTest::makeHandler($this, $assessment, $items));
	}//end makeAction()

	/**
	 * The action is one OpenRegister's executor can run.
	 *
	 * @return void
	 */
	public function testImplementsTheActionInterface(): void {
		self::assertInstanceOf(LifecycleActionInterface::class, $this->makeAction(null));
	}//end testImplementsTheActionInterface()

	/**
	 * The returned object carries the server-computed auto scores, overwriting
	 * whatever the client sent, and keeps every other field.
	 *
	 * @return void
	 */
	public function testReturnedObjectCarriesTheAutoScores(): void {
		$action = $this->makeAction(['id' => 'exam-1', 'itemRefs' => [['itemId' => 'item-choice', 'points' => 4]]]);
		$previous = AssessmentScoringHandlerTest::submittedResult();
		$previous['lifecycle'] = 'in-progress';

		$saved = $action->execute(AssessmentScoringHandlerTest::submittedResult(), $previous, [], AssessmentAutoScoreAction::class);

		self::assertSame(4.0, $saved['responses'][0]['autoScore']);
		self::assertNull($saved['responses'][1]['autoScore']);
		self::assertSame('submitted', $saved['lifecycle']);
		self::assertSame('result-1', $saved['id']);
	}//end testReturnedObjectCarriesTheAutoScores()

	/**
	 * An unreachable Assessment throws rather than saving client scores.
	 *
	 * @return void
	 */
	public function testUnreachableAssessmentThrows(): void {
		$this->expectException(RuntimeException::class);

		$this->makeAction(null)->execute(AssessmentScoringHandlerTest::submittedResult(), [], [], AssessmentAutoScoreAction::class);
	}//end testUnreachableAssessmentThrows()
}//end class
