<?php

/**
 * Unit tests for GradeRollupHandler on AssessmentResult -> graded: the concept
 * GradeEntry it creates and the back-link it writes.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-21
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Grading\GradeFormulaEvaluator;
use OCA\Learniq\Grading\GradeVisibilityResolver;
use OCA\Learniq\Listener\GradeRollupHandler;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Learniq\Listener\GradeRollupHandler
 * @uses   \OCA\Learniq\Service\ListenerSchemaResolver
 * @uses   \OCA\Learniq\Service\ListenerSlugContract
 */
class GradeRollupAssessmentResultTest extends TestCase {

	/** @var ObjectService&MockObject */
	private ObjectService $objectService;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/**
	 * Recorded saveObject() calls: [schema, object].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $saved = [];

	private GradeRollupHandler $handler;

	/**
	 * Build the handler over a recording ObjectService.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved = [];
		$this->objectService = $this->createMock(ObjectService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->handler = new GradeRollupHandler(
			$this->objectService,
			$this->createMock(GradeFormulaEvaluator::class),
			$this->createMock(GradeVisibilityResolver::class),
			$this->createMock(ITimeFactory::class),
			TransitionScope::resolver(),
			$this->logger
		);
	}//end setUp()

	/**
	 * Record saves; a grade-entry save answers an entity with the given data.
	 *
	 * @param array<string, mixed> $savedEntry What the saved GradeEntry serialises to.
	 *
	 * @return void
	 */
	private function recordSaves(array $savedEntry): void {
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null) use ($savedEntry): ObjectEntity {
				self::assertSame('learniq', $register);
				$this->saved[] = [(string)$schema, $object];
				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($savedEntry);
				return $entity;
			}
		);
	}//end recordSaves()

	/**
	 * An AssessmentResult -> graded event.
	 *
	 * @param array<string, mixed> $result The result data.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function graded(array $result): ObjectTransitionedEvent {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('jsonSerialize')->willReturn($result);
		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('assessment-result');
		$event->method('getTo')->willReturn('graded');
		$event->method('getObject')->willReturn($object);
		return $event;
	}//end graded()

	/**
	 * A complete result.
	 *
	 * @return array<string, mixed>
	 */
	private static function gradedResult(): array {
		return [
			'id' => 'r-1',
			'learnerId' => 'learner-1',
			'totalScore' => '7',
			'tenant_id' => 't-1',
			'gradeEntryComponentId' => 'comp-1',
			'curriculumPlanId' => 'plan-1',
			'gradeScaleId' => 'scale-1',
		];
	}//end gradedResult()

	/**
	 * A graded result creates a concept auto-graded GradeEntry and links it back onto the result.
	 *
	 * @return void
	 */
	public function testAGradedResultCreatesAConceptEntryAndLinksIt(): void {
		$this->recordSaves(['id' => 'ge-1']);
		$this->logger->expects(self::never())->method('error');

		$this->handler->handle($this->graded(self::gradedResult()));

		self::assertCount(2, $this->saved);
		[$schema, $entry] = $this->saved[0];
		self::assertSame('grade-entry', $schema);
		self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $entry['gradedAt']));
		unset($entry['gradedAt']);
		self::assertSame(
			[
				'learnerId' => 'learner-1',
				'curriculumPlanId' => 'plan-1',
				'componentId' => 'comp-1',
				'sourceKind' => 'assessment-result',
				'assessmentResultId' => 'r-1',
				'value' => 7.0,
				'gradeScaleId' => 'scale-1',
				'grader' => 'auto',
				'tenant_id' => 't-1',
				'lifecycle' => 'concept',
			],
			$entry
		);
		self::assertSame(['assessment-result', self::gradedResult() + ['gradeEntryId' => 'ge-1']], $this->saved[1]);
	}//end testAGradedResultCreatesAConceptEntryAndLinksIt()

	/**
	 * Without a scale the entry's scale is empty; without a saved id there is no back-link.
	 *
	 * @return void
	 */
	public function testNoScaleAndNoSavedIdWritesOnlyTheEntry(): void {
		$this->recordSaves([]);
		$result = self::gradedResult();
		unset($result['gradeScaleId'], $result['tenant_id']);

		$this->handler->handle($this->graded($result));

		self::assertCount(1, $this->saved);
		self::assertSame('', $this->saved[0][1]['gradeScaleId']);
		self::assertSame('', $this->saved[0][1]['tenant_id']);
	}//end testNoScaleAndNoSavedIdWritesOnlyTheEntry()

	/**
	 * A result missing a learner, score, component or plan, or already linked, writes nothing.
	 *
	 * @return void
	 */
	public function testIncompleteOrLinkedResultsWriteNothing(): void {
		$this->objectService->expects(self::never())->method('saveObject');
		$this->logger->expects(self::never())->method('error');

		foreach (['learnerId', 'totalScore', 'gradeEntryComponentId', 'curriculumPlanId'] as $field) {
			$result = self::gradedResult();
			unset($result[$field]);
			$this->handler->handle($this->graded($result));
		}

		$this->handler->handle($this->graded(['learnerId' => ''] + self::gradedResult()));
		$this->handler->handle($this->graded(self::gradedResult() + ['gradeEntryId' => 'ge-old']));
	}//end testIncompleteOrLinkedResultsWriteNothing()
}//end class
