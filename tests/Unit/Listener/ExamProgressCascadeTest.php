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
 * @spec openspec/specs/enrolment/spec.md#requirement-a-students-exam-progress-reads-in-words
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\ExamProgressCascade;
use OCA\Learniq\Service\Exam\ExamProgressRestamp;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Listener\ExamProgressCascade
 * @uses   \OCA\Learniq\Service\ListenerSchemaResolver
 */
class ExamProgressCascadeTest extends TestCase {

	/**
	 * The students restamped, in order.
	 *
	 * @var list<string>
	 */
	private array $restamped = [];

	/**
	 * A published result restamps its student; another transition does not.
	 *
	 * @return void
	 */
	public function testAPublishedResultRestampsItsStudent(): void {
		$cascade = $this->cascade();

		$cascade->handle($this->transitioned(schema: 'grade-entry', to: 'published', object: ['learnerId' => 'milan']));
		$cascade->handle($this->transitioned(schema: 'grade-entry', to: 'revised', object: ['learnerId' => 'other']));

		self::assertSame(expected: ['milan'], actual: $this->restamped);
	}//end testAPublishedResultRestampsItsStudent()

	/**
	 * A changed or new sitting restamps every student of its cohorts once.
	 *
	 * @return void
	 */
	public function testASittingRestampsItsCohorts(): void {
		$cascade = $this->cascade();

		$cascade->handle($this->transitioned(schema: 'exam-sitting', to: 'cancelled', object: ['cohortIds' => ['mt3a', 'mt3b']]));
		self::assertSame(expected: ['milan', 'sara', 'noor'], actual: $this->restamped);

		$this->restamped = [];
		$event = $this->createMock(originalClassName: ObjectCreatedEvent::class);
		$event->method('getObject')->willReturn(OrEntityFactory::make(['id' => 's-9', 'cohortIds' => ['mt3b']], 'exam-sitting'));
		$cascade->handle($event);
		self::assertSame(expected: ['sara', 'noor'], actual: $this->restamped);
	}//end testASittingRestampsItsCohorts()

	/**
	 * A new object of another schema is ignored.
	 *
	 * @return void
	 */
	public function testAnotherNewObjectIsIgnored(): void {
		$event = $this->createMock(originalClassName: ObjectCreatedEvent::class);
		$event->method('getObject')->willReturn(OrEntityFactory::make(['id' => 'c-1', 'cohortIds' => ['mt3a']], 'cohort'));

		$this->cascade()->handle($event);

		self::assertSame(expected: [], actual: $this->restamped);
	}//end testAnotherNewObjectIsIgnored()

	/**
	 * A transitioned event.
	 *
	 * @param string               $schema The schema.
	 * @param string               $to     The state reached.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function transitioned(string $schema, string $to, array $object): ObjectTransitionedEvent {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($object);
		$event = $this->createMock(originalClassName: ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($entity);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn($schema);
		$event->method('getTo')->willReturn($to);

		return $event;
	}//end transitioned()

	/**
	 * The cascade over two cohorts.
	 *
	 * @return ExamProgressCascade
	 */
	private function cascade(): ExamProgressCascade {
		$restamp = $this->createMock(originalClassName: ExamProgressRestamp::class);
		$restamp->method('restamp')->willReturnCallback(
			function (string $learnerUserId): bool {
				$this->restamped[] = $learnerUserId;
				return true;
			}
		);
		$cohorts = [
			['id' => 'mt3a', 'learnerIds' => ['milan', 'sara']],
			['id' => 'mt3b', 'learnerIds' => ['sara', 'noor']],
		];
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config = []) use ($cohorts): array {
				self::assertSame(expected: 'cohort', actual: $config['filters']['schema']);
				return array_map(static fn (array $row): ObjectEntity => OrEntityFactory::make($row, 'cohort'), $cohorts);
			}
		);

		return new ExamProgressCascade(restamp: $restamp, objectService: $objects, schemas: TransitionScope::resolver());
	}//end cascade()
}//end class
