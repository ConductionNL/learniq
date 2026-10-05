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
	public const ZONE = 'Europe/Amsterdam';

	/**
	 * Course tags that mean the course ends in an exam the exam institution
	 * registers a participant for, which needs the participant's birth date.
	 *
	 * @var array<int, string>
	 */
	public const EXAM_TAGS = ['examen', 'certificaat'];

	private const WEEKDAYS = ['maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag', 'zondag'];

	private const MONTHS = [
		'januari',
		'februari',
		'maart',
		'april',
		'mei',
		'juni',
		'juli',
		'augustus',
		'september',
		'oktober',
		'november',
		'december',
	];

	/**
	 * The booking's copies and status, and each enrolment's employer fields.
	 *
	 * @param array<string, mixed>                                                    $booking      The stored booking.
	 * @param array<string, mixed>                                                    $course       The course of the edition (name, tags), or [].
	 * @param array<int, array<string, mixed>>                                        $sessions     The edition's sessions.
	 * @param array<int, array{enrolment: array<string, mixed>, profile: array<string, mixed>}> $participants The enrolments that point at the booking, with their profiles.
	 * @param array<string, array<string, mixed>>                                     $renewed      The certificate each enrolment renews, by enrolment id.
	 * @param array{trainerName?: string|null, placeLabel?: string|null}              $context      Names read elsewhere.
	 *
	 * @return array{booking: array<string, mixed>, enrolments: array<string, array<string, mixed>>}
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function derive(array $booking, array $course, array $sessions, array $participants, array $renewed=[], array $context=[]): array {
		$days = $this->days(sessions: $sessions);
		$firstDay = ($days[0] ?? null);
		usort($participants, static fn (array $one, array $two): int => strcmp((string)($one['enrolment']['id'] ?? ''), (string)($two['enrolment']['id'] ?? '')));
		$participants = array_values(array_filter($participants, static fn (array $row): bool => ($row['enrolment']['lifecycle'] ?? '') !== 'withdrawn'));

		$lifecycle = $this->lifecycle(participants: $participants, current: (string)($booking['lifecycle'] ?? 'received'));
		$needsBirthDate = $this->needsBirthDate(course: $course);
		$enrolments = [];
		$names = [];
		$refs = [];
		$missing = 0;
		foreach ($participants as $row) {
			$fields = $this->participant(row: $row, needsBirthDate: $needsBirthDate && in_array($lifecycle, ['received', 'confirmed'], true) === true, firstDay: $firstDay, renewed: $renewed);
			$enrolments[(string)($row['enrolment']['id'] ?? '')] = $fields;
			$names[] = $this->fullName(profile: $row['profile']);
			$refs[] = (string)($row['profile']['id'] ?? ($row['enrolment']['learnerRef'] ?? ''));
			if ($fields['detailsStatus'] === 'birth-date-missing') {
				$missing++;
			}
		}

		$places = max(1, (int)($booking['participantCount'] ?? count($participants)));
		$open = max(0, ($places - count($participants)));
		$status = $this->employerStatus(lifecycle: $lifecycle, openPlaces: $open, missing: $missing);

		return [
			'booking' => [
				'courseName' => $this->orNull(value: (string)($course['name'] ?? '')),
				'bookingLabel' => $this->orNull(value: implode(', ', array_filter([trim((string)($course['name'] ?? '')), (string)$this->dayLabel(days: $days)]))),
				'upcoming' => in_array($lifecycle, ['received', 'confirmed'], true),
				'firstDay' => $firstDay?->format('Y-m-d'),
				'dayLabel' => $this->dayLabel(days: $days),
				'timeLabel' => $this->timeLabel(sessions: $sessions, firstDay: $firstDay),
				'placeLabel' => ($context['placeLabel'] ?? null),
				'trainerName' => ($context['trainerName'] ?? null),
				'participantRefs' => $refs,
				'participantNames' => $this->orNull(value: implode(', ', array_filter($names))),
				'missingDetailsCount' => $missing,
				'lifecycle' => $lifecycle,
				'employerStatus' => $status,
				'statusNote' => $this->statusNote(status: $status, lifecycle: $lifecycle, openPlaces: $open, missing: $missing, places: $places, requestedAt: (string)($booking['requestedAt'] ?? '')),
				'detailsDueAt' => $this->detailsDueAt(firstDay: $firstDay),
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
	 */
	public function needsBirthDate(array $course): bool {
		$tags = array_map(static fn (mixed $tag): string => strtolower(trim((string)$tag)), (array)($course['tags'] ?? []));

		return array_intersect($tags, self::EXAM_TAGS) !== [];
	}//end needsBirthDate()

	/**
	 * 12.00 on the working day before the first course day: until then the
	 * employer may still supply names and details.
	 *
	 * @param DateTimeImmutable|null $firstDay The first course day.
	 *
	 * @return string|null An ISO date-time, or null without a day.
	 */
	public function detailsDueAt(?DateTimeImmutable $firstDay): ?string {
		if ($firstDay === null) {
			return null;
		}

		$day = $firstDay->modify('-1 day');
		while ((int)$day->format('N') > 5) {
			$day = $day->modify('-1 day');
		}

		return $day->setTime(12, 0)->format(DATE_ATOM);
	}//end detailsDueAt()

	/**
	 * A participant's employer fields: are the details complete, what is
	 * still to do, and which certificate the course renews.
	 *
	 * @param array{enrolment: array<string, mixed>, profile: array<string, mixed>} $row            The enrolment and profile.
	 * @param bool                                                                  $needsBirthDate Whether the booking still needs birth dates.
	 * @param DateTimeImmutable|null                                                $firstDay       The first course day.
	 * @param array<string, array<string, mixed>>                                   $renewed        The certificate each enrolment renews.
	 *
	 * @return array{detailsStatus: string, openTask: string|null, openTaskNote: string|null, openTaskDueAt: string|null, certificateLine: string|null}
	 */
	private function participant(array $row, bool $needsBirthDate, ?DateTimeImmutable $firstDay, array $renewed): array {
		$profile = $row['profile'];
		$missing = $needsBirthDate && trim((string)($profile['birthDate'] ?? '')) === '';
		$given = trim((string)($profile['givenName'] ?? ''));
		$fields = [
			'detailsStatus' => 'complete',
			'openTask' => null,
			'openTaskNote' => null,
			'openTaskDueAt' => null,
			'certificateLine' => $this->certificateLine(credential: ($renewed[(string)($row['enrolment']['id'] ?? '')] ?? null)),
		];
		if ($missing === true) {
			$day = '';
			if ($firstDay !== null) {
				$day = ' ' . self::WEEKDAYS[((int)$firstDay->format('N') - 1)];
			}

			$fields['detailsStatus'] = 'birth-date-missing';
			$fields['openTask'] = 'Vul de geboortedatum van ' . $this->fullName(profile: $profile) . ' in';
			$fields['openTaskNote'] = $given . ' doet' . $day . ' examen. Zonder geboortedatum kunnen wij ' . $given . ' niet aanmelden.';
			$fields['openTaskDueAt'] = $this->detailsDueAt(firstDay: $firstDay);
		}

		return $fields;
	}//end participant()

	/**
	 * "Certificaat geldig tot 30 november 2026", or null.
	 *
	 * @param array<string, mixed>|null $credential The certificate the course renews.
	 *
	 * @return string|null
	 */
	private function certificateLine(?array $credential): ?string {
		$date = $this->date(value: ($credential['expiresAt'] ?? null));
		if ($date === null) {
			return null;
		}

		return 'Certificaat geldig tot ' . $this->longDate(day: $date);
	}//end certificateLine()

	/**
	 * The booking's state, from its participants' enrolments: completed when
	 * every one completed, confirmed when one is active, cancelled when the
	 * booking had people and none is left, received otherwise.
	 *
	 * @param array<int, array{enrolment: array<string, mixed>, profile: array<string, mixed>}> $participants Participants not withdrawn.
	 * @param string                                                                            $current      The stored state.
	 *
	 * @return string
	 */
	private function lifecycle(array $participants, string $current): string {
		$states = array_map(static fn (array $row): string => (string)($row['enrolment']['lifecycle'] ?? 'pending'), $participants);
		if ($states === []) {
			return in_array($current, ['cancelled', 'confirmed'], true) === true ? $current : 'received';
		}

		if (array_diff($states, ['completed', 'failed']) === []) {
			return 'completed';
		}

		return in_array('active', $states, true) === true ? 'confirmed' : 'received';
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
		if (in_array($lifecycle, ['completed', 'cancelled'], true) === true) {
			return $lifecycle;
		}

		return ($openPlaces > 0 || $missing > 0) ? 'waiting-for-you' : $lifecycle;
	}//end employerStatus()

	/**
	 * The line under the status.
	 *
	 * @param string $status      The employer status.
	 * @param string $lifecycle   The booking's state.
	 * @param int    $openPlaces  Places without a name.
	 * @param int    $missing     Participants without a needed detail.
	 * @param int    $places      Places booked.
	 * @param string $requestedAt When the booking was made.
	 *
	 * @return string|null
	 */
	private function statusNote(string $status, string $lifecycle, int $openPlaces, int $missing, int $places, string $requestedAt): ?string {
		if ($status === 'waiting-for-you' && $openPlaces > 0) {
			return $openPlaces === 1 ? 'Vul de naam van 1 deelnemer in' : 'Vul de namen van ' . $openPlaces . ' deelnemers in';
		}

		if ($status === 'waiting-for-you') {
			return 'Geboortedatum van ' . $missing . ' ' . ($missing === 1 ? 'deelnemer' : 'deelnemers') . ' ontbreekt';
		}

		if ($lifecycle === 'confirmed') {
			return $places === 1 ? 'De plek staat vast' : 'De plekken staan vast';
		}

		$requested = $this->date(value: $requestedAt);
		if ($lifecycle === 'received' && $requested !== null) {
			return 'Bevestiging uiterlijk ' . $this->dayAndDate(day: $this->workingDaysAfter(day: $requested, count: 2));
		}

		return $lifecycle === 'completed' ? 'Afgerond' : null;
	}//end statusNote()

	/**
	 * The distinct course days of the sessions, in order.
	 *
	 * @param array<int, array<string, mixed>> $sessions The sessions.
	 *
	 * @return array<int, DateTimeImmutable>
	 */
	private function days(array $sessions): array {
		$days = [];
		foreach ($sessions as $session) {
			$start = $this->date(value: ($session['startsAt'] ?? null));
			if ($start !== null) {
				$days[$start->format('Y-m-d')] = $start->setTime(0, 0);
			}
		}

		ksort($days);

		return array_values($days);
	}//end days()

	/**
	 * "donderdag 8 oktober", "dinsdag 20 en woensdag 21 oktober" or "3, 4 en 10 november".
	 *
	 * @param array<int, DateTimeImmutable> $days The course days.
	 *
	 * @return string|null
	 */
	private function dayLabel(array $days): ?string {
		if ($days === []) {
			return null;
		}

		if (count($days) <= 2) {
			$parts = [];
			foreach ($days as $index => $day) {
				$sameMonth = isset($days[$index + 1]) === true && $days[$index + 1]->format('Y-m') === $day->format('Y-m');
				$parts[] = $sameMonth === true ? self::WEEKDAYS[((int)$day->format('N') - 1)] . ' ' . $day->format('j') : $this->dayAndDate(day: $day);
			}

			return implode(' en ', $parts);
		}

		$byMonth = [];
		foreach ($days as $day) {
			$byMonth[self::MONTHS[((int)$day->format('n') - 1)]][] = $day->format('j');
		}

		$parts = [];
		foreach ($byMonth as $month => $numbers) {
			$parts[] = $this->listOf(items: $numbers) . ' ' . $month;
		}

		return $this->listOf(items: $parts);
	}//end dayLabel()

	/**
	 * "08.30 tot 16.30 uur": the first day's start to its end.
	 *
	 * @param array<int, array<string, mixed>> $sessions The sessions.
	 * @param DateTimeImmutable|null           $firstDay The first course day.
	 *
	 * @return string|null
	 */
	private function timeLabel(array $sessions, ?DateTimeImmutable $firstDay): ?string {
		if ($firstDay === null) {
			return null;
		}

		$starts = [];
		$ends = [];
		foreach ($sessions as $session) {
			$start = $this->date(value: ($session['startsAt'] ?? null));
			$end = $this->date(value: ($session['endsAt'] ?? null));
			if ($start === null || $end === null || $start->format('Y-m-d') !== $firstDay->format('Y-m-d')) {
				continue;
			}

			$starts[] = $start->format('H.i');
			$ends[] = $end->format('H.i');
		}

		if ($starts === []) {
			return null;
		}

		return min($starts) . ' tot ' . max($ends) . ' uur';
	}//end timeLabel()

	/**
	 * "a, b en c".
	 *
	 * @param array<int, string> $items The items.
	 *
	 * @return string
	 */
	private function listOf(array $items): string {
		$last = array_pop($items);
		if ($items === []) {
			return (string)$last;
		}

		return implode(', ', $items) . ' en ' . $last;
	}//end listOf()

	/**
	 * The day a number of working days after another.
	 *
	 * @param DateTimeImmutable $day   The day.
	 * @param int               $count How many working days.
	 *
	 * @return DateTimeImmutable
	 */
	private function workingDaysAfter(DateTimeImmutable $day, int $count): DateTimeImmutable {
		while ($count > 0) {
			$day = $day->modify('+1 day');
			if ((int)$day->format('N') <= 5) {
				$count--;
			}
		}

		return $day;
	}//end workingDaysAfter()

	/**
	 * "dinsdag 6 oktober".
	 *
	 * @param DateTimeImmutable $day The day.
	 *
	 * @return string
	 */
	private function dayAndDate(DateTimeImmutable $day): string {
		return self::WEEKDAYS[((int)$day->format('N') - 1)] . ' ' . $day->format('j') . ' ' . self::MONTHS[((int)$day->format('n') - 1)];
	}//end dayAndDate()

	/**
	 * "30 november 2026".
	 *
	 * @param DateTimeImmutable $day The day.
	 *
	 * @return string
	 */
	private function longDate(DateTimeImmutable $day): string {
		return $day->format('j') . ' ' . self::MONTHS[((int)$day->format('n') - 1)] . ' ' . $day->format('Y');
	}//end longDate()

	/**
	 * A date or date-time in the institute's zone, or null.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function date(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value, new DateTimeZone(self::ZONE)))->setTimezone(new DateTimeZone(self::ZONE));
		} catch (\Exception) {
			return null;
		}
	}//end date()

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

		return $value === '' ? null : $value;
	}//end orNull()
}//end class
