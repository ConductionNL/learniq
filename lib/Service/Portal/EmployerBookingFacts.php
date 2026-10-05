<?php

/**
 * Learniq EmployerBookingFacts
 *
 * What a company's booking (inschrijving) says to its employer, derived from
 * the rows it is made of: the edition's sessions, the participants'
 * enrolments and profiles, and the certificates the course renews. Pure: no
 * I/O, so the example sets and a test can check that the seeded copies are
 * the copies the server would write (employer-portal-audience).
 *
 * WHY THE TEXT IS DUTCH. These are readable copies stored on the rows, in the
 * institute's own language, like a group name or a report caption. The
 * portal shows them as they are; the labels around them are translated.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
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

namespace OCA\Learniq\Service\Portal;

use DateTimeImmutable;

/**
 * Derives a booking's readable copies and status, and its participants' tasks.
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
 */
class EmployerBookingFacts {

	/**
	 * The time zone the institute's days are in.
	 */
	public const ZONE = CourseDayLines::ZONE;

	/**
	 * Course tags that mean the course ends in an exam the exam institution
	 * registers a participant for, which needs the participant's birth date.
	 *
	 * @var array<int, string>
	 */
	public const EXAM_TAGS = ['examen', 'certificaat'];

	/**
	 * The states in which a booking still asks something of the employer.
	 *
	 * @var array<int, string>
	 */
	private const OPEN = ['received', 'confirmed'];

