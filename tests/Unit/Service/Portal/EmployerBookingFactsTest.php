<?php

/**
 * What a company's booking tells its employer.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Portal
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

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use OCA\Learniq\Service\Portal\EmployerBookingFacts;
use OCA\Learniq\Service\Portal\EmployerBookingSteps;
use PHPUnit\Framework\TestCase;

/**
 * EmployerBookingFacts on hand-made rows and on the seeded training set.
 */
class EmployerBookingFactsTest extends TestCase {

	/**
	 * One session row.
	 *
	 * @param string $start The start.
	 * @param string $end   The end.
	 *
	 * @return array<string, string>
	 */
	private static function session(string $start, string $end): array {
		return ['startsAt' => $start, 'endsAt' => $end];
	}//end session()

	/**
	 * A participant.
	 *
	 * @param string      $id        The enrolment id.
	 * @param string      $given     Given name.
	 * @param string|null $birthDate The birth date.
	 * @param string      $lifecycle The enrolment state.
	 *
	 * @return array{enrolment: array<string, mixed>, profile: array<string, mixed>}
	 */
	private static function person(string $id, string $given, ?string $birthDate, string $lifecycle='active'): array {
		$profile = ['id' => 'lp-' . $id, 'givenName' => $given, 'familyName' => 'Test'];
		if ($birthDate !== null) {
			$profile['birthDate'] = $birthDate;
		}

		return ['enrolment' => ['id' => 'en-' . $id, 'learnerRef' => 'lp-' . $id, 'lifecycle' => $lifecycle], 'profile' => $profile];
	}//end person()

	/**
	 * A confirmed exam booking with one participant missing a birth date waits for the employer.
	 *
	 * @return void
	 */
	public function testAMissingBirthDateBeforeAnExamWaitsForTheEmployer(): void {
		$facts = (new EmployerBookingFacts())->derive(
			booking: ['participantCount' => 2, 'lifecycle' => 'received'],
			course: ['name' => 'F-gassen: herhaling en examen', 'tags' => ['examen']],
			sessions: [self::session('2026-10-08T08:30:00+02:00', '2026-10-08T12:00:00+02:00'), self::session('2026-10-08T12:45:00+02:00', '2026-10-08T16:30:00+02:00')],
			participants: [self::person('1', 'Tom', '1988-06-12'), self::person('2', 'Youssef', null)],
			renewed: ['en-2' => ['expiresAt' => '2026-11-30T23:59:00+01:00']]
		);

		self::assertSame('confirmed', $facts['booking']['lifecycle']);
		self::assertSame('waiting-for-you', $facts['booking']['employerStatus']);
		self::assertSame('Geboortedatum van 1 deelnemer ontbreekt', $facts['booking']['statusNote']);
		self::assertSame('donderdag 8 oktober', $facts['booking']['dayLabel']);
		self::assertSame('08.30 tot 16.30 uur', $facts['booking']['timeLabel']);
		self::assertSame('2026-10-07T12:00:00+02:00', $facts['booking']['detailsDueAt']);
		self::assertSame('Tom Test, Youssef Test', $facts['booking']['participantNames']);
		self::assertSame('birth-date-missing', $facts['enrolments']['en-2']['detailsStatus']);
		self::assertSame('Vul de geboortedatum van Youssef Test in', $facts['enrolments']['en-2']['openTask']);
		self::assertSame('Certificaat geldig tot 30 november 2026', $facts['enrolments']['en-2']['certificateLine']);
		self::assertSame('complete', $facts['enrolments']['en-1']['detailsStatus']);
		self::assertNull($facts['enrolments']['en-1']['openTask']);
	}//end testAMissingBirthDateBeforeAnExamWaitsForTheEmployer()

