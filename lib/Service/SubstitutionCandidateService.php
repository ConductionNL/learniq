<?php

/**
 * Learniq Substitution Candidate Service
 *
 * Who can cover a lesson (timetabling-standby-slots): first the teachers on
 * standby at the lesson's time, then the teachers who work that weekday and
 * have no lesson then, each with the reason they are listed. A standby teacher
 * who does have a lesson then goes last with "has a lesson then". The lesson's
 * own teachers (the absent ones) are never listed. Learniq suggests; a person
 * chooses, and SessionChangeGuard still checks the substitution.
 *
 * A teacher's lessons come from the current timetable source (planninq when
 * installed, decision D10, else learniq's Session), read per cohort the
 * teacher teaches plus the lessons they already cover.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/specs/timetabling/spec.md#requirement-the-substitution-dialog-offers-standby-teachers-first
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use OCA\Learniq\Kernel\Contracts\SubstitutionCandidateServiceInterface;
use OCA\Learniq\Kernel\Contracts\TimetableSourceResolverInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use Throwable;

/**
 * Lists the teachers who can cover a lesson, standby first.
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-the-substitution-dialog-offers-standby-teachers-first
 */
class SubstitutionCandidateService implements SubstitutionCandidateServiceInterface {

	private const REGISTER = 'learniq';

