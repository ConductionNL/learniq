<?php

/**
 * Learniq GradeRollupHandler routing and programme lookup tests.
 *
 * Pins which transitions the roll-up acts on, that a failing roll-up is
 * logged and never thrown, and which programme a final grade names.
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
 * @spec openspec/specs/grading/spec.md#scenario-a-final-grade-names-the-programme-of-its-plan
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Grading\GradeFormulaEvaluator;
use OCA\Learniq\Grading\GradeVisibilityResolver;
use OCA\Learniq\Listener\GradeRollupHandler;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Routing and programme lookup of GradeRollupHandler.
 */
class GradeRollupHandlerRoutingTest extends TestCase {

	/**
	 * A handler whose store throws on every read, so any roll-up that runs is
	 * visible as one logged error.
	 *
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectService   $store  The store.
	 *
	 * @return GradeRollupHandler
	 */
	private function handler(LoggerInterface $logger, ObjectService $store): GradeRollupHandler {
		$schemas = $this->createMock(ListenerSchemaResolver::class);
		$schemas->method('eventRegister')->willReturnCallback(static fn (ObjectTransitionedEvent $event): string => $event->getRegister());
		$schemas->method('eventSchema')->willReturnCallback(static fn (ObjectTransitionedEvent $event): string => $event->getSchema());

		return new GradeRollupHandler(
			$store,
			$this->createMock(GradeFormulaEvaluator::class),
			new GradeVisibilityResolver(),
			$this->createMock(ITimeFactory::class),
			$schemas,
			$logger
		);
	}//end handler()

	/**
	 * A transition event.
	 *
	 * @param string $register The register.
	 * @param string $schema   The schema.
	 * @param string $to       The target state.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function event(string $register, string $schema, string $to): ObjectTransitionedEvent {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('jsonSerialize')->willThrowException(new RuntimeException('rolled up '.$schema));
		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getRegister')->willReturn($register);
		$event->method('getSchema')->willReturn($schema);
		$event->method('getTo')->willReturn($to);
		$event->method('getObject')->willReturn($object);
		return $event;
	}//end event()

	/**
	 * Only a published grade entry or a graded assessment result in Learniq's
	 * register starts a roll-up; one that fails is logged as an error and
	 * not thrown.
	 *
	 * @return void
	 */
	public function testOnlyPublishedEntriesAndGradedResultsRollUp(): void {
		$errors = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			static function (string $message, array $context) use (&$errors): void {
				$errors[] = $context['error'];
			}
		);
		$handler = $this->handler(logger: $logger, store: $this->createMock(ObjectService::class));

		$handler->handle($this->createMock(Event::class));
		$handler->handle($this->event(register: 'shillinq', schema: 'grade-entry', to: 'published'));
		$handler->handle($this->event(register: 'learniq', schema: 'grade-entry', to: 'graded'));
		$handler->handle($this->event(register: 'learniq', schema: 'assessment-result', to: 'published'));
		$handler->handle($this->event(register: 'learniq', schema: 'enrolment', to: 'published'));
		self::assertSame([], $errors);

		$handler->handle($this->event(register: 'learniq', schema: 'grade-entry', to: 'published'));
		$handler->handle($this->event(register: 'learniq', schema: 'assessment-result', to: 'graded'));
		self::assertSame(['rolled up grade-entry', 'rolled up assessment-result'], $errors);
	}//end testOnlyPublishedEntriesAndGradedResultsRollUp()

	/**
	 * A final grade names its plan's programme by id or uuid; otherwise the
	 * programme it already named; otherwise none.
	 *
	 * @return void
	 */
	public function testTheProgrammeAFinalGradeNames(): void {
		$answers = [];
		$queries = [];
		$store = $this->createMock(ObjectService::class);
		$store->method('findAll')->willReturnCallback(
			static function (array $config) use (&$answers, &$queries): array {
				$queries[] = $config;
				return ($answers[$config['filters']['curriculumPlanId']] ?? []);
			}
		);
		$answers = [
			'by-id'      => [['id' => 'prog-1', 'uuid' => 'u-1']],
			'by-uuid'    => [['uuid' => 'u-2']],
			'entity'     => [OrEntityFactory::make(['curriculumPlanId' => 'entity'], 'programme', 'learniq', 'u-3')],
			'empty-id'   => [['id' => '', 'uuid' => 'u-4']],
			'numeric-id' => [['id' => 5]],
		];
		$programmeFor = new \ReflectionMethod(GradeRollupHandler::class, 'programmeFor');
		$handler = $this->handler(logger: $this->createMock(LoggerInterface::class), store: $store);
		$call = static fn (string $plan, mixed $current): ?string => $programmeFor->invoke($handler, $plan, $current);

		self::assertSame('prog-1', $call('by-id', 'kept'));
		self::assertSame('u-2', $call('by-uuid', null));
		self::assertSame('u-3', $call('entity', null));
		self::assertSame('kept', $call('empty-id', 'kept'));
		self::assertSame('kept', $call('numeric-id', 'kept'));
		self::assertSame('kept', $call('none', 'kept'));
		self::assertNull($call('none', ''));
		self::assertNull($call('none', 7));
		self::assertNull($call('none', null));
		self::assertSame(
			['filters' => ['register' => 'learniq', 'schema' => 'programme', 'curriculumPlanId' => 'by-id'], 'limit' => 1],
			$queries[0]
		);
	}//end testTheProgrammeAFinalGradeNames()
}//end class