	/**
	 * With today given, a booking is coming up to and including its last
	 * course day and no longer after it; a two-day course is still coming on
	 * its second day. Without today the days are left out of it, as the
	 * example sets write it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/past-course-days-drop-off/specs/portal-contribution/spec.md#requirement-a-course-day-that-has-passed-is-no-longer-coming
	 */
	public function testABookingStopsBeingComingAfterItsLastDay(): void {
		$zone = new \DateTimeZone('Europe/Amsterdam');
		$sessions = [self::session('2026-10-20T08:30:00+02:00', '2026-10-20T16:30:00+02:00'), self::session('2026-10-21T08:30:00+02:00', '2026-10-21T16:30:00+02:00')];
		$derive = static fn (?string $today): array => (new EmployerBookingFacts())->derive(
			booking: ['participantCount' => 1, 'lifecycle' => 'confirmed'],
			course: ['name' => 'Basis'],
			sessions: $sessions,
			participants: [self::person('1', 'Daan', null)],
			today: ($today === null ? null : new \DateTimeImmutable($today, $zone))
		);

		self::assertTrue($derive('2026-10-20 09:00')['booking']['upcoming'], 'on the first day');
		self::assertTrue($derive('2026-10-21 23:30')['booking']['upcoming'], 'on the last day');
		$after = $derive('2026-10-22 00:10');
		self::assertFalse($after['booking']['upcoming'], 'the day after');
		self::assertFalse($after['enrolments']['en-1']['upcoming'], 'his course day drops off too');
		self::assertTrue($derive(null)['booking']['upcoming'], 'the seeded copies leave the days out');
		self::assertFalse((new EmployerBookingFacts())->upcoming(lifecycle: 'completed', days: [], today: null));
	}//end testABookingStopsBeingComingAfterItsLastDay()

	/**
	 * A course without an exam never asks for a birth date.
	 *
	 * @return void
	 */
	public function testACourseWithoutAnExamAsksNoBirthDate(): void {
		$facts = (new EmployerBookingFacts())->derive(
			booking: ['participantCount' => 1],
			course: ['name' => 'Basis', 'tags' => ['warmtepompen']],
			sessions: [self::session('2026-10-20T08:30:00+02:00', '2026-10-20T16:30:00+02:00'), self::session('2026-10-21T08:30:00+02:00', '2026-10-21T16:30:00+02:00')],
			participants: [self::person('1', 'Daan', null)]
		);

		self::assertSame('confirmed', $facts['booking']['employerStatus']);
		self::assertSame('De plek staat vast', $facts['booking']['statusNote']);
		self::assertSame('dinsdag 20 en woensdag 21 oktober', $facts['booking']['dayLabel']);
		self::assertSame('complete', $facts['enrolments']['en-1']['detailsStatus']);
	}//end testACourseWithoutAnExamAsksNoBirthDate()

	/**
	 * Places without a name keep the booking on "Wacht op u", and a received booking names its confirmation day.
	 *
	 * @return void
	 */
	public function testOpenPlacesAndAReceivedBooking(): void {
		$open = (new EmployerBookingFacts())->derive(
			booking: ['participantCount' => 3, 'lifecycle' => 'received', 'requestedAt' => '2026-10-02T15:20:00+02:00'],
			course: ['name' => 'X'],
			sessions: [],
			participants: [self::person('1', 'Tom', '1988-06-12', 'pending')]
		);
		self::assertSame('waiting-for-you', $open['booking']['employerStatus']);
		self::assertSame('Vul de namen van 2 deelnemers in', $open['booking']['statusNote']);
		self::assertNull($open['booking']['detailsDueAt']);

		$received = (new EmployerBookingFacts())->derive(
			booking: ['participantCount' => 1, 'requestedAt' => '2026-10-02T15:20:00+02:00'],
			course: ['name' => 'X'],
			sessions: [self::session('2026-11-03T08:30:00+01:00', '2026-11-03T16:30:00+01:00'), self::session('2026-11-04T08:30:00+01:00', '2026-11-04T16:30:00+01:00'), self::session('2026-11-10T08:30:00+01:00', '2026-11-10T16:30:00+01:00')],
			participants: [self::person('1', 'Tom', '1988-06-12', 'pending')]
		);
		self::assertSame('received', $received['booking']['employerStatus']);
		self::assertSame('Bevestiging uiterlijk dinsdag 6 oktober', $received['booking']['statusNote']);
		self::assertSame('3, 4 en 10 november', $received['booking']['dayLabel']);
		// Tuesday 3 November: due on Monday 2 November at noon; a Monday course skips back over the weekend.
		self::assertSame('2026-11-02T12:00:00+01:00', $received['booking']['detailsDueAt']);
		self::assertSame('2026-11-06T12:00:00+01:00', (new EmployerBookingFacts())->detailsDueAt(firstDay: new \DateTimeImmutable('2026-11-09', new \DateTimeZone('Europe/Amsterdam'))));
		self::assertTrue($received['booking']['upcoming']);
	}//end testOpenPlacesAndAReceivedBooking()

