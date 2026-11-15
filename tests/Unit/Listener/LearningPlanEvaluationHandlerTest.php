<?php

/**
 * Unit tests for LearningPlanEvaluationHandler::handle(): which transitions it
 * acts on, and what it writes to the parent LearningPlan.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/learning-plan/specs/learning-plan/spec.md#requirement-learningplanevaluation-schema-persistence-and-goal-status-update
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\LearningPlanEvaluationHandler;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Learniq\Listener\LearningPlanEvaluationHandler
 * @uses   \OCA\Learniq\Service\ListenerSchemaResolver
 * @uses   \OCA\Learniq\Service\ListenerSlugContract
 */
class LearningPlanEvaluationHandlerTest extends TestCase {

	/** @var ObjectService&MockObject */
	private ObjectService $objectService;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private LearningPlanEvaluationHandler $handler;

	/**
	 * Build the handler over a mocked ObjectService and logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->handler = new LearningPlanEvaluationHandler($this->objectService, $this->logger, TransitionScope::resolver());
	}//end setUp()

	/**
	 * A transition event for an evaluation.
	 *
	 * @param array<string, mixed> $evaluation The evaluation data.
	 * @param string               $to         The target state.
	 * @param string               $schema     The schema slug.
	 * @param string               $register   The register slug.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function event(array $evaluation, string $to = 'recorded', string $schema = 'learning-plan-evaluation', string $register = 'learniq'): ObjectTransitionedEvent {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('jsonSerialize')->willReturn($evaluation);

		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($object);
		$event->method('getTo')->willReturn($to);
		$event->method('getSchema')->willReturn($schema);
		$event->method('getRegister')->willReturn($register);
		return $event;
	}//end event()

	/**
	 * Other events, registers, schemas and target states are ignored.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnored(): void {
		$this->objectService->expects(self::never())->method('findAll');
		$this->objectService->expects(self::never())->method('saveObject');

		$this->handler->handle(new Event());
		$this->handler->handle($this->event(['learningPlanId' => 'p-1'], register: 'other'));
		$this->handler->handle($this->event(['learningPlanId' => 'p-1'], schema: 'learning-plan'));
		$this->handler->handle($this->event(['learningPlanId' => 'p-1'], to: 'draft'));
	}//end testOtherEventsAreIgnored()

	/**
	 * An evaluation without a plan id is skipped with a warning.
	 *
	 * @return void
	 */
	public function testAnEvaluationWithoutAPlanIsSkipped(): void {
		$this->objectService->expects(self::never())->method('findAll');
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('has no learningPlanId'));

		$this->handler->handle($this->event(['goalOutcomes' => [['goalId' => 'g-1', 'outcome' => 'met']]]));
	}//end testAnEvaluationWithoutAPlanIsSkipped()

	/**
	 * A plan that cannot be found is skipped with a warning naming it.
	 *
	 * @return void
	 */
	public function testAMissingPlanIsSkipped(): void {
		$this->objectService->expects(self::once())->method('findAll')->with(
			[
				'ids' => ['p-1'],
				'filters' => ['register' => 'learniq', 'schema' => 'learning-plan'],
				'limit' => 1,
			]
		)->willReturn([]);
		$this->objectService->expects(self::never())->method('saveObject');
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('not found'), ['id' => 'p-1']);

		$this->handler->handle($this->event(['learningPlanId' => 'p-1']));
	}//end testAMissingPlanIsSkipped()

	/**
	 * Outcomes update the goals of an entity plan, and the next review date is set.
	 *
	 * @return void
	 */
	public function testOutcomesAndNextReviewAreSaved(): void {
		$plan = $this->createMock(ObjectEntity::class);
		$plan->method('jsonSerialize')->willReturn(
			[
				'id' => 'p-1',
				'goals' => [
					['goalId' => 'g-1', 'status' => 'open'],
					['goalId' => 'g-2', 'status' => 'open'],
					['goalId' => 'g-3', 'status' => 'open'],
				],
			]
		);
		$this->objectService->method('findAll')->willReturn([$plan]);
		$this->objectService->expects(self::once())->method('saveObject')->with(
			[
				'id' => 'p-1',
				'goals' => [
					['goalId' => 'g-1', 'status' => 'met'],
					['goalId' => 'g-2', 'status' => 'open'],
					['goalId' => 'g-3', 'status' => 'open'],
				],
				'nextReviewAt' => '2027-01-15',
			],
			self::anything(),
			'learniq',
			'learning-plan'
		);
		$this->logger->expects(self::once())->method('info')->with(self::stringContains('Updated LearningPlan'), ['id' => 'p-1']);

		$this->handler->handle(
			$this->event(
				[
					'learningPlanId' => 'p-1',
					'nextReviewAt' => '2027-01-15',
					'goalOutcomes' => [
						['goalId' => 'g-1', 'outcome' => 'met'],
						['goalId' => 'g-2'],
						['outcome' => 'dropped'],
					],
				]
			)
		);
	}//end testOutcomesAndNextReviewAreSaved()

	/**
	 * A next review date alone, on an array plan, is a change worth saving.
	 *
	 * @return void
	 */
	public function testANextReviewAloneIsSaved(): void {
		$this->objectService->method('findAll')->willReturn([['id' => 'p-2']]);
		$this->objectService->expects(self::once())->method('saveObject')->with(
			['id' => 'p-2', 'nextReviewAt' => '2027-02-01', 'goals' => []],
			self::anything(),
			'learniq',
			'learning-plan'
		);

		$this->handler->handle($this->event(['learningPlanId' => 'p-2', 'nextReviewAt' => '2027-02-01']));
	}//end testANextReviewAloneIsSaved()

	/**
	 * Without a status change or review date nothing is saved.
	 *
	 * @return void
	 */
	public function testNoChangeSavesNothing(): void {
		$this->objectService->method('findAll')->willReturn([['id' => 'p-3', 'goals' => [['goalId' => 'g-1', 'status' => 'met']]]]);
		$this->objectService->expects(self::never())->method('saveObject');
		$this->logger->expects(self::once())->method('info')->with(self::stringContains('No changes needed'), ['id' => 'p-3']);

		$this->handler->handle(
			$this->event(['learningPlanId' => 'p-3', 'goalOutcomes' => [['goalId' => 'g-1', 'outcome' => 'met'], ['goalId' => 'g-9', 'outcome' => 'continued']]])
		);
	}//end testNoChangeSavesNothing()
}//end class
