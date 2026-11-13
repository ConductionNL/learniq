<?php

/**
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\BackgroundJob\CourseDayRestampJob;
use OCA\Learniq\Listener\CourseDayStatusCascade;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use PHPUnit\Framework\TestCase;

/**
 * CourseDayStatusCascade.
 *
 * @covers \OCA\Learniq\Listener\CourseDayStatusCascade
 */
class CourseDayStatusCascadeTest extends TestCase {

	/**
	 * What the listener queued.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $deferred = [];

	/**
	 * The listener for one schema.
	 *
	 * @param string $slug The schema the resolver answers.
	 *
	 * @return CourseDayStatusCascade
	 */
	private function cascade(string $slug): CourseDayStatusCascade {
		$this->deferred = [];
		$deferral = $this->createMock(ListenerDeferralService::class);
		$deferral->method('defer')->willReturnCallback(
			function (string $jobClass, array $entry, int $chunkSize = 50, ?string $dedupeKey = null): void {
				$this->deferred[] = ['jobClass' => $jobClass, 'entry' => $entry, 'key' => $dedupeKey];
			}
		);
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn($slug);

		return new CourseDayStatusCascade(schemaResolver: $resolver, deferral: $deferral);
	}//end cascade()

	/**
	 * An update event.
	 *
	 * @param string               $slug The schema.
	 * @param array<string, mixed> $new  After.
	 * @param array<string, mixed> $old  Before.
	 *
	 * @return ObjectUpdatedEvent
	 */
	private static function updated(string $slug, array $new, array $old): ObjectUpdatedEvent {
		return new ObjectUpdatedEvent(OrEntityFactory::make($new, $slug, 'learniq', 'row-1'), OrEntityFactory::make($old, $slug, 'learniq', 'row-1'));
	}//end updated()

	/**
	 * A new enrolment on a day queues that day, once per day.
	 *
	 * @return void
	 */
	public function testANewEnrolmentQueuesItsDay(): void {
		$this->cascade('enrolment')->handle(new ObjectCreatedEvent(OrEntityFactory::make(['cohortId' => 'c-22', 'lifecycle' => 'pending'], 'enrolment', 'learniq', 'e-1')));

		self::assertSame([['jobClass' => CourseDayRestampJob::class, 'entry' => ['cohortId' => 'c-22'], 'key' => 'course-day:c-22']], $this->deferred);
	}//end testANewEnrolmentQueuesItsDay()

	/**
	 * A booking moved to another day queues both days.
	 *
	 * @return void
	 */
	public function testAMovedBookingQueuesBothDays(): void {
		$this->cascade('course-booking')->handle(self::updated('course-booking', ['cohortId' => 'c-26', 'participantCount' => 2], ['cohortId' => 'c-22', 'participantCount' => 2]));

		self::assertSame([['cohortId' => 'c-26'], ['cohortId' => 'c-22']], array_column($this->deferred, 'entry'));
	}//end testAMovedBookingQueuesBothDays()

	/**
	 * A decision on the day queues it; the restamp's own write of dayStatus queues nothing.
	 *
	 * @return void
	 */
	public function testADecisionQueuesAndTheRestampDoesNot(): void {
		$this->cascade('cohort')->handle(self::updated('cohort', ['goDecision' => 'go', 'dayStatus' => 'open'], ['dayStatus' => 'open']));
		self::assertSame([['cohortId' => 'row-1']], array_column($this->deferred, 'entry'));

		$this->cascade('cohort')->handle(self::updated('cohort', ['minParticipants' => 6, 'dayStatus' => 'goes-ahead'], ['minParticipants' => 6, 'dayStatus' => 'open']));
		self::assertSame([], $this->deferred);
	}//end testADecisionQueuesAndTheRestampDoesNot()

	/**
	 * Other schemas and rows without a day queue nothing.
	 *
	 * @return void
	 */
	public function testUnrelatedChangesQueueNothing(): void {
		$this->cascade('grade-entry')->handle(self::updated('grade-entry', ['cohortId' => 'c-22', 'value' => 8], ['cohortId' => 'c-22', 'value' => 7]));
		self::assertSame([], $this->deferred);

		$this->cascade('session')->handle(new ObjectCreatedEvent(OrEntityFactory::make(['startsAt' => '2026-10-22T09:00:00+02:00'], 'session', 'learniq', 's-1')));
		self::assertSame([], $this->deferred);
	}//end testUnrelatedChangesQueueNothing()
}//end class