	/**
	 * The day and time lines.
	 *
	 * @var CourseDayLines
	 */
	private CourseDayLines $lines;

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->lines = new CourseDayLines();
	}//end __construct()

	/**
	 * The booking's copies and status, and each enrolment's employer fields.
	 *
	 * @param array<string, mixed>                $booking      The stored booking.
	 * @param array<string, mixed>                $course       The course of the edition (name, tags), or [].
	 * @param array<int, array<string, mixed>>    $sessions     The edition's sessions.
	 * @param array<int, array<string, mixed>>    $participants Each `{enrolment, profile}` that points at the booking.
	 * @param array<string, array<string, mixed>> $renewed      The certificate each enrolment renews, by enrolment id.
	 * @param array<string, string|null>          $context      `trainerName` and `placeLabel`, read elsewhere.
	 *
	 * @return array{booking: array<string, mixed>, enrolments: array<string, array<string, mixed>>}
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function derive(array $booking, array $course, array $sessions, array $participants, array $renewed=[], array $context=[]): array {
		$days = $this->lines->days(sessions: $sessions);
		$firstDay = ($days[0] ?? null);
		$participants = $this->present(participants: $participants);
		$lifecycle = $this->lifecycle(participants: $participants, current: (string)($booking['lifecycle'] ?? 'received'));
		$needsBirthDate = $this->needsBirthDate(course: $course) && in_array($lifecycle, self::OPEN, true) === true;

		$enrolments = [];
		$names = [];
		$refs = [];
		$missing = 0;
		foreach ($participants as $row) {
			$fields = $this->participant(row: $row, needsBirthDate: $needsBirthDate, firstDay: $firstDay, renewed: $renewed);
			$enrolments[(string)($row['enrolment']['id'] ?? '')] = $fields;
			$names[] = $this->fullName(profile: $row['profile']);
			$refs[] = (string)($row['profile']['id'] ?? ($row['enrolment']['learnerRef'] ?? ''));
			$missing += (int)($fields['detailsStatus'] === 'birth-date-missing');
		}

		$places = max(1, (int)($booking['participantCount'] ?? count($participants)));
		$open = max(0, ($places - count($participants)));
		$status = $this->employerStatus(lifecycle: $lifecycle, openPlaces: $open, missing: $missing);
		$dayLabel = $this->lines->dayLabel(days: $days);
		$courseName = trim((string)($course['name'] ?? ''));

		return [
			'booking' => [
				'courseName' => $this->orNull(value: $courseName),
				'bookingLabel' => $this->orNull(value: implode(', ', array_filter([$courseName, (string)$dayLabel]))),
				'upcoming' => in_array($lifecycle, self::OPEN, true),
				'firstDay' => $firstDay?->format('Y-m-d'),
				'dayLabel' => $dayLabel,
				'timeLabel' => $this->lines->timeLabel(sessions: $sessions, firstDay: $firstDay),
				'placeLabel' => ($context['placeLabel'] ?? null),
				'trainerName' => ($context['trainerName'] ?? null),
				'participantRefs' => $refs,
				'participantNames' => $this->orNull(value: implode(', ', array_filter($names))),
				'missingDetailsCount' => $missing,
				'lifecycle' => $lifecycle,
				'employerStatus' => $status,
				'statusNote' => $this->statusNote(
					status: $status,
					lifecycle: $lifecycle,
					counts: ['open' => $open, 'missing' => $missing, 'places' => $places],
					requestedAt: (string)($booking['requestedAt'] ?? '')
				),
				'detailsDueAt' => $this->lines->detailsDueAt(firstDay: $firstDay),
			],
			'enrolments' => $enrolments,
		];
	}//end derive()

	/**
	 * Whether a course ends in an exam that needs the participant's birth date.
	 *
	 * @param array<string, mixed> $course The course.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function needsBirthDate(array $course): bool {
		$tags = array_map(static fn (mixed $tag): string => strtolower(trim((string)$tag)), (array)($course['tags'] ?? []));

		return array_intersect($tags, self::EXAM_TAGS) !== [];
	}//end needsBirthDate()

	/**
	 * 12.00 on the working day before the first course day.
	 *
	 * @param DateTimeImmutable|null $firstDay The first course day.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function detailsDueAt(?DateTimeImmutable $firstDay): ?string {
		return $this->lines->detailsDueAt(firstDay: $firstDay);
	}//end detailsDueAt()

	/**
	 * The participants not withdrawn, in the order of their enrolments.
	 *
	 * @param array<int, array<string, mixed>> $participants Each `{enrolment, profile}`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function present(array $participants): array {
		usort(
			$participants,
			static fn (array $one, array $two): int => strcmp((string)($one['enrolment']['id'] ?? ''), (string)($two['enrolment']['id'] ?? ''))
		);

		return array_values(array_filter($participants, static fn (array $row): bool => ($row['enrolment']['lifecycle'] ?? '') !== 'withdrawn'));
	}//end present()

	/**
	 * A participant's employer fields: are the details complete, what is
	 * still to do, and which certificate the course renews.
	 *
	 * @param array<string, mixed>                $row            The `{enrolment, profile}`.
	 * @param bool                                $needsBirthDate Whether the booking still needs birth dates.
	 * @param DateTimeImmutable|null              $firstDay       The first course day.
	 * @param array<string, array<string, mixed>> $renewed        The certificate each enrolment renews.
	 *
	 * @return array<string, string|null>
	 */
	private function participant(array $row, bool $needsBirthDate, ?DateTimeImmutable $firstDay, array $renewed): array {
		$profile = (array)$row['profile'];
		$fields = [
			'detailsStatus' => 'complete',
			'openTask' => null,
			'openTaskNote' => null,
			'openTaskDueAt' => null,
			'certificateLine' => $this->certificateLine(credential: ($renewed[(string)($row['enrolment']['id'] ?? '')] ?? null)),
		];
		if ($needsBirthDate === false || trim((string)($profile['birthDate'] ?? '')) !== '') {
			return $fields;
		}

		$given = trim((string)($profile['givenName'] ?? ''));
		$day = '';
		if ($firstDay !== null) {
			$day = ' ' . $this->lines->weekday(day: $firstDay);
		}

		return array_merge(
			$fields,
			[
				'detailsStatus' => 'birth-date-missing',
				'openTask' => 'Vul de geboortedatum van ' . $this->fullName(profile: $profile) . ' in',
				'openTaskNote' => $given . ' doet' . $day . ' examen. Zonder geboortedatum kunnen wij ' . $given . ' niet aanmelden.',
				'openTaskDueAt' => $this->lines->detailsDueAt(firstDay: $firstDay),
			]
		);
	}//end participant()

	/**
	 * "Certificaat geldig tot 30 november 2026", or null.
	 *
	 * @param array<string, mixed>|null $credential The certificate the course renews.
	 *
	 * @return string|null
	 */
	private function certificateLine(?array $credential): ?string {
		$date = $this->lines->date(value: ($credential['expiresAt'] ?? null));
		if ($date === null) {
			return null;
		}

		return 'Certificaat geldig tot ' . $this->lines->longDate(day: $date);
	}//end certificateLine()

	/**
	 * The booking's state, from its participants' enrolments: completed when
	 * every one completed, confirmed when one is active, received otherwise.
	 * A booking without people keeps a stored cancelled or confirmed.
	 *
	 * @param array<int, array<string, mixed>> $participants Participants not withdrawn.
	 * @param string                           $current      The stored state.
	 *
	 * @return string
	 */
	private function lifecycle(array $participants, string $current): string {
		$states = array_map(static fn (array $row): string => (string)($row['enrolment']['lifecycle'] ?? 'pending'), $participants);
		if ($states === [] && in_array($current, ['cancelled', 'confirmed'], true) === true) {
			return $current;
		}

		if ($states !== [] && array_diff($states, ['completed', 'failed']) === []) {
			return 'completed';
		}

		if (in_array('active', $states, true) === true) {
			return 'confirmed';
		}

		return 'received';
	}//end lifecycle()

	/**
	 * Where the booking stands for the employer.
	 *
	 * @param string $lifecycle  The booking's state.
	 * @param int    $openPlaces Places without a name.
	 * @param int    $missing    Participants without a needed detail.
	 *
	 * @return string
	 */
	private function employerStatus(string $lifecycle, int $openPlaces, int $missing): string {
		if (in_array($lifecycle, self::OPEN, true) === true && ($openPlaces > 0 || $missing > 0)) {
			return 'waiting-for-you';
		}

		return $lifecycle;
	}//end employerStatus()

	/**
	 * The line under the status.
	 *
	 * @param string             $status      The employer status.
	 * @param string             $lifecycle   The booking's state.
	 * @param array<string, int> $counts      `open` places, `missing` details, booked `places`.
	 * @param string             $requestedAt When the booking was made.
	 *
	 * @return string|null
	 */
	private function statusNote(string $status, string $lifecycle, array $counts, string $requestedAt): ?string {
		if ($status === 'waiting-for-you') {
			return $this->waitingNote(open: $counts['open'], missing: $counts['missing']);
		}

		if ($lifecycle === 'confirmed' && $counts['places'] === 1) {
			return 'De plek staat vast';
		}

		if ($lifecycle === 'confirmed') {
			return 'De plekken staan vast';
		}

		$requested = $this->lines->date(value: $requestedAt);
		if ($lifecycle === 'received' && $requested !== null) {
			return 'Bevestiging uiterlijk ' . $this->lines->dayAndDate(day: $this->lines->workingDaysAfter(day: $requested, count: 2));
		}

		if ($lifecycle === 'completed') {
			return 'Afgerond';
		}

		return null;
	}//end statusNote()

	/**
	 * What waits: names first, then birth dates.
	 *
	 * @param int $open    Places without a name.
	 * @param int $missing Participants without a birth date.
	 *
	 * @return string
	 */
	private function waitingNote(int $open, int $missing): string {
		if ($open === 1) {
			return 'Vul de naam van 1 deelnemer in';
		}

		if ($open > 1) {
			return 'Vul de namen van ' . $open . ' deelnemers in';
		}

		if ($missing === 1) {
			return 'Geboortedatum van 1 deelnemer ontbreekt';
		}

		return 'Geboortedatum van ' . $missing . ' deelnemers ontbreekt';
	}//end waitingNote()

	/**
	 * Given and family name.
	 *
	 * @param array<string, mixed> $profile The learner profile.
	 *
	 * @return string
	 */
	private function fullName(array $profile): string {
		return trim(trim((string)($profile['givenName'] ?? '')) . ' ' . trim((string)($profile['familyName'] ?? '')));
	}//end fullName()

	/**
	 * A text, or null when empty.
	 *
	 * @param string $value The text.
	 *
	 * @return string|null
	 */
	private function orNull(string $value): ?string {
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end orNull()
}//end class
