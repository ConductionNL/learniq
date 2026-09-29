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
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use Throwable;

/**
 * Lists the teachers who can cover a lesson, standby first.
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-the-substitution-dialog-offers-standby-teachers-first
 */
class SubstitutionCandidateService {

	private const REGISTER = 'learniq';

	private const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService           $objectService Slots, staff and cohorts.
	 * @param TimetableSourceResolver $sources       Where lessons are read from.
	 * @param IUserManager            $userManager   Display names.
	 * @param StandbyCalendar         $calendar      Whether a slot covers a lesson.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly TimetableSourceResolver $sources,
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
		$start = $this->parse(value: (string)($session['startsAt'] ?? ''));
		$end = $this->parse(value: (string)($session['endsAt'] ?? ''));
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
		$busy = $this->busyTeachers(start: $start, end: $end, exceptSessionId: (string)($session['id'] ?? ($session['uuid'] ?? '')));

		[$standby, $busyStandby] = $this->standbyCandidates(lesson: $lesson, absent: $absent, busy: $busy);
		$skip = array_merge(array_keys($standby), array_keys($busyStandby), array_keys($busy), $absent);
		$free = $this->freeCandidates(weekday: $lesson['weekday'], skip: $skip);

		return array_values(array_merge($this->sorted(rows: $standby), $this->sorted(rows: $free), $this->sorted(rows: $busyStandby)));
	}//end forSession()

	/**
	 * The teachers on standby during the lesson: free ones, and those who teach then.
	 *
	 * @param array<string,string> $lesson The lesson's date, weekday, from, to and location.
	 * @param array<int,string>    $absent The lesson's own teachers.
	 * @param array<string,true>   $busy   Teachers with a lesson then.
	 *
	 * @return array{0:array<string,array<string,mixed>>,1:array<string,array<string,mixed>>} Standby, and busy standby, by uid.
	 */
	private function standbyCandidates(array $lesson, array $absent, array $busy): array {
		$standby = [];
		$busyStandby = [];
		foreach ($this->read(schema: 'standby-slot', filters: []) as $slot) {
			$uid = (string)($slot['teacherId'] ?? '');
			if ($uid === '' || in_array($uid, $absent, true) === true || $this->calendar->covers(slot: $slot, lesson: $lesson) === false) {
				continue;
			}

			if (isset($busy[$uid]) === true) {
				$busyStandby[$uid] = $this->candidate(uid: $uid, group: 'busy', reason: 'Has a lesson then');
				continue;
			}

			$window = ['startsAt' => (string)$slot['startsAt'], 'endsAt' => (string)$slot['endsAt']];
			$standby[$uid] = $this->candidate(uid: $uid, group: 'standby', reason: sprintf('On standby %s to %s', $window['startsAt'], $window['endsAt']));
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
				$free[$uid] = $this->candidate(uid: $uid, group: 'free', reason: 'Free then');
			}
		}

		return $free;
	}//end freeCandidates()

	/**
	 * The teachers who teach or cover a lesson overlapping the window.
	 *
	 * @param DateTimeImmutable $start           Window start.
	 * @param DateTimeImmutable $end             Window end.
	 * @param string            $exceptSessionId The lesson being covered.
	 *
	 * @return array<string,true>
	 */
	private function busyTeachers(DateTimeImmutable $start, DateTimeImmutable $end, string $exceptSessionId): array {
		$teachersByCohort = [];
		foreach ($this->read(schema: 'cohort', filters: []) as $cohort) {
			$id = (string)($cohort['id'] ?? ($cohort['uuid'] ?? ''));
			if ($id !== '') {
				$teachersByCohort[$id] = array_map('strval', (array)($cohort['teacherIds'] ?? []));
			}
		}

		$dayStart = $start->setTime(0, 0);
		$dayEnd = $dayStart->modify('+1 day');
		try {
			$lessons = $this->sources->current()->sessionsForCohorts(
				cohortIds: array_keys($teachersByCohort),
				from: $dayStart->format(DATE_ATOM),
				to: $dayEnd->format(DATE_ATOM)
			);
		} catch (Throwable $exception) {
			// Without the timetable nobody is known to be busy; the list says
			// who is on standby and who works that day, and a person chooses.
			return [];
		}

		$busy = [];
		foreach ($lessons as $lesson) {
			if ($this->overlaps(lesson: $lesson, start: $start, end: $end, exceptSessionId: $exceptSessionId) === false) {
				continue;
			}

			$teachers = ($teachersByCohort[(string)($lesson['cohortId'] ?? '')] ?? []);
			$teachers[] = (string)($lesson['substituteTeacherId'] ?? '');
			$teachers[] = (string)($lesson['teacherUserId'] ?? '');
			foreach ($teachers as $uid) {
				if ($uid !== '') {
					$busy[$uid] = true;
				}
			}
		}

		return $busy;
	}//end busyTeachers()

	/**
	 * Whether a lesson that is not cancelled overlaps the window.
	 *
	 * @param array<string,mixed> $lesson          The lesson.
	 * @param DateTimeImmutable   $start           Window start.
	 * @param DateTimeImmutable   $end             Window end.
	 * @param string              $exceptSessionId A lesson to ignore.
	 *
	 * @return bool
	 */
	private function overlaps(array $lesson, DateTimeImmutable $start, DateTimeImmutable $end, string $exceptSessionId): bool {
		if ((string)($lesson['id'] ?? '') === $exceptSessionId || ($lesson['lifecycle'] ?? '') === 'cancelled') {
			return false;
		}

		$lessonStart = $this->parse(value: (string)($lesson['startsAt'] ?? ''));
		$lessonEnd = $this->parse(value: (string)($lesson['endsAt'] ?? ''));

		return $lessonStart !== null && $lessonEnd !== null && $lessonStart < $end && $start < $lessonEnd;
	}//end overlaps()

	/**
	 * A candidate row with the teacher's display name.
	 *
	 * @param string $uid    The teacher.
	 * @param string $group  `standby`, `free` or `busy`.
	 * @param string $reason Why they are listed.
	 *
	 * @return array<string,mixed>
	 */
	private function candidate(string $uid, string $group, string $reason): array {
		$user = $this->userManager->get($uid);
		$name = $uid;
		if ($user !== null) {
			$name = $user->getDisplayName();
		}

		return ['userId' => $uid, 'displayName' => $name, 'group' => $group, 'reason' => $reason];
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
	 * An ISO timestamp, or null.
	 *
	 * @param string $value The timestamp.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function parse(string $value): ?DateTimeImmutable {
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $exception) {
			return null;
		}
	}//end parse()

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
