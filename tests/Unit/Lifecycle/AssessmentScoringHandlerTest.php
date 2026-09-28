<?php

/**
 * Tests for AssessmentScoringHandler as an OpenRegister lifecycle guard.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\AssessmentScoringHandler;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The submit guard refuses an attempt whose parent Assessment can not be
 * resolved (learniq#983), and the scoring it hands the action is unchanged.
 *
 * Fixtures are the object OpenRegister hands a guard: the flat AssessmentResult
 * with the lifecycle field already at `submitted`.
 */
class AssessmentScoringHandlerTest extends TestCase {

	/**
	 * Build the handler over the given Assessment and Item fixtures.
	 *
	 * @param TestCase $test The test that owns the mocks.
	 * @param array<string,mixed>|null $assessment The parent Assessment, or null when unreachable.
	 * @param array<string,array<string,mixed>> $items Item fixtures by uuid.
	 *
	 * @return AssessmentScoringHandler
	 */
	public static function makeHandler(TestCase $test, ?array $assessment, array $items = []): AssessmentScoringHandler {
		$objectService = $test->getMockBuilder(ObjectService::class)->disableOriginalConstructor()->getMock();
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($assessment, $items): array {
				$uuid = ($config['ids'][0] ?? '');
				if ($config['filters']['schema'] === 'exam') {
					if ($assessment === null) {
						return [];
					}

					return [$assessment];
				}

				if (isset($items[$uuid]) === true) {
					return [$items[$uuid]];
				}

				return [];
			}
		);

		$logger = $test->getMockBuilder(LoggerInterface::class)->getMock();

		return new AssessmentScoringHandler($objectService, $logger);
	}//end makeHandler()

	/**
	 * The submitted AssessmentResult as OpenRegister saves it.
	 *
	 * @return array<string,mixed>
	 */
	public static function submittedResult(): array {
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
	 * OpenRegister only runs a guard that implements its interface.
	 *
	 * @return void
	 */
	public function testImplementsTheGuardInterface(): void {
		self::assertInstanceOf(LifecycleGuardInterface::class, self::makeHandler($this, null));
	}//end testImplementsTheGuardInterface()

	/**
	 * An unreachable parent Assessment refuses the submit, as the old guard did.
	 *
	 * @return void
	 */
	public function testUnreachableAssessmentRefusesSubmit(): void {
		$result = self::makeHandler($this, null)->check(self::submittedResult(), 'submit', 'learner-1');

		self::assertFalse($result->isAllowed());
		self::assertNotSame('', (string)$result->getMessage());
	}//end testUnreachableAssessmentRefusesSubmit()

	/**
	 * A reachable parent Assessment allows the submit.
	 *
	 * @return void
	 */
	public function testReachableAssessmentAllowsSubmit(): void {
		$handler = self::makeHandler($this, ['id' => 'exam-1', 'itemRefs' => []]);

		self::assertTrue($handler->check(self::submittedResult(), 'submit', 'learner-1')->isAllowed());
	}//end testReachableAssessmentAllowsSubmit()

	/**
	 * An attempt with no responses has nothing to score and is allowed.
	 *
	 * @return void
	 */
	public function testAttemptWithoutResponsesIsAllowed(): void {
		$object = self::submittedResult();
		$object['responses'] = [];

		self::assertTrue(self::makeHandler($this, null)->check($object, 'submit', 'learner-1')->isAllowed());
	}//end testAttemptWithoutResponsesIsAllowed()

	/**
	 * Answers are stored as `{value: X}`: TakeAssessmentView and the portal
	 * write that shape, ItemAnalysisService and AssessmentScoringView read it.
	 * Scoring compares X with the correct response, so a right answer earns its
	 * points and a wrong one does not; a bare value still scores as before.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-auto-scoring-reads-the-stored-answer-shape
	 */
	public function testTheStoredValueShapeIsScored(): void {
		$handler = self::makeHandler(
			$this,
			['id' => 'exam-1', 'itemRefs' => [['itemId' => 'item-choice', 'points' => 2], ['itemId' => 'item-order', 'points' => 4]]],
			[
				'item-choice' => ['interactionType' => 'choice', 'correctResponse' => 'B', 'maxScore' => 1],
				'item-order' => ['interactionType' => 'order', 'correctResponse' => ['C', 'A', 'B'], 'maxScore' => 4],
				'item-wrong' => ['interactionType' => 'choice', 'correctResponse' => 'B', 'maxScore' => 1],
				'item-bare' => ['interactionType' => 'choice', 'correctResponse' => 'B', 'maxScore' => 1],
			]
		);
		$result = self::submittedResult();
		$result['responses'] = [
			['itemId' => 'item-choice', 'response' => ['value' => 'B'], 'autoScore' => null],
			['itemId' => 'item-order', 'response' => ['value' => ['C', 'A', 'B']], 'autoScore' => null],
			['itemId' => 'item-wrong', 'response' => ['value' => 'A'], 'autoScore' => null],
			['itemId' => 'item-bare', 'response' => 'B', 'autoScore' => null],
		];

		$scored = $handler->score($result);

		self::assertNotNull($scored);
		self::assertSame(2.0, $scored['responses'][0]['autoScore']);
		self::assertSame(4.0, $scored['responses'][1]['autoScore']);
		self::assertSame(0.0, $scored['responses'][2]['autoScore']);
		self::assertSame(1.0, $scored['responses'][3]['autoScore']);
	}//end testTheStoredValueShapeIsScored()
}//end class
