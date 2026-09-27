<?php

/**
 * Unit tests for CompetencyAlignmentListener (goal-alignment-depth).
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
 * @spec openspec/changes/goal-alignment-depth/tasks.md#task-3-competencyalignmentlistener-and-its-registration
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\CompetencyAlignmentListener;
use OCA\Learniq\Service\CompetencyAlignmentNormaliser;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\ObjectRowReader;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Drives the listener through real OpenRegister event stubs.
 */
class CompetencyAlignmentListenerTest extends TestCase {

	/**
	 * Framework loads seen by the reader double, to prove the cache.
	 *
	 * @var int
	 */
	private int $frameworkLoads = 0;

	/**
	 * Build the listener with a reader that knows three goals in two
	 * frameworks.
	 *
	 * @param string $slug        The schema slug the resolver reports.
	 * @param bool   $resolverThrows Whether the resolver fails.
	 *
	 * @return CompetencyAlignmentListener
	 */
	private function listener(string $slug='lesson', bool $resolverThrows=false): CompetencyAlignmentListener {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		if ($resolverThrows === true) {
			$resolver->method('guardSchemaSlug')->willThrowException(new RuntimeException('no schema'));
		} else {
			$resolver->method('guardSchemaSlug')->willReturn($slug);
		}

		$rows = [
			'competency'           => [
				'goal-a' => ['code' => 'K1', 'frameworkId' => 'fw-slo'],
				'goal-b' => ['code' => 'K2', 'frameworkId' => 'fw-slo'],
				'goal-c' => ['code' => 'WP1', 'frameworkId' => 'fw-sbb'],
			],
			'competency-framework' => [
				'fw-slo' => ['proficiencyLevels' => [['levelId' => 'introduce'], ['levelId' => 'practise'], ['levelId' => 'master']]],
				'fw-sbb' => ['proficiencyLevels' => [['levelId' => 'nog-niet-competent'], ['levelId' => 'competent']]],
			],
		];
		$reader = $this->createMock(ObjectRowReader::class);
		$reader->method('load')->willReturnCallback(
			function (string $schema, string $id) use ($rows): ?array {
				if ($schema === 'competency-framework') {
					$this->frameworkLoads++;
				}

				return ($rows[$schema][$id] ?? null);
			}
		);

		return new CompetencyAlignmentListener(
			schemaResolver: $resolver,
			reader: $reader,
			normaliser: new CompetencyAlignmentNormaliser(),
			logger: new NullLogger(),
		);
	}//end listener()

	/**
	 * Run a create through the listener.
	 *
	 * @param CompetencyAlignmentListener $listener The listener.
	 * @param array<string, mixed>        $data     The object as submitted.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function create(CompetencyAlignmentListener $listener, array $data): ObjectCreatingEvent {
		$event = new ObjectCreatingEvent(OrEntityFactory::make($data, 'lesson'));
		$listener->handle($event);
		return $event;
	}//end create()

	/**
	 * Run an update through the listener.
	 *
	 * @param CompetencyAlignmentListener $listener The listener.
	 * @param array<string, mixed>        $old      The stored object.
	 * @param array<string, mixed>        $new      The object as it would be saved.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function update(CompetencyAlignmentListener $listener, array $old, array $new): ObjectUpdatingEvent {
		$event = new ObjectUpdatingEvent(OrEntityFactory::make($new, 'lesson'), OrEntityFactory::make($old, 'lesson'));
		$listener->handle($event);
		return $event;
	}//end update()

	/**
	 * A new lesson's alignments derive its flat goal list.
	 *
	 * @return void
	 */
	public function testAlignmentsDeriveCompetencyIds(): void {
		$event = $this->create(
			$this->listener(),
			[
				'competencyAlignments' => [
					['competencyId' => 'goal-a', 'depth' => 'practise'],
					['competencyId' => 'goal-b', 'depth' => 'introduce'],
				],
			]
		);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame(['competencyIds' => ['goal-a', 'goal-b']], $event->getModifiedData());
		self::assertSame(1, $this->frameworkLoads, 'two goals in one framework load it once');

	}//end testAlignmentsDeriveCompetencyIds()

	/**
	 * An open depth is accepted on an assignment.
	 *
	 * @return void
	 */
	public function testNullDepthIsAccepted(): void {
		$event = $this->create(
			$this->listener(slug: 'assignment'),
			['competencyAlignments' => [['competencyId' => 'goal-c', 'depth' => null]], 'competencyIds' => []]
		);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame(['competencyIds' => ['goal-c']], $event->getModifiedData());

	}//end testNullDepthIsAccepted()

