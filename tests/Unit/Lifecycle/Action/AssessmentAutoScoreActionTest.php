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
use OCA\Learniq\Lifecycle\AssessmentScoringHandler;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($assessment, $items): array {
				if ($config['filters']['schema'] === 'exam') {
					return array_filter([$assessment]);
				}

				$uuid = ($config['ids'][0] ?? '');

				return array_filter([($items[$uuid] ?? null)]);
			}
		);

		return new AssessmentAutoScoreAction(new AssessmentScoringHandler($objectService, new NullLogger()));
	}//end makeAction()

	/**
	 * The submitted AssessmentResult as OpenRegister saves it.
	 *
	 * @return array<string,mixed>
	 */
	private static function submittedResult(): array {
		return [
			'id' => 'result-1',
			'lifecycle' => 'submitted',
			'assessmentId' => 'exam-1',
			'tenant_id' => 'tenant-1',
			'responses' => [
				['itemId' => 'item-choice', 'response' => 'B', 'autoScore' => 99],
				['itemId' => 'item-essay', 'response' => 'My essay.', 'autoScore' => 99],
			],
		];
	}//end submittedResult()

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
		$previous = self::submittedResult();
		$previous['lifecycle'] = 'in-progress';

		$saved = $action->execute(self::submittedResult(), $previous, [], AssessmentAutoScoreAction::class);

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

		$this->makeAction(null)->execute(self::submittedResult(), [], [], AssessmentAutoScoreAction::class);
	}//end testUnreachableAssessmentThrows()
}//end class
