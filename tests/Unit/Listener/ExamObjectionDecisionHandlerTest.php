<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/specs/exam-board/spec.md#requirement-a-student-objects-against-a-result-and-the-committee-decides-within-its-term
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\ExamObjectionDecisionHandler;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Learniq\Listener\ExamObjectionDecisionHandler
 * @uses   \OCA\Learniq\Service\ListenerSchemaResolver
 */
class ExamObjectionDecisionHandlerTest extends TestCase {

	/**
	 * What happened, in order: transitions and saves.
	 *
	 * @var list<string>
	 */
	private array $steps = [];

	/**
	 * The objects saved, by schema.
	 *
	 * @var list<array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * A changed result revises the published entry, saves the new value and republishes it.
	 *
	 * @return void
	 */
	public function testAChangedResultGoesThroughReviseAndRepublish(): void {
		$handler = $this->handler(entryLifecycle: 'published');

		$handler->handle($this->decided(fields: ['decisionOutcome' => 'result-changed', 'newValue' => 6.5, 'decisionRationale' => 'Herbeoordeeld.']));

		self::assertSame(
			expected: ['transition ge-1 revise', 'save grade-entry', 'transition ge-1 republish', 'save exam-objection'],
			actual: $this->steps
		);
		self::assertSame(expected: 6.5, actual: $this->saved[0]['object']['value']);
		self::assertSame(expected: 'obj-1', actual: $this->saved[0]['object']['examObjectionId']);
		self::assertSame(expected: 'ge-1', actual: $this->saved[1]['object']['resultingGradeEntryId']);
	}//end testAChangedResultGoesThroughReviseAndRepublish()

	/**
	 * An entry already being revised is not revised again.
	 *
	 * @return void
	 */
	public function testARevisedEntryIsOnlyRepublished(): void {
		$this->handler(entryLifecycle: 'revised')->handle($this->decided(fields: ['decisionOutcome' => 'result-changed', 'newValue' => 7]));

		self::assertSame(expected: ['save grade-entry', 'transition ge-1 republish', 'save exam-objection'], actual: $this->steps);
	}//end testARevisedEntryIsOnlyRepublished()

	/**
	 * An upheld result changes nothing.
	 *
	 * @return void
	 */
	public function testAnUpheldResultChangesNothing(): void {
		$this->handler(entryLifecycle: 'published')->handle($this->decided(fields: ['decisionOutcome' => 'result-upheld']));

		self::assertSame(expected: [], actual: $this->steps);
	}//end testAnUpheldResultChangesNothing()

	/**
	 * A missing grade entry changes nothing.
	 *
	 * @return void
	 */
	public function testAMissingEntryChangesNothing(): void {
		$this->handler(entryLifecycle: null)->handle($this->decided(fields: ['decisionOutcome' => 'result-changed', 'newValue' => 7]));

		self::assertSame(expected: [], actual: $this->steps);
	}//end testAMissingEntryChangesNothing()

	/**
	 * A decided objection event.
	 *
	 * @param array<string, mixed> $fields The decision fields.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function decided(array $fields): ObjectTransitionedEvent {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(['id' => 'obj-1', 'gradeEntryId' => 'ge-1', 'lifecycle' => 'decided'] + $fields);

		$event = $this->createMock(originalClassName: ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($entity);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('exam-objection');
		$event->method('getTo')->willReturn('decided');

		return $event;
	}//end decided()

	/**
	 * The handler over a grade entry in a given state.
	 *
	 * @param string|null $entryLifecycle The entry's state, or null when it does not exist.
	 *
	 * @return ExamObjectionDecisionHandler
	 */
	private function handler(?string $entryLifecycle): ExamObjectionDecisionHandler {
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$state   = $entryLifecycle;
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options = null) use (&$state): ?ObjectEntity {
				self::assertSame(expected: 'grade-entry', actual: $options?->schema);
				return ($state === null) ? null : OrEntityFactory::make(['id' => (string) $id, 'value' => 5.0, 'lifecycle' => $state], 'grade-entry');
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions()): ObjectEntity {
				$schema        = (string) $options->target->schema;
				$this->steps[] = 'save ' . $schema;
				$this->saved[] = ['schema' => $schema, 'object' => (array) $object];
				return OrEntityFactory::make((array) $object, $schema);
			}
		);
		$engine = $this->createMock(originalClassName: TransitionEngine::class);
		$engine->method('transition')->willReturnCallback(
			function (string $objectId, string $action) use (&$state): ObjectEntity {
				$this->steps[] = 'transition ' . $objectId . ' ' . $action;
				$state         = ($action === 'revise') ? 'revised' : 'published';
				return OrEntityFactory::make(['id' => $objectId, 'lifecycle' => $state], 'grade-entry');
			}
		);

		return new ExamObjectionDecisionHandler(
			objectService: $objects,
			transitionEngine: $engine,
			schemas: TransitionScope::resolver(),
			logger: new NullLogger()
		);
	}//end handler()
}//end class
