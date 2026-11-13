<?php

/**
 * Golden tests for the write-event prologue nine stamp listeners share.
 *
 * Each of these listeners acts only on a create or update of one of its own
 * schemas that no earlier listener stopped: any other event, a stopped
 * write, a write whose schema can not be resolved and a write of another
 * schema leave the event untouched. This pins that, for every one of them.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\AccessibilityFeedbackReporterStamp;
use OCA\Learniq\Listener\ConcernReportReporterStamp;
use OCA\Learniq\Listener\GradeEntryLearnerRefStamp;
use OCA\Learniq\Listener\LearnerUserIdStamp;
use OCA\Learniq\Listener\LearningRecordOwnerStamp;
use OCA\Learniq\Listener\LvsResultLearnerRefStamp;
use OCA\Learniq\Listener\ReadableCopyStamp;
use OCA\Learniq\Listener\RegulationExemptionRequestStamp;
use OCA\Learniq\Listener\SovereigntyPolicySetByStamp;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\ContractImplementations;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Golden tests for the shared write-event prologue.
 */
class OwnSchemaWritePrologueGoldenTest extends TestCase {

	/**
	 * The nine listeners.
	 *
	 * @return array<string,array{0:class-string}>
	 */
	public static function listeners(): array {
		$out = [];
		foreach ([
			AccessibilityFeedbackReporterStamp::class,
			ConcernReportReporterStamp::class,
			GradeEntryLearnerRefStamp::class,
			LearnerUserIdStamp::class,
			LearningRecordOwnerStamp::class,
			LvsResultLearnerRefStamp::class,
			ReadableCopyStamp::class,
			RegulationExemptionRequestStamp::class,
			SovereigntyPolicySetByStamp::class,
		] as $class) {
			$out[(new ReflectionClass($class))->getShortName()] = [$class];
		}

		return $out;
	}//end listeners()

	/**
	 * Build a listener over mocks, with the given schema resolver.
	 *
	 * @param class-string           $class    The listener.
	 * @param ListenerSchemaResolver $resolver The resolver.
	 *
	 * @return object
	 */
	private function listener(string $class, ListenerSchemaResolver $resolver): object {
		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$type = ContractImplementations::concrete((string)$parameter->getType());
			$args[] = $type === ListenerSchemaResolver::class ? $resolver : $this->createMock($type);
		}

		return new $class(...$args);
	}//end listener()

	/**
	 * A write event that must stay untouched.
	 *
	 * @param class-string $eventClass ObjectCreatingEvent or ObjectUpdatingEvent.
	 * @param bool         $stopped    Whether an earlier listener stopped it.
	 *
	 * @return ObjectCreatingEvent|ObjectUpdatingEvent
	 */
	private function untouchedWrite(string $eventClass, bool $stopped): ObjectCreatingEvent|ObjectUpdatingEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn(['learnerId' => 'lea']);
		$event = $this->createMock($eventClass);
		$event->method('isPropagationStopped')->willReturn($stopped);
		$event->method('getModifiedData')->willReturn([]);
		if ($eventClass === ObjectUpdatingEvent::class) {
			$event->method('getNewObject')->willReturn($entity);
			$event->method('getOldObject')->willReturn($entity);
		} else {
			$event->method('getObject')->willReturn($entity);
		}

		$event->expects(self::never())->method('setModifiedData');
		$event->expects(self::never())->method('setErrors');
		$event->expects(self::never())->method('stopPropagation');

		return $event;
	}//end untouchedWrite()

	/**
	 * Another kind of event is ignored without asking for its schema.
	 *
	 * @param class-string $class The listener.
	 *
	 * @return void
	 */
	#[DataProvider('listeners')]
	public function testOtherEventsAreIgnored(string $class): void {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->expects(self::never())->method('guardSchemaSlug');

		$this->listener($class, $resolver)->handle(new Event());
		self::addToAssertionCount(1);
	}//end testOtherEventsAreIgnored()

	/**
	 * A stopped write is left alone without asking for its schema.
	 *
	 * @param class-string $class The listener.
	 *
	 * @return void
	 */
	#[DataProvider('listeners')]
	public function testStoppedWritesAreLeftAlone(string $class): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $eventClass) {
			$resolver = $this->createMock(ListenerSchemaResolver::class);
			$resolver->expects(self::never())->method('guardSchemaSlug');
			$this->listener($class, $resolver)->handle($this->untouchedWrite($eventClass, true));
		}
	}//end testStoppedWritesAreLeftAlone()

	/**
	 * A write whose schema can not be resolved, or of another schema, is left alone; the new state is what is resolved.
	 *
	 * @param class-string $class The listener.
	 *
	 * @return void
	 */
	#[DataProvider('listeners')]
	public function testUnknownOrForeignSchemasAreLeftAlone(string $class): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $eventClass) {
			foreach ([new RuntimeException('unknown schema'), 'someone-elses-schema'] as $answer) {
				$event = $this->untouchedWrite($eventClass, false);
				$expected = $eventClass === ObjectUpdatingEvent::class ? $event->getNewObject() : $event->getObject();
				$resolver = $this->createMock(ListenerSchemaResolver::class);
				$resolver->expects(self::once())->method('guardSchemaSlug')->willReturnCallback(
					function (ObjectEntity $entity) use ($answer, $expected): string {
						self::assertSame($expected, $entity);
						if ($answer instanceof \Throwable) {
							throw $answer;
						}

						return $answer;
					}
				);

				$this->listener($class, $resolver)->handle($event);
			}
		}
	}//end testUnknownOrForeignSchemasAreLeftAlone()
}//end class