	/**
	 * A row that never had alignments keeps its flat list untouched.
	 *
	 * @return void
	 */
	public function testLegacyRowIsLeftAlone(): void {
		$event = $this->create($this->listener(slug: 'course'), ['competencyIds' => ['goal-a']]);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getModifiedData());

	}//end testLegacyRowIsLeftAlone()

	/**
	 * Editing only the flat list makes the alignments follow it.
	 *
	 * @return void
	 */
	public function testFlatListEditUpdatesAlignments(): void {
		$stored = [
			'competencyIds'        => ['goal-a', 'goal-b'],
			'competencyAlignments' => [
				['competencyId' => 'goal-a', 'depth' => 'master'],
				['competencyId' => 'goal-b', 'depth' => 'introduce'],
			],
		];
		$new    = array_merge($stored, ['competencyIds' => ['goal-a', 'goal-c']]);

		$event = $this->update($this->listener(), $stored, $new);

		self::assertSame(
			[
				'competencyAlignments' => [
					['competencyId' => 'goal-a', 'depth' => 'master'],
					['competencyId' => 'goal-c', 'depth' => null],
				],
			],
			$event->getModifiedData()
		);

	}//end testFlatListEditUpdatesAlignments()

	/**
	 * Emptying the alignments empties the flat list.
	 *
	 * @return void
	 */
	public function testEmptiedAlignmentsEmptyTheFlatList(): void {
		$stored = [
			'competencyIds'        => ['goal-a'],
			'competencyAlignments' => [['competencyId' => 'goal-a', 'depth' => 'master']],
		];

		$event = $this->update(
			$this->listener(slug: 'exam'),
			$stored,
			array_merge($stored, ['competencyAlignments' => []])
		);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame(['competencyIds' => []], $event->getModifiedData());

	}//end testEmptiedAlignmentsEmptyTheFlatList()

	/**
	 * When both lists change in one save, the alignments win.
	 *
	 * @return void
	 */
	public function testAlignmentsWinWhenBothChange(): void {
		$stored = [
			'competencyIds'        => ['goal-a'],
			'competencyAlignments' => [['competencyId' => 'goal-a', 'depth' => 'master']],
		];

		$event = $this->update(
			$this->listener(),
			$stored,
			[
				'competencyIds'        => ['goal-c'],
				'competencyAlignments' => [['competencyId' => 'goal-b', 'depth' => 'practise']],
			]
		);

		self::assertSame(['competencyIds' => ['goal-b']], $event->getModifiedData());

	}//end testAlignmentsWinWhenBothChange()

	/**
	 * A depth from another framework is refused with the allowed levels.
	 *
	 * @return void
	 */
	public function testUnknownDepthIsRefusedWithTheAllowedLevels(): void {
		$event = $this->create(
			$this->listener(slug: 'assignment'),
			['competencyAlignments' => [['competencyId' => 'goal-c', 'depth' => 'master']]]
		);

		self::assertTrue($event->isPropagationStopped());
		$errors = $event->getErrors();
		self::assertSame('competency-alignment-invalid', $errors['reason']);
		self::assertStringContainsString('WP1', $errors['message']);
		self::assertStringContainsString('nog-niet-competent, competent', $errors['message']);
		self::assertSame([], $event->getModifiedData());

	}//end testUnknownDepthIsRefusedWithTheAllowedLevels()

	/**
	 * The same goal twice is refused, and so is a goal that does not exist.
	 *
	 * @return void
	 */
	public function testDuplicateGoalIsRefused(): void {
		$twice = $this->create(
			$this->listener(slug: 'course'),
			[
				'competencyAlignments' => [
					['competencyId' => 'goal-a', 'depth' => 'introduce'],
					['competencyId' => 'goal-a', 'depth' => 'master'],
				],
			]
		);
		self::assertTrue($twice->isPropagationStopped());
		self::assertStringContainsString('K1', $twice->getErrors()['message']);

		$unknown = $this->create(
			$this->listener(slug: 'course'),
			['competencyAlignments' => [['competencyId' => 'goal-missing', 'depth' => null]]]
		);
		self::assertTrue($unknown->isPropagationStopped());

	}//end testDuplicateGoalIsRefused()

	/**
	 * Another schema, or a schema that cannot be resolved, is never touched.
	 *
	 * @return void
	 */
	public function testOtherSchemasAreIgnored(): void {
		$data = ['competencyAlignments' => [['competencyId' => 'goal-a', 'depth' => 'nonsense']]];

		$item = $this->create($this->listener(slug: 'item'), $data);
		self::assertFalse($item->isPropagationStopped());
		self::assertSame([], $item->getModifiedData());

		$unknown = $this->create($this->listener(resolverThrows: true), $data);
		self::assertFalse($unknown->isPropagationStopped());
		self::assertSame([], $unknown->getModifiedData());

	}//end testOtherSchemasAreIgnored()

	/**
	 * A payload that omits both keys (a partial write) changes nothing, so it
	 * can never clear either list.
	 *
	 * @return void
	 */
	public function testPartialPayloadNeverClearsTheLists(): void {
		$stored = [
			'name'                 => 'Breuken',
			'competencyIds'        => ['goal-a'],
			'competencyAlignments' => [['competencyId' => 'goal-a', 'depth' => 'master']],
		];

		$event = $this->update($this->listener(), $stored, ['name' => 'Breuken en decimalen']);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getModifiedData());

	}//end testPartialPayloadNeverClearsTheLists()
}//end class
