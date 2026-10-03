<?php

/**
 * Unit tests for CurriculumCoverageRollupHandler (curriculum-coverage-rollup).
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
 * @spec openspec/changes/archive/2026-09-28-curriculum-coverage-rollup/tasks.md#task-4-curriculumcoveragerolluphandler-and-its-registration
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\CoverageListenerRegistrar;
use OCA\Learniq\Listener\CurriculumCoverageRollupHandler;
use OCA\Learniq\Service\CompetencyAlignmentNormaliser;
use OCA\Learniq\Service\CurriculumCoverageRollup;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Proves the handler recomputes exactly the frameworks a write touched.
 */
class CurriculumCoverageRollupHandlerTest extends TestCase {

	/**
	 * The rollup double.
	 *
	 * @var CurriculumCoverageRollup&MockObject
	 */
	private CurriculumCoverageRollup&MockObject $rollup;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Frameworks recompute() was called with.
	 *
	 * @var array<int, string>
	 */
	private array $recomputed = [];

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->rollup = $this->createMock(CurriculumCoverageRollup::class);
		$this->rollup->method('recompute')->willReturnCallback(
			function (string $frameworkId): array {
				$this->recomputed[] = $frameworkId;
				return ['saved' => 0, 'deleted' => 0, 'unchanged' => 0];
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The handler with a resolver that reports one slug.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return CurriculumCoverageRollupHandler
	 */
	private function handler(string $slug): CurriculumCoverageRollupHandler {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn($slug);

		return new CurriculumCoverageRollupHandler(
			schemaResolver: $resolver,
			rollup: $this->rollup,
			normaliser: new CompetencyAlignmentNormaliser(),
			logger: $this->logger,
		);
	}//end handler()

	/**
	 * An update event over old and new data.
	 *
	 * @param array<string, mixed> $new  New data.
	 * @param array<string, mixed> $old  Old data.
	 * @param string|null          $uuid The entity uuid.
	 *
	 * @return ObjectUpdatedEvent
	 */
	private function updated(array $new, array $old, ?string $uuid=null): ObjectUpdatedEvent {
		$event = $this->createMock(ObjectUpdatedEvent::class);
		$event->method('getObject')->willReturn(OrEntityFactory::make($new, 'x', 'learniq', $uuid));
		$event->method('getOldObject')->willReturn(OrEntityFactory::make($old, 'x', 'learniq', $uuid));
		return $event;
	}//end updated()

	/**
	 * Moving a lesson's alignment from F1's goal to F2's goal recomputes F1
	 * and F2, nothing else.
	 *
	 * @return void
	 */
	public function testLessonSaveRecomputesTheFrameworksOfOldAndNewGoals(): void {
		$this->rollup->expects(self::once())->method('frameworksForGoals')
			->with(['g2', 'g1'])
			->willReturn(['F2', 'F1']);

		$this->handler('lesson')->handle(
			$this->updated(
				['competencyIds' => ['g2'], 'competencyAlignments' => [['competencyId' => 'g2', 'depth' => null]]],
				['competencyIds' => ['g1']]
			)
		);

		self::assertSame(['F2', 'F1'], $this->recomputed);

	}//end testLessonSaveRecomputesTheFrameworksOfOldAndNewGoals()

	/**
	 * A goal moved between frameworks recomputes both, without resolving
	 * goals.
	 *
	 * @return void
	 */
	public function testGoalSaveRecomputesItsOldAndNewFramework(): void {
		$this->rollup->expects(self::never())->method('frameworksForGoals');

		$this->handler('competency')->handle($this->updated(['frameworkId' => 'B'], ['frameworkId' => 'A']));

		self::assertSame(['B', 'A'], $this->recomputed);

	}//end testGoalSaveRecomputesItsOldAndNewFramework()

	/**
	 * A new framework recomputes itself.
	 *
	 * @return void
	 */
	public function testFrameworkSaveRecomputesItself(): void {
		$event = $this->createMock(ObjectCreatedEvent::class);
		$event->method('getObject')->willReturn(OrEntityFactory::make(['name' => 'Kerndoelen'], 'competency-framework', 'learniq', 'fw-9'));

		$this->handler('competency-framework')->handle($event);

		self::assertSame(['fw-9'], $this->recomputed);

	}//end testFrameworkSaveRecomputesItself()

	/**
	 * A deleted assessment recomputes the frameworks of the goals it had.
	 *
	 * @return void
	 */
	public function testDeletedAssessmentRecomputesFromItsOldGoals(): void {
		$this->rollup->method('frameworksForGoals')->with(['g1'])->willReturn(['F1']);
		$event = $this->createMock(ObjectDeletedEvent::class);
		$event->method('getObject')->willReturn(OrEntityFactory::make(['competencyIds' => ['g1']], 'exam'));

		$this->handler('exam')->handle($event);

		self::assertSame(['F1'], $this->recomputed);

	}//end testDeletedAssessmentRecomputesFromItsOldGoals()

	/**
	 * Another schema, or an event that carries no object, recomputes nothing.
	 *
	 * @return void
	 */
	public function testOtherSchemasAreIgnored(): void {
		$this->rollup->expects(self::never())->method('frameworksForGoals');

		$this->handler('item')->handle($this->updated(['competencyIds' => ['g1']], []));
		$this->handler('lesson')->handle(new Event());

		self::assertSame([], $this->recomputed);

	}//end testOtherSchemasAreIgnored()

	/**
	 * A failing recompute is logged and never rethrown into the save.
	 *
	 * @return void
	 */
	public function testFailureNeverBreaksTheSave(): void {
		$rollup = $this->createMock(CurriculumCoverageRollup::class);
		$rollup->method('recompute')->willThrowException(new RuntimeException('database gone'));
		$this->logger->expects(self::once())->method('warning');
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn('competency-framework');
		$handler = new CurriculumCoverageRollupHandler($resolver, $rollup, new CompetencyAlignmentNormaliser(), $this->logger);

		// Reaching the logger's expectation without an exception is the assertion.
		$handler->handle($this->updated(['name' => 'x'], ['name' => 'y'], 'fw-1'));

	}//end testFailureNeverBreaksTheSave()

	/**
	 * The registrar narrows the handler to the six schemas.
	 *
	 * @return void
	 */
	public function testRegistrarNarrowsToTheSixSchemas(): void {
		self::assertSame(
			['lesson', 'course', 'assignment', 'exam', 'competency', 'competency-framework'],
			CoverageListenerRegistrar::SCHEMAS
		);

	}//end testRegistrarNarrowsToTheSixSchemas()
}//end class
