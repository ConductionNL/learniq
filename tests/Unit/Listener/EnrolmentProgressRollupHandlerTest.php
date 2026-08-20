<?php

/**
 * Learniq EnrolmentProgressRollupHandler unit tests.
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
 * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#requirement-enrolment-carries-a-declared-lesson-progress-roll-up
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Listener\EnrolmentProgressRollupHandler;
use OCA\Learniq\Progress\EnrolmentProgressEvaluator;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EnrolmentProgressRollupHandler::handle() on ObjectCreatedEvent<LessonCompletion>.
 */
class EnrolmentProgressRollupHandlerTest extends TestCase {

	/**
	 * Recorded saveObject() calls.
	 *
	 * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
	 */
	private array $savedObjects = [];

	/**
	 * Resolver turning the entity's numeric register/schema ids into slugs.
	 *
	 * @var ListenerSchemaResolver&MockObject
	 */
	private ListenerSchemaResolver&MockObject $schemaResolver;

	/**
	 * Reset fixtures before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->savedObjects = [];
		$this->schemaResolver = $this->createMock(ListenerSchemaResolver::class);

	}//end setUp()

	/**
	 * Stub the resolver the way OpenRegister behaves in production: the entity
	 * carries numeric ids and the resolver turns them into slugs.
	 *
	 * @param string $schemaSlug The slug the resolver resolves the schema id to.
	 *
	 * @return void
	 */
	private function stubResolver(string $schemaSlug): void {
		$this->schemaResolver->method('registerSlug')->willReturn('learniq');
		$this->schemaResolver->method('schemaSlug')->willReturn($schemaSlug);

	}//end stubResolver()

	/**
	 * Build a handler with mocked collaborators.
	 *
	 * @param array<int, array<string, mixed>> $enrolments Enrolment rows returned for the active-enrolment lookup.
	 * @param array{progressPercent: int, completedLessonCount: int, totalPublishedLessonCount: int} $evaluated Result the mocked evaluator returns.
	 *
	 * @return EnrolmentProgressRollupHandler
	 */
	private function makeHandler(array $enrolments, array $evaluated): EnrolmentProgressRollupHandler {
		$objectService = $this->createMock(ObjectService::class);

		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($enrolments) {
				if ($config['schema'] === 'enrolment') {
					return $enrolments;
				}

				return [];
			}
		);

		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null): ObjectEntity {
				$this->savedObjects[] = [
					'register' => (string)$register,
					'schema' => (string)$schema,
					'object' => $object,
				];
				return OrEntityFactory::make($object, (string)$schema, (string)$register);
			}
		);

		$evaluator = $this->createMock(EnrolmentProgressEvaluator::class);
		$evaluator->method('evaluate')->willReturn($evaluated);

		return new EnrolmentProgressRollupHandler($objectService, $evaluator, $this->schemaResolver);
	}//end makeHandler()

	/**
	 * Build a mocked ObjectCreatedEvent<LessonCompletion>.
	 *
	 * @param array<string, mixed> $data The LessonCompletion jsonSerialize() payload.
	 *
	 * @return ObjectCreatedEvent
	 */
	private function makeEvent(array $data): ObjectCreatedEvent {
		$objectEntity = OrEntityFactory::make($data, '1280', '9');
		$this->stubResolver('lesson-completion');

		$event = $this->createMock(ObjectCreatedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);

		return $event;
	}//end makeEvent()

	/**
	 * A new LessonCompletion for a learner with an active Enrolment triggers
	 * a recompute and saves progressPercent onto that Enrolment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#scenario-progress-percentage-recomputes-when-a-lesson-is-completed
	 */
	public function testNewCompletionTriggersRecompute(): void {
		$enrolment = ['id' => 'enrolment-1', 'learnerId' => 'learner-1', 'courseId' => 'course-1', 'lifecycle' => 'active'];

		$handler = $this->makeHandler(
			enrolments: [$enrolment],
			evaluated: ['progressPercent' => 40, 'completedLessonCount' => 4, 'totalPublishedLessonCount' => 10]
		);

		$handler->handle(
			$this->makeEvent(
				['learnerId' => 'learner-1', 'lessonId' => 'lesson-4', 'courseId' => 'course-1', 'source' => 'xapi']
			)
		);

		self::assertCount(1, $this->savedObjects);
		self::assertSame('enrolment', $this->savedObjects[0]['schema']);
		self::assertSame('enrolment-1', $this->savedObjects[0]['object']['id']);
		self::assertSame(40, $this->savedObjects[0]['object']['progressPercent']);

	}//end testNewCompletionTriggersRecompute()

	/**
	 * A learner with no active Enrolment for the completion's course is
	 * skipped without error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#requirement-enrolment-carries-a-declared-lesson-progress-roll-up
	 */
	public function testNoActiveEnrolmentIsSkipped(): void {
		$handler = $this->makeHandler(
			enrolments: [],
			evaluated: ['progressPercent' => 0, 'completedLessonCount' => 0, 'totalPublishedLessonCount' => 0]
		);

		$handler->handle(
			$this->makeEvent(
				['learnerId' => 'learner-1', 'lessonId' => 'lesson-4', 'courseId' => 'course-1', 'source' => 'xapi']
			)
		);

		self::assertCount(0, $this->savedObjects);

	}//end testNoActiveEnrolmentIsSkipped()

	/**
	 * An event on a different schema is ignored entirely.
	 *
	 * @return void
	 */
	public function testUnrelatedSchemaIsIgnored(): void {
		$handler = $this->makeHandler(
			enrolments: [['id' => 'enrolment-1', 'learnerId' => 'learner-1', 'courseId' => 'course-1']],
			evaluated: ['progressPercent' => 50, 'completedLessonCount' => 5, 'totalPublishedLessonCount' => 10]
		);

		$objectEntity = OrEntityFactory::make(['id' => 'x'], '1281', '9');
		$this->stubResolver('xapi-statement');

		$event = $this->createMock(ObjectCreatedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);

		$handler->handle($event);

		self::assertCount(0, $this->savedObjects);

	}//end testUnrelatedSchemaIsIgnored()
}//end class