	private const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService           $objectService Slots, staff and cohorts.
	 * @param TimetableSourceResolverInterface $sources       Where lessons are read from.
	 * @param IUserManager            $userManager   Display names.
	 * @param StandbyCalendar         $calendar      Whether a slot covers a lesson.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly TimetableSourceResolverInterface $sources,
		private readonly IUserManager $userManager,
		private readonly StandbyCalendar $calendar,
	) {
	}//end __construct()

	/**
	 * The candidates for a lesson.
	 *
	 * @param array<string,mixed> $session The lesson (a learniq Session).
	 * @param array<string,mixed> $cohort  Its cohort.
	 *
	 * @return array<int,array<string,mixed>> `userId`, `displayName`, `group`, `reason` (and `slot` for standby); standby, then free, then busy standby.
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-the-substitution-dialog-offers-standby-teachers-first
	 */
	public function forSession(array $session, array $cohort): array {
		$start = $this->time(row: $session, field: 'startsAt');
		$end = $this->time(row: $session, field: 'endsAt');
		if ($start === null || $end === null || $end <= $start) {
			return [];
		}

		$lesson = [
			'date' => $start->format('Y-m-d'),
			'weekday' => self::WEEKDAYS[((int)$start->format('N')) - 1],
			'from' => $start->format('H:i'),
			'to' => $end->format('H:i'),
			'location' => (string)($cohort['locationId'] ?? ''),
		];
		$absent = array_map('strval', (array)($cohort['teacherIds'] ?? []));
		$busy = $this->busyTeachers(window: ['start' => $start, 'end' => $end, 'except' => (string)($session['id'] ?? ($session['uuid'] ?? ''))]);

		[$standby, $busyStandby] = $this->standbyCandidates(slots: $this->coveringSlots(lesson: $lesson, absent: $absent), busy: $busy);
		$skip = array_merge(array_keys($standby), array_keys($busyStandby), array_keys($busy), $absent);
		$free = $this->freeCandidates(weekday: $lesson['weekday'], skip: $skip);

		return array_values(array_merge($this->sorted(rows: $standby), $this->sorted(rows: $free), $this->sorted(rows: $busyStandby)));
	}//end forSession()

	/**
	 * The standby slots covering the lesson, by teacher; the lesson's own teachers are left out.
	 *
	 * @param array<string,string> $lesson The lesson's date, weekday, from, to and location.
	 * @param array<int,string>    $absent The lesson's own teachers.
	 *
	 * @return array<string,array<string,mixed>> The last covering slot per uid.
	 */
	private function coveringSlots(array $lesson, array $absent): array {
		$slots = [];
		foreach ($this->read(schema: 'standby-slot', filters: []) as $slot) {
			$uid = (string)($slot['teacherId'] ?? '');
			if ($uid !== '' && in_array($uid, $absent, true) === false && $this->calendar->covers(slot: $slot, lesson: $lesson) === true) {
				$slots[$uid] = $slot;
			}
		}

		return $slots;
	}//end coveringSlots()

	/**
	 * The teachers on standby during the lesson: free ones, and those who teach then.
	 *
	 * @param array<string,array<string,mixed>> $slots The covering slot per uid.
	 * @param array<string,true>                $busy  Teachers with a lesson then.
	 *
	 * @return array{0:array<string,array<string,mixed>>,1:array<string,array<string,mixed>>} Standby, and busy standby, by uid.
	 */
	private function standbyCandidates(array $slots, array $busy): array {
		$standby = [];
		$busyStandby = [];
		foreach ($slots as $uid => $slot) {
			$uid = (string)$uid;
			if (isset($busy[$uid]) === true) {
				$busyStandby[$uid] = $this->candidate(uid: $uid, why: ['busy', 'Has a lesson then']);
				continue;
			}

			$window = ['startsAt' => (string)$slot['startsAt'], 'endsAt' => (string)$slot['endsAt']];
			$standby[$uid] = $this->candidate(uid: $uid, why: ['standby', sprintf('On standby %s to %s', $window['startsAt'], $window['endsAt'])]);
			$standby[$uid]['slot'] = $window;
		}

		return [$standby, $busyStandby];
	}//end standbyCandidates()

	/**
	 * Staff who work that weekday and are not listed, busy or absent.
	 *
	 * @param string            $weekday The lesson's weekday.
	 * @param array<int,string> $skip    Teachers already listed, busy or absent.
	 *
	 * @return array<string,array<string,mixed>> By uid.
	 */
	private function freeCandidates(string $weekday, array $skip): array {
		$free = [];
		foreach ($this->read(schema: 'staff', filters: []) as $staff) {
			$uid = (string)($staff['ncUserId'] ?? '');
			$works = in_array($weekday, (array)($staff['workingDays'] ?? []), true);
			if ($uid !== '' && $works === true && in_array($uid, $skip, true) === false) {
				$free[$uid] = $this->candidate(uid: $uid, why: ['free', 'Free then']);
			}
		}

		return $free;
	}//end freeCandidates()

	/**
	 * The teachers who teach or cover a lesson overlapping the window.
	 *
	 * @param array{start: DateTimeImmutable, end: DateTimeImmutable, except: string} $window The window, and the lesson being covered.
	 *
	 * @return array<string,true>
	 */
	private function busyTeachers(array $window): array {
		$teachersByCohort = $this->teachersByCohort();
		$lessons = $this->dayLessons(cohortIds: array_keys($teachersByCohort), start: $window['start']);

		$busy = [];
		foreach ($lessons as $lesson) {
			if ($this->overlaps(lesson: $lesson, window: $window) === false) {
				continue;
			}

			foreach (self::teachersOf(lesson: $lesson, teachersByCohort: $teachersByCohort) as $uid) {
				if ($uid !== '') {
					$busy[$uid] = true;
				}
			}
		}

		return $busy;
	}//end busyTeachers()

	/**
	 * Every cohort's teachers, by cohort id.
	 *
	 * @return array<string,list<string>>
	 */
	private function teachersByCohort(): array {
		$teachersByCohort = [];
		foreach ($this->read(schema: 'cohort', filters: []) as $cohort) {
			$id = (string)($cohort['id'] ?? ($cohort['uuid'] ?? ''));
			if ($id !== '') {
				$teachersByCohort[$id] = array_map('strval', (array)($cohort['teacherIds'] ?? []));
			}
		}

		return $teachersByCohort;
	}//end teachersByCohort()

	/**
	 * The lessons of these cohorts on the day the window starts; none when the timetable cannot be read.
	 *
	 * @param array<int,string|int> $cohortIds The cohorts.
	 * @param DateTimeImmutable     $start     The window start.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function dayLessons(array $cohortIds, DateTimeImmutable $start): array {
		$dayStart = $start->setTime(0, 0);
		$dayEnd = $dayStart->modify('+1 day');
		try {
			return $this->sources->current()->sessionsForCohorts(
				cohortIds: $cohortIds,
				from: $dayStart->format(DATE_ATOM),
				to: $dayEnd->format(DATE_ATOM)
			);
		} catch (Throwable $exception) {
			// Without the timetable nobody is known to be busy; the list says
			// who is on standby and who works that day, and a person chooses.
			return [];
		}
	}//end dayLessons()

	/**
	 * Who teaches a lesson: its cohort's teachers, its substitute and its own teacher (may be '').
	 *
	 * @param array<string,mixed>        $lesson           The lesson.
	 * @param array<string,list<string>> $teachersByCohort Every cohort's teachers.
	 *
	 * @return list<string>
	 */
	private static function teachersOf(array $lesson, array $teachersByCohort): array {
		$teachers = ($teachersByCohort[(string)($lesson['cohortId'] ?? '')] ?? []);
		$teachers[] = (string)($lesson['substituteTeacherId'] ?? '');
		$teachers[] = (string)($lesson['teacherUserId'] ?? '');

		return $teachers;
	}//end teachersOf()

	/**
	 * Whether a lesson that is not cancelled, and not the one being covered, overlaps the window.
	 *
	 * @param array<string,mixed>                                                      $lesson The lesson.
	 * @param array{start: DateTimeImmutable, end: DateTimeImmutable, except: string} $window The window, and the lesson to ignore.
	 *
	 * @return bool
	 */
	private function overlaps(array $lesson, array $window): bool {
		if (self::ignored(lesson: $lesson, except: $window['except']) === true) {
			return false;
		}

		$lessonStart = $this->time(row: $lesson, field: 'startsAt');
		$lessonEnd = $this->time(row: $lesson, field: 'endsAt');

		return $lessonStart !== null && $lessonEnd !== null && $lessonStart < $window['end'] && $window['start'] < $lessonEnd;
	}//end overlaps()

	/**
	 * Whether a lesson does not count: it is the one being covered, or it is cancelled.
	 *
	 * @param array<string,mixed> $lesson The lesson.
	 * @param string              $except The lesson being covered.
	 *
	 * @return bool
	 */
	private static function ignored(array $lesson, string $except): bool {
		return (string)($lesson['id'] ?? '') === $except || ($lesson['lifecycle'] ?? '') === 'cancelled';
	}//end ignored()

	/**
	 * A candidate row with the teacher's display name.
	 *
	 * @param string                    $uid The teacher.
	 * @param array{0: string, 1: string} $why Its group (`standby`, `free` or `busy`) and why they are listed.
	 *
	 * @return array<string,mixed>
	 */
	private function candidate(string $uid, array $why): array {
		$user = $this->userManager->get($uid);
		$name = $uid;
		if ($user !== null) {
			$name = $user->getDisplayName();
		}

		return ['userId' => $uid, 'displayName' => $name, 'group' => $why[0], 'reason' => $why[1]];
	}//end candidate()

	/**
	 * Rows sorted by display name.
	 *
	 * @param array<string,array<string,mixed>> $rows Candidates by uid.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function sorted(array $rows): array {
		$rows = array_values($rows);
		usort($rows, static fn (array $a, array $b): int => strcmp((string)$a['displayName'], (string)$b['displayName']));
		return $rows;
	}//end sorted()

	/**
	 * A row's ISO timestamp field, or null when it is missing, empty or unreadable.
	 *
	 * @param array<string,mixed> $row   The session or lesson.
	 * @param string              $field `startsAt` or `endsAt`.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function time(array $row, string $field): ?DateTimeImmutable {
		$value = (string)($row[$field] ?? '');
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $exception) {
			return null;
		}
	}//end time()

	/**
	 * Read learniq objects as plain arrays.
	 *
	 * @param string              $schema  Schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function read(string $schema, array $filters): array {
		// Without the caller's RBAC: the controller decides who may ask, and a
		// coordinator outside the staff groups still needs the staff list.
		$rows = $this->objectService->findAll(
			['filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters)],
			_rbac: false
		);

		$out = [];
		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = (array)$row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end read()
}//end class
