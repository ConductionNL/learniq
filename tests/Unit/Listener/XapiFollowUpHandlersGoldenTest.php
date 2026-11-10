<?php

/**
 * Golden tests for the two handlers that queue a completed xAPI statement.
 *
 * XapiCompletionHandler and LessonProgressHandler each queue a completed or
 * passed XapiStatement for its own follow-up. This pins, for both, which
 * events are queued, the exact deferral arguments and the dedupe key.
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

use OCA\Learniq\BackgroundJob\XapiStatementFollowUpJob;
use OCA\Learniq\Lifecycle\XapiCompletionHandler;
use OCA\Learniq\Listener\LessonProgressHandler;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for the xAPI follow-up handlers.
 */
class XapiFollowUpHandlersGoldenTest extends TestCase {

	/**
	 * Deferrals recorded.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $deferred = [];

	/**
	 * Run both handlers on one event.
	 *
	 * @param Event  $event    The event.
	 * @param string $register Register slug the resolver answers.
	 * @param string $schema   Schema slug the resolver answers.
	 *
	 * @return void
	 */
	private function dispatch(Event $event, string $register = 'learniq', string $schema = 'xapi-statement'): void {
		$deferral = $this->createMock(ListenerDeferralService::class);
		$deferral->method('defer')->willReturnCallback(
			function (string $jobClass, array $entry, int $chunkSize = 0, ?string $dedupeKey = null): void {
				$this->deferred[] = ['jobClass' => $jobClass, 'entry' => $entry, 'dedupeKey' => $dedupeKey];
			}
		);
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('registerSlug')->willReturn($register);
		$resolver->method('schemaSlug')->willReturn($schema);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-03-04T05:06:07+00:00'));

		(new XapiCompletionHandler($deferral, $resolver))->handle($event);
		(new LessonProgressHandler($deferral, $resolver, $time))->handle($event);
	}//end dispatch()

	/**
	 * A created event carrying a statement.
	 *
	 * @param array<string,mixed> $statement The statement.
	 *
	 * @return ObjectCreatedEvent
	 */
	private function created(array $statement): ObjectCreatedEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($statement);
		$event = $this->createMock(ObjectCreatedEvent::class);
		$event->method('getObject')->willReturn($entity);

		return $event;
	}//end created()

	/**
	 * A completed statement is queued by both, with their own kind and dedupe key.
	 *
	 * @return void
	 */
	public function testACompletedStatementIsQueuedByBoth(): void {
		$statement = ['id' => 'st-1', 'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/completed']];
		$this->dispatch($this->created($statement));

		self::assertSame(
			[
				[
					'jobClass' => XapiStatementFollowUpJob::class,
					'entry' => ['kind' => XapiStatementFollowUpJob::ENROLMENT_COMPLETION, 'statement' => $statement],
					'dedupeKey' => XapiStatementFollowUpJob::ENROLMENT_COMPLETION . '|st-1',
				],
				[
					'jobClass' => XapiStatementFollowUpJob::class,
					'entry' => ['kind' => XapiStatementFollowUpJob::LESSON_PROGRESS, 'statement' => $statement, 'completedAt' => '2026-03-04T05:06:07+00:00'],
					'dedupeKey' => XapiStatementFollowUpJob::LESSON_PROGRESS . '|st-1',
				],
			],
			$this->deferred
		);
	}//end testACompletedStatementIsQueuedByBoth()

	/**
	 * A passed statement dedupes on its uuid, and without an id there is no dedupe key.
	 *
	 * @return void
	 */
	public function testDedupeKeys(): void {
		$this->dispatch($this->created(['uuid' => 'u-2', 'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/passed']]));
		self::assertSame([XapiStatementFollowUpJob::ENROLMENT_COMPLETION . '|u-2', XapiStatementFollowUpJob::LESSON_PROGRESS . '|u-2'], array_column($this->deferred, 'dedupeKey'));

		$this->deferred = [];
		$this->dispatch($this->created(['verb' => ['id' => 'http://adlnet.gov/expapi/verbs/passed']]));
		self::assertSame([null, null], array_column($this->deferred, 'dedupeKey'));
		self::assertCount(2, $this->deferred);
	}//end testDedupeKeys()

	/**
	 * Other events, registers, schemas and verbs queue nothing.
	 *
	 * @return void
	 */
	public function testEverythingElseQueuesNothing(): void {
		$completed = ['id' => 'st-1', 'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/completed']];
		$this->dispatch(new Event());
		$this->dispatch($this->createMock(ObjectUpdatedEvent::class));
		$this->dispatch($this->created($completed), 'other-app');
		$this->dispatch($this->created($completed), 'learniq', 'lesson');
		$this->dispatch($this->created(['id' => 'st-1', 'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/attempted']]));
		$this->dispatch($this->created(['id' => 'st-1']));

		self::assertSame([], $this->deferred);
	}//end testEverythingElseQueuesNothing()
}//end class