	/**
	 * The seeded bookings of the training set carry exactly what the server would write.
	 *
	 * @return void
	 */
	public function testTheSeededBookingsAgreeWithTheServer(): void {
		$set = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/profiles/training.json'), true);
		$objects = $set['x-openregister']['seedData']['objects'];
		$byId = static fn (string $bucket): array => array_column($objects[$bucket], null, 'uuid');
		$profiles = $byId('learner-profile');
		$courses = $byId('course');
		$cohorts = $byId('cohort');
		$staff = ['training-trainer-10' => 'Henk Dekker', 'training-trainer-11' => 'Fatima Ouali'];
		$location = $objects['vestiging'][0];
		self::assertCount(4, $objects['course-booking']);

		foreach ($objects['course-booking'] as $booking) {
			$participants = [];
			$renewed = [];
			foreach ($objects['enrolment'] as $enrolment) {
				if (($enrolment['bookingRef'] ?? null) !== $booking['uuid']) {
					continue;
				}

				$participants[] = ['enrolment' => $enrolment + ['id' => $enrolment['uuid']], 'profile' => $profiles[$enrolment['learnerRef']] + ['id' => $enrolment['learnerRef']]];
				foreach ($objects['credential'] as $credential) {
					if (($credential['renewalEnrolmentId'] ?? null) === $enrolment['uuid']) {
						$renewed[$enrolment['uuid']] = $credential;
					}
				}
			}

			$cohort = $cohorts[$booking['cohortId']];
			$sessions = array_values(array_filter($objects['session'], static fn (array $s): bool => $s['cohortId'] === $booking['cohortId']));
			$facts = (new EmployerBookingFacts())->derive(
				booking: $booking,
				course: $courses[$booking['courseId']],
				sessions: $sessions,
				participants: $participants,
				renewed: $renewed,
				context: ['trainerName' => ($staff[$cohort['teacherIds'][0]] ?? null), 'placeLabel' => $location['name'] . ', ' . $location['street']]
			);
			foreach ($facts['booking'] as $field => $value) {
				self::assertSame($value, $booking[$field], $booking['bookingNumber'] . ' ' . $field);
			}

			foreach ($facts['enrolments'] as $id => $fields) {
				$seeded = array_column($objects['enrolment'], null, 'uuid')[$id];
				foreach ($fields as $field => $value) {
					self::assertSame($value, $seeded[$field], $booking['bookingNumber'] . ' ' . $id . ' ' . $field);
				}
			}
		}//end foreach

		$waiting = array_column($objects['course-booking'], 'employerStatus', 'bookingNumber');
		self::assertSame(['I-2026-0377' => 'completed', 'I-2026-0412' => 'waiting-for-you', 'I-2026-0425' => 'confirmed', 'I-2026-0431' => 'received'], $waiting);
	}//end testTheSeededBookingsAgreeWithTheServer()

	/**
	 * The steps of a booking that waits for a birth date (board Detail).
	 *
	 * @return void
	 */
	public function testTheStepsOfABookingThatWaitsForADetail(): void {
		$steps = (new EmployerBookingSteps(
			rows: $this->createMock(\OCA\Learniq\Service\Portal\EmployerBookingProjection::class),
			l10n: $this->createMock(\OCP\L10N\IFactory::class),
			logger: new \Psr\Log\NullLogger()
		))->stepsOf(
			booking: [
				'lifecycle' => 'confirmed',
				'employerStatus' => 'waiting-for-you',
				'statusNote' => 'Geboortedatum van 1 deelnemer ontbreekt',
				'participantCount' => 3,
				'dayLabel' => 'donderdag 8 oktober',
				'requestedAt' => '2026-09-14T09:40:00+02:00',
				'requestedByName' => 'Linda Jansen',
			],
			l10n: null
		);

		self::assertSame(['done', 'done', 'current', 'todo', 'todo'], array_column($steps, 'state'));
		self::assertSame('3 places', $steps[1]['description']);
		self::assertSame('Geboortedatum van 1 deelnemer ontbreekt', $steps[2]['description']);
		self::assertSame('donderdag 8 oktober', $steps[3]['description']);
	}//end testTheStepsOfABookingThatWaitsForADetail()
}//end class
