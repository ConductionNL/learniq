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
use DateTimeZone;

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
	 * @param DateTimeImmutable|null              $today        Today: a booking past its last day is no longer coming.
	 *                                                          Null leaves the days out of it (the seeded copies).
	 *
	 * @return array{booking: array<string, mixed>, enrolments: array<string, array<string, mixed>>}
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function derive(
		array $booking,
		array $course,
		array $sessions,
		array $participants,
		array $renewed=[],
		array $context=[],
		?DateTimeImmutable $today=null
	): array {
		$days = $this->lines->days(sessions: $sessions);
		$participants = $this->present(participants: $participants);
		$lifecycle = $this->lifecycle(participants: $participants, current: (string)($booking['lifecycle'] ?? 'received'));
		$roster = $this->roster(
			participants: $participants,
			terms: [
				'needsBirthDate' => $this->needsBirthDate(course: $course) && in_array($lifecycle, self::OPEN, true) === true,
				'firstDay' => ($days[0] ?? null),
				'renewed' => $renewed,
				'lifecycle' => $lifecycle,
			]
		);
		$day = $this->courseDay(
			sessions: $sessions,
			facts: [
				'days' => $days,
				'context' => $context,
				'upcoming' => $this->upcoming(lifecycle: $lifecycle, days: $days, today: $today),
			]
		);
		// The participant reads what to bring on his enrolment; the booking does not copy it.
		$day['bringList'] = self::bringList(course: $course);

		return [
			'booking' => $this->bookingFields(
				booking: $booking,
				facts: [
					'courseName' => trim((string)($course['name'] ?? '')),
					'firstDay' => ($days[0] ?? null),
					'lifecycle' => $lifecycle,
					'participantCount' => count($participants),
					'roster' => $roster,
					'day' => $day,
				]
			),
			'enrolments' => array_map(static fn (array $fields): array => array_merge($fields, $day), $roster['enrolments']),
		];
	}//end derive()

	/**
	 * Where the participant is in the steps of his booking: booked, confirmed,
	 * details complete, course day, result and certificate. The value names
	 * the current step: `confirmed` while the booking waits for its
	 * confirmation, `details` while a detail of his is missing, `course-day`
	 * when nothing is left before the day, `result` once the booking is
	 * completed; null for a cancelled booking.
	 *
	 * @param string $lifecycle     The booking's state.
	 * @param string $detailsStatus The participant's details status.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/training-provider-course-days/tasks.md#t7
	 */
	public static function participantStep(string $lifecycle, string $detailsStatus): ?string {
		return match (true) {
			$lifecycle === 'completed' => 'result',
			$lifecycle === 'confirmed' && $detailsStatus !== 'complete' => 'details',
			$lifecycle === 'confirmed' => 'course-day',
			$lifecycle === 'received' => 'confirmed',
			default => null,
		};
	}//end participantStep()

	/**
	 * What the participant brings to the course day: the course's bring list,
	 * one trimmed line each, empty lines and non-text left out.
	 *
	 * @param array<string, mixed> $course The course.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-says-what-to-bring
	 */
	public static function bringList(array $course): array {
		$lines = [];
		foreach ((array)($course['bringList'] ?? []) as $line) {
			if (is_string($line) === true && trim($line) !== '') {
				$lines[] = trim($line);
			}
		}

		return $lines;
	}//end bringList()

	/**
	 * Each present participant's employer fields by enrolment id, with the
	 * names, refs and the count of missing details the booking shows.
	 *
	 * @param array<int, array<string, mixed>> $participants The present `{enrolment, profile}` rows.
	 * @param array<string, mixed>             $terms        `needsBirthDate`, the `firstDay`, the `renewed`
	 *                                                       certificate by enrolment id and the booking's `lifecycle`.
	 *
	 * @return array{enrolments: array<string, array<string, mixed>>, names: array<int, string>, refs: array<int, string>, missing: int}
	 */
	private function roster(array $participants, array $terms): array {
		$roster = ['enrolments' => [], 'names' => [], 'refs' => [], 'missing' => 0];
		foreach ($participants as $row) {
			$fields = $this->participant(row: $row, terms: $terms);
			$fields['step'] = self::participantStep(lifecycle: (string)$terms['lifecycle'], detailsStatus: (string)$fields['detailsStatus']);
			$roster['enrolments'][(string)($row['enrolment']['id'] ?? '')] = $fields;
			$roster['names'][] = $this->fullName(profile: $row['profile']);
			$roster['refs'][] = (string)($row['profile']['id'] ?? ($row['enrolment']['learnerRef'] ?? ''));
			$roster['missing'] += (int)($fields['detailsStatus'] === 'birth-date-missing');
		}

		return $roster;
	}//end roster()

	/**
	 * The course day fields the booking and each participant's enrolment
	 * carry (participant-portal).
	 *
	 * @param array<int, array<string, mixed>> $sessions The edition's sessions.
	 * @param array<string, mixed>             $facts    The course `days`, the `context` (`trainerName`,
	 *                                                   `placeLabel`) and whether the booking is `upcoming`.
	 *
	 * @return array<string, mixed>
	 */
	private function courseDay(array $sessions, array $facts): array {
		$firstDay = ($facts['days'][0] ?? null);

		return [
			'firstDay' => $firstDay?->format('Y-m-d'),
			'dayLabel' => $this->lines->dayLabel(days: $facts['days']),
			'timeLabel' => $this->lines->timeLabel(sessions: $sessions, firstDay: $firstDay),
			'placeLabel' => ($facts['context']['placeLabel'] ?? null),
			'trainerName' => ($facts['context']['trainerName'] ?? null),
			'upcoming' => $facts['upcoming'],
		];
	}//end courseDay()

	/**
	 * The booking's copies and employer status.
	 *
	 * @param array<string, mixed> $booking The stored booking.
	 * @param array<string, mixed> $facts   The `courseName`, `firstDay`, `lifecycle`, `participantCount`, the `roster`
	 *                                      and the course `day`.
	 *
	 * @return array<string, mixed>
	 */
	private function bookingFields(array $booking, array $facts): array {
		$day = $facts['day'];
		$roster = $facts['roster'];
		$places = max(1, (int)($booking['participantCount'] ?? $facts['participantCount']));
		$counts = ['open' => max(0, ($places - $facts['participantCount'])), 'missing' => $roster['missing'], 'places' => $places];
		$status = $this->employerStatus(lifecycle: $facts['lifecycle'], counts: $counts);

		return [
			'courseName' => $this->orNull(value: $facts['courseName']),
			'bookingLabel' => $this->orNull(value: implode(', ', array_filter([$facts['courseName'], (string)$day['dayLabel']]))),
			'upcoming' => $day['upcoming'],
			'firstDay' => $day['firstDay'],
			'dayLabel' => $day['dayLabel'],
			'timeLabel' => $day['timeLabel'],
			'placeLabel' => $day['placeLabel'],
			'trainerName' => $day['trainerName'],
			'participantRefs' => $roster['refs'],
			'participantNames' => $this->orNull(value: implode(', ', array_filter($roster['names']))),
			'missingDetailsCount' => $roster['missing'],
			'lifecycle' => $facts['lifecycle'],
			'employerStatus' => $status,
			'statusNote' => $this->statusNote(
				status: $status,
				booking: ['lifecycle' => $facts['lifecycle'], 'counts' => $counts, 'requestedAt' => (string)($booking['requestedAt'] ?? '')]
			),
			'detailsDueAt' => $this->lines->detailsDueAt(firstDay: $facts['firstDay']),
		];
	}//end bookingFields()

	/**
	 * Whether the booking is still coming: it is open, and its last day is
	 * today or later. Without today the days are left out of it, as the
	 * example sets write it (school-portals-follow-the-live-week).
	 *
	 * @param string                        $lifecycle The booking's state.
	 * @param array<int, DateTimeImmutable> $days      The course days, in order.
	 * @param DateTimeImmutable|null        $today     Today, or null.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/past-course-days-drop-off/specs/portal-contribution/spec.md#requirement-a-course-day-that-has-passed-is-no-longer-coming
	 */
	public function upcoming(string $lifecycle, array $days, ?DateTimeImmutable $today): bool {
		if (in_array($lifecycle, self::OPEN, true) === false) {
			return false;
		}

		$last = end($days);
		if ($today === null || $last === false) {
			return true;
		}

		$zone = new DateTimeZone(self::ZONE);

		return $last->setTimezone($zone)->format('Y-m-d') >= $today->setTimezone($zone)->format('Y-m-d');
	}//end upcoming()

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
	 * @param array<string, mixed> $row   The `{enrolment, profile}`.
	 * @param array<string, mixed> $terms Whether the booking still `needsBirthDate`, the `firstDay`
	 *                                    and the certificate each enrolment renews (`renewed`).
	 *
	 * @return array<string, string|null>
	 */
	private function participant(array $row, array $terms): array {
		$firstDay = $terms['firstDay'];
		$renewed = $terms['renewed'];
		$profile = (array)$row['profile'];
		$fields = [
			'detailsStatus' => 'complete',
			'openTask' => null,
			'openTaskNote' => null,
			'openTaskDueAt' => null,
			'certificateLine' => $this->certificateLine(credential: ($renewed[(string)($row['enrolment']['id'] ?? '')] ?? null)),
			'certificateValidUntil' => $this->lines->date(value: ($renewed[(string)($row['enrolment']['id'] ?? '')]['expiresAt'] ?? null))?->format('Y-m-d'),
		];
		if ($terms['needsBirthDate'] === false || trim((string)($profile['birthDate'] ?? '')) !== '') {
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
	 * @param string             $lifecycle The booking's state.
	 * @param array<string, int> $counts    `open` places without a name and participants `missing` a needed detail.
	 *
	 * @return string
	 */
	private function employerStatus(string $lifecycle, array $counts): string {
		if (in_array($lifecycle, self::OPEN, true) === true && ($counts['open'] > 0 || $counts['missing'] > 0)) {
			return 'waiting-for-you';
		}

		return $lifecycle;
	}//end employerStatus()

	/**
	 * The line under the status.
	 *
	 * @param string               $status  The employer status.
	 * @param array<string, mixed> $booking The booking's state (`lifecycle`), its `counts` (`open` places,
	 *                                      `missing` details, booked `places`) and when it was made (`requestedAt`).
	 *
	 * @return string|null
	 */
	private function statusNote(string $status, array $booking): ?string {
		$lifecycle = $booking['lifecycle'];
		$counts = $booking['counts'];
		if ($status === 'waiting-for-you') {
			return $this->waitingNote(open: $counts['open'], missing: $counts['missing']);
		}

		if ($lifecycle === 'confirmed' && $counts['places'] === 1) {
			return 'De plek staat vast';
		}

		if ($lifecycle === 'confirmed') {
			return 'De plekken staan vast';
		}

		$requested = $this->lines->date(value: $booking['requestedAt']);
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
