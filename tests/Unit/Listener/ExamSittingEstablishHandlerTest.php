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
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/specs/exam-board/spec.md#requirement-the-committee-establishes-the-results-of-a-sitting
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\ExamSittingEstablishHandler;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Learniq\Listener\ExamSittingEstablishHandler
 * @uses   \OCA\Learniq\Service\ListenerSchemaResolver
 */
class ExamSittingEstablishHandlerTest extends TestCase {

	/**
	 * The grade entries saved.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The filters findAll was asked.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * Every provisional result of the sitting gets the sitting's moment and actor.
	 *
	 * @return void
	 */
	public function testEveryProvisionalResultIsEstablished(): void {
		$handler = $this->handler(
			entries: [
				['id' => 'ge-1', 'examSittingId' => 's-1', 'value' => 6.0],
				['id' => 'ge-2', 'examSittingId' => 's-1', 'value' => 4.5],
			]
		);

		$handler->handle($this->established(fields: ['establishedAt' => '2026-10-08T10:00:00+00:00', 'establishedBy' => 'commissie-1']));

		self::assertSame(expected: ['ge-1', 'ge-2'], actual: array_column($this->saved, 'id'));
		self::assertSame(expected: ['2026-10-08T10:00:00+00:00', '2026-10-08T10:00:00+00:00'], actual: array_column($this->saved, 'establishedAt'));
		self::assertSame(expected: ['commissie-1', 'commissie-1'], actual: array_column($this->saved, 'establishedBy'));
		self::assertSame(expected: 6.0, actual: $this->saved[0]['value']);
		self::assertSame(
			expected: ['register' => 'learniq', 'schema' => 'grade-entry', 'examSittingId' => 's-1', 'tenant_id' => 'school-1'],
			actual: $this->queries[0]
		);
	}//end testEveryProvisionalResultIsEstablished()

	/**
	 * A result already established, or one of another sitting, is left alone.
	 *
	 * @return void
	 */
	public function testEstablishedAndForeignResultsAreLeftAlone(): void {
		$handler = $this->handler(
			entries: [
				['id' => 'ge-1', 'examSittingId' => 's-1', 'establishedAt' => '2026-09-01T00:00:00+00:00', 'establishedBy' => 'eerder'],
				['id' => 'ge-2', 'examSittingId' => 's-2'],
				['id' => 'ge-3', 'examSittingId' => 's-1'],
			]
		);

		$handler->handle($this->established(fields: ['establishedAt' => '2026-10-08T10:00:00+00:00', 'establishedBy' => 'commissie-1']));

		self::assertSame(expected: ['ge-3'], actual: array_column($this->saved, 'id'));
	}//end testEstablishedAndForeignResultsAreLeftAlone()

	/**
	 * Without the stamped moment or actor nothing is written.
	 *
	 * @return void
	 */
	public function testAnUnstampedSittingChangesNothing(): void {
		$this->handler(entries: [['id' => 'ge-1', 'examSittingId' => 's-1']])->handle($this->established(fields: ['establishedBy' => 'commissie-1']));

		self::assertSame(expected: [], actual: $this->saved);
		self::assertSame(expected: [], actual: $this->queries);
	}//end testAnUnstampedSittingChangesNothing()

	/**
	 * Another transition, or another schema, is ignored.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnored(): void {
		$handler = $this->handler(entries: [['id' => 'ge-1', 'examSittingId' => 's-1']]);

		$handler->handle($this->established(fields: ['establishedAt' => 'x', 'establishedBy' => 'y'], to: 'cancelled'));
		$handler->handle($this->established(fields: ['establishedAt' => 'x', 'establishedBy' => 'y'], schema: 'exam-period'));

		self::assertSame(expected: [], actual: $this->saved);
	}//end testOtherEventsAreIgnored()

	/**
	 * A transitioned sitting event.
	 *
	 * @param array<string, mixed> $fields The sitting's stamped fields.
	 * @param string               $to     The state reached.
	 * @param string               $schema The schema slug.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function established(array $fields, string $to = 'established', string $schema = 'exam-sitting'): ObjectTransitionedEvent {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(['id' => 's-1', 'tenant_id' => 'school-1', 'lifecycle' => $to] + $fields);

		$event = $this->createMock(originalClassName: ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($entity);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn($schema);
		$event->method('getTo')->willReturn($to);

		return $event;
	}//end established()

	/**
	 * The handler over the given grade entries.
	 *
	 * @param list<array<string, mixed>> $entries The stored grade entries.
	 *
	 * @return ExamSittingEstablishHandler
	 */
	private function handler(array $entries): ExamSittingEstablishHandler {
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($entries): array {
				$this->queries[] = $config['filters'];
				return array_map(static fn (array $row): ObjectEntity => OrEntityFactory::make($row, 'grade-entry'), $entries);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions()): ObjectEntity {
				self::assertSame(expected: 'grade-entry', actual: $options->target->schema);
				$this->saved[] = (array) $object;
				return OrEntityFactory::make((array) $object, 'grade-entry');
			}
		);

		return new ExamSittingEstablishHandler(objectService: $objects, schemas: TransitionScope::resolver(), logger: new NullLogger());
	}//end handler()
}//end class
