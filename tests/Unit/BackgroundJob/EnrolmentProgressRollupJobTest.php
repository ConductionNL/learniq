<?php

/**
 * Tests for the deferred Enrolment progress roll-up.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\BackgroundJob;

use OCA\Learniq\BackgroundJob\EnrolmentProgressRollupJob;
use OCA\Learniq\Progress\EnrolmentProgressEvaluator;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The work `EnrolmentProgressRollupHandler` used to do inside the
 * LessonCompletion write now happens here.
 *
 * These arms are the ones that moved out of the listener's test when the work
 * did — a roll-up whose Enrolment has vanished, and the write itself. Leaving
 * them behind on the listener would have meant asserting behaviour at a layer
 * that no longer has it.
 *
 * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#requirement-enrolment-carries-a-declared-lesson-progress-roll-up
 */
class EnrolmentProgressRollupJobTest extends TestCase {

	/**
	 * Objects the mocked ObjectService was asked to save.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Build the job with a mocked OR surface.
	 *
	 * @param array<int, array<string, mixed>> $enrolments Rows the enrolment lookup returns.
	 *
	 * @return EnrolmentProgressRollupJob The job under test.
	 */
	private function makeJob(array $enrolments): EnrolmentProgressRollupJob {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn($enrolments);
		$objectService->method('saveObject')->willReturnCallback(
			function (mixed $object = null, ?array $extend = [], mixed $register = null, mixed $schema = null): mixed {
				$this->saved[] = ['schema' => (string)$schema, 'object' => $object];
				return $object;
			}
		);

		$evaluator = $this->createMock(EnrolmentProgressEvaluator::class);
		$evaluator->method('evaluate')->willReturn(
			['progressPercent' => 40, 'completedLessonCount' => 4, 'totalPublishedLessonCount' => 10]
		);

		return new EnrolmentProgressRollupJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			$this->createMock(OrganisationService::class),
			new NullLogger(),
			$objectService,
			$evaluator
		);
	}//end makeJob()

	/**
	 * Invoke the protected `runDeferred()` with a context.
	 *
	 * @param EnrolmentProgressRollupJob      $job     The job.
	 * @param array<int, array<string, mixed>> $entries The buffered entries.
	 *
	 * @return void
	 */
	private function runJob(EnrolmentProgressRollupJob $job, array $entries): void {
		$method = new \ReflectionMethod($job, 'runDeferred');
		$method->setAccessible(true);
		$method->invoke($job, new DeferredListenerContext(userId: 'learner-1', orgUuid: null, entries: $entries));
	}//end runJob()

	/**
	 * A deferred entry writes progressPercent onto the active Enrolment.
	 *
	 * @return void
	 */
	public function testAnEntryWritesTheRecomputedProgress(): void {
		$job = $this->makeJob([['id' => 'enrolment-1', 'learnerId' => 'learner-1', 'courseId' => 'course-1']]);

		$this->runJob($job, [['learnerId' => 'learner-1', 'courseId' => 'course-1']]);

		self::assertCount(1, $this->saved);
		self::assertSame('enrolment', $this->saved[0]['schema']);
		self::assertSame(40, $this->saved[0]['object']['progressPercent']);
		self::assertSame('enrolment-1', $this->saved[0]['object']['id'], 'it must write onto the existing row, not a new one');
	}//end testAnEntryWritesTheRecomputedProgress()

	/**
	 * No active Enrolment means nothing to recompute onto.
	 *
	 * This is the arm that moved here from the listener's test.
	 *
	 * @return void
	 */
	public function testAnEntryWithNoActiveEnrolmentWritesNothing(): void {
		$job = $this->makeJob([]);

		$this->runJob($job, [['learnerId' => 'learner-1', 'courseId' => 'course-1']]);

		self::assertSame([], $this->saved);
	}//end testAnEntryWithNoActiveEnrolmentWritesNothing()

	/**
	 * An incomplete entry is skipped rather than written with empty ids.
	 *
	 * @return void
	 */
	public function testAnIncompleteEntryIsSkipped(): void {
		$job = $this->makeJob([['id' => 'enrolment-1']]);

		$this->runJob($job, [['learnerId' => '', 'courseId' => 'course-1'], ['courseId' => 'course-2']]);

		self::assertSame([], $this->saved);
	}//end testAnIncompleteEntryIsSkipped()

	/**
	 * One failing entry must not discard the rest of the chunk.
	 *
	 * Entries are batched, so an exception that escaped the loop would drop
	 * every roll-up buffered after the one that failed — silently, because the
	 * job already succeeded from the queue's point of view.
	 *
	 * @return void
	 */
	public function testOneFailingEntryDoesNotLoseTheChunk(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([['id' => 'enrolment-1']]);
		$calls = 0;
		$objectService->method('saveObject')->willReturnCallback(
			function (mixed $object = null, ?array $extend = [], mixed $register = null, mixed $schema = null) use (&$calls): mixed {
				$calls++;
				if ($calls === 1) {
					throw new \RuntimeException('write failed');
				}

				$this->saved[] = ['schema' => (string)$schema, 'object' => $object];
				return $object;
			}
		);

		$evaluator = $this->createMock(EnrolmentProgressEvaluator::class);
		$evaluator->method('evaluate')->willReturn(
			['progressPercent' => 40, 'completedLessonCount' => 4, 'totalPublishedLessonCount' => 10]
		);

		$job = new EnrolmentProgressRollupJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			$this->createMock(OrganisationService::class),
			new NullLogger(),
			$objectService,
			$evaluator
		);

		$this->runJob($job, [
			['learnerId' => 'learner-1', 'courseId' => 'course-1'],
			['learnerId' => 'learner-2', 'courseId' => 'course-2'],
		]);

		self::assertCount(1, $this->saved, 'the second entry must still be written');
	}//end testOneFailingEntryDoesNotLoseTheChunk()
}//end class
