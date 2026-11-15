<?php

/**
 * A booking follows what staff change in its parts: queued in the request,
 * re-derived after it.
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
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\BackgroundJob\EmployerBookingRestampJob;
use OCA\Learniq\Listener\EmployerBookingCascade;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use PHPUnit\Framework\TestCase;

/**
 * EmployerBookingCascade.
 */
class EmployerBookingCascadeTest extends TestCase {

	/**
	 * What the listener queued.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $deferred = [];

	/**
	 * The listener for one schema.
	 *
	 * @param string $slug The schema the resolver answers.
	 *
	 * @return EmployerBookingCascade
	 */
	private function cascade(string $slug): EmployerBookingCascade {
		$this->deferred = [];
		$deferral = $this->createMock(ListenerDeferralService::class);
		$deferral->method('defer')->willReturnCallback(
			function (string $jobClass, array $entry, int $chunkSize = 50, ?string $dedupeKey = null): void {
				$this->deferred[] = ['jobClass' => $jobClass, 'entry' => $entry];
			}
		);
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn($slug);

		return new EmployerBookingCascade(schemaResolver: $resolver, deferral: $deferral);
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
	 * The planner confirms an enrolment: its booking is queued.
	 *
	 * @return void
	 */
	public function testAConfirmedEnrolmentQueuesItsBooking(): void {
		$this->cascade('enrolment')->handle(self::updated('enrolment', ['bookingRef' => 'b-1', 'lifecycle' => 'active'], ['bookingRef' => 'b-1', 'lifecycle' => 'pending']));

		self::assertSame([['jobClass' => EmployerBookingRestampJob::class, 'entry' => ['bookingRef' => 'b-1']]], $this->deferred);
	}//end testAConfirmedEnrolmentQueuesItsBooking()

	/**
	 * The projection's own write (derived fields only) queues nothing, so it never loops.
	 *
	 * @return void
	 */
	public function testTheProjectionsOwnWriteQueuesNothing(): void {
		$this->cascade('enrolment')->handle(self::updated('enrolment', ['bookingRef' => 'b-1', 'lifecycle' => 'active', 'detailsStatus' => 'complete'], ['bookingRef' => 'b-1', 'lifecycle' => 'active', 'detailsStatus' => 'birth-date-missing']));
		$this->cascade('course-booking')->handle(self::updated('course-booking', ['participantCount' => 3, 'statusNote' => 'a'], ['participantCount' => 3, 'statusNote' => 'b']));

		self::assertSame([], $this->deferred);
	}//end testTheProjectionsOwnWriteQueuesNothing()

	/**
	 * A birth date filled in in Nextcloud queues the person's bookings; an enrolment without a booking queues nothing.
	 *
	 * @return void
	 */
	public function testABirthDateQueuesThePersonAndALooseEnrolmentNothing(): void {
		$this->cascade('learner-profile')->handle(self::updated('learner-profile', ['givenName' => 'Youssef', 'birthDate' => '1994-03-14'], ['givenName' => 'Youssef']));
		self::assertSame([['learnerRef' => 'row-1']], array_column($this->deferred, 'entry'));

		$this->cascade('enrolment')->handle(new ObjectCreatedEvent(OrEntityFactory::make(['lifecycle' => 'pending'], 'enrolment', 'learniq', 'row-2')));
		self::assertSame([], $this->deferred);

		$this->cascade('grade-entry')->handle(self::updated('grade-entry', ['value' => 8], ['value' => 7]));
		self::assertSame([], $this->deferred);
	}//end testABirthDateQueuesThePersonAndALooseEnrolmentNothing()
}//end class
