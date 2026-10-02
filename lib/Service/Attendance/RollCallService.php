<?php

/**
 * Learniq Roll Call Service
 *
 * The day's register of one group: who may open it, what the page shows and
 * what one save writes.
 *
 * - A member of `instructors` opens the groups that list them in
 *   `teacherIds` or `teacherAssignments`; `coordinators`,
 *   `administration-managers` and admins open every current group.
 * - Every pupil starts as present; a saved mark wins, then an approved
 *   absence report covering the day.
 * - A group teacher saves today, and an earlier day nobody saved yet; an
 *   earlier saved day is read-only for them. School-wide staff save any day
 *   up to today. Nobody saves the future.
 * - A day without a lesson gets one on save, timed like the group's last
 *   lesson on the same weekday, or 08:30 to 14:30.
 * - The server writes the records itself (`_rbac: false`), after deciding
 *   access here, so a coordinator needs no write rule on AttendanceRecord.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Attendance
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
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Attendance;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Opens and saves one group's register of one day.
 *
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
 */
class RollCallService {

	private const REGISTER = 'learniq';
	private const TEACHER_GROUP = 'instructors';
	private const SCHOOL_WIDE_GROUPS = ['coordinators', 'administration-managers'];
	private const DEFAULT_START = '08:30';
	private const DEFAULT_END = '14:30';

	/**
	 * The page's lock reasons.
	 */
	public const LOCKED_FUTURE = 'future';
	public const LOCKED_PAST_SAVED = 'past-saved';

	private readonly RollCallReader $reader;
	private readonly RollCallMarks $marks;

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister object access.
	 * @param IGroupManager   $groupManager  Group membership.
	 * @param IDateTimeZone   $timeZone      The caller's time zone, for "today".
	 * @param ITimeFactory    $time          Clock.
	 * @param IL10N           $l10n          Messages and the lesson title's date.
	 * @param LoggerInterface $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IGroupManager $groupManager,
		private readonly IDateTimeZone $timeZone,
		private readonly ITimeFactory $time,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		$this->reader = new RollCallReader(objectService: $objectService);
		$this->marks = new RollCallMarks();
	}//end __construct()

	/**
	 * The register of a group on a day, as the page shows it.
	 *
	 * @param IUser       $user      The caller.
	 * @param string|null $cohortId  The group; null for the caller's first group.
	 * @param string|null $date      The day, `Y-m-d`; null for today.
	 * @param string|null $sessionId The lesson; null for the day's first lesson.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RollCallException When the caller may not open it, or the date is not a date.
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-who-may-open-which-groups-register
	 */
	public function open(IUser $user, ?string $cohortId, ?string $date, ?string $sessionId): array {
		$zone = $this->zone(user: $user);
		$today = $this->today(zone: $zone);
		$date = $this->validDate(date: ($date ?? $today));
		$cohorts = $this->cohortsFor(user: $user);
		$base = ['date' => $date, 'today' => $today, 'cohorts' => $this->cohortList(cohorts: $cohorts)];
		if ($cohorts === []) {
			return array_merge($base, ['cohortId' => null, 'cohortName' => '', 'sessions' => [], 'sessionId' => null, 'editable' => false, 'locked' => '', 'pupils' => []]);
		}

		$cohort = $this->chosenCohort(cohorts: $cohorts, cohortId: $cohortId);
		$sessions = $this->reader->sessionsOn(cohortId: RollCallReader::idOf(row: $cohort), date: $date, zone: $zone);
		$session = $this->chosenSession(sessions: $sessions, sessionId: $sessionId);
		$records = $this->reader->recordsOf(sessionId: ($session === null ? null : RollCallReader::idOf(row: $session)));
		$locked = $this->lockReason(user: $user, date: $date, today: $today, records: $records);

		return array_merge(
			$base,
			[
				'cohortId' => RollCallReader::idOf(row: $cohort),
				'cohortName' => (string)($cohort['name'] ?? ''),
				'sessions' => array_map(
					static fn (array $s): array => ['id' => RollCallReader::idOf(row: $s), 'title' => (string)($s['title'] ?? ''), 'startsAt' => $s['startsAt'] ?? null, 'endsAt' => $s['endsAt'] ?? null],
					$sessions
				),
				'sessionId' => ($session === null ? null : RollCallReader::idOf(row: $session)),
				'editable' => ($locked === ''),
				'locked' => $locked,
				'pupils' => $this->pupils(cohort: $cohort, records: $records, date: $date),
			]
		);
	}//end open()

	/**
	 * Save the marks of a group's register on a day, and answer with the register.
	 *
	 * @param IUser                            $user      The caller.
	 * @param string                           $cohortId  The group.
	 * @param string                           $date      The day, `Y-m-d`.
	 * @param string|null                      $sessionId The lesson; null for the day's first lesson, created when none.
	 * @param array<int, array<string, mixed>> $marks     One mark per pupil: learnerId, status, lateMinutes, absenceReasonKind, reason.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RollCallException When the caller may not save it, or a mark is incomplete.
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
	 */
	public function save(IUser $user, string $cohortId, string $date, ?string $sessionId, array $marks): array {
		$zone = $this->zone(user: $user);
		$today = $this->today(zone: $zone);
		$date = $this->validDate(date: $date);
		$cohort = $this->chosenCohort(cohorts: $this->cohortsFor(user: $user), cohortId: $cohortId);
		$learnerIds = $this->learnerIdsOf(cohort: $cohort);
		$profiles = $this->reader->profilesOf(learnerIds: $learnerIds);
		$this->refuseProblems(marks: $marks, learnerIds: $learnerIds, profiles: $profiles);

		$session = $this->chosenSession(sessions: $this->reader->sessionsOn(cohortId: $cohortId, date: $date, zone: $zone), sessionId: $sessionId);
		$records = $this->reader->recordsOf(sessionId: ($session === null ? null : RollCallReader::idOf(row: $session)));
		$locked = $this->lockReason(user: $user, date: $date, today: $today, records: $records);
		if ($locked === self::LOCKED_FUTURE) {
			throw new RollCallException($this->l10n->t('You can only take the register for today or an earlier day.'), Http::STATUS_FORBIDDEN);
		}

		if ($locked === self::LOCKED_PAST_SAVED) {
			throw new RollCallException($this->l10n->t('This day\'s register is saved. Ask a coordinator to change it.'), Http::STATUS_FORBIDDEN);
		}

		$session = ($session ?? $this->createSession(cohort: $cohort, date: $date, zone: $zone));
		$this->writeMarks(user: $user, cohort: $cohort, session: $session, marks: $marks, records: $records, profiles: $profiles, date: $date);

		return $this->open(user: $user, cohortId: $cohortId, date: $date, sessionId: RollCallReader::idOf(row: $session));
	}//end save()

	/**
	 * Write every changed mark as an AttendanceRecord.
	 *
	 * @param IUser                               $user     The caller.
	 * @param array<string, mixed>                $cohort   The group.
	 * @param array<string, mixed>                $session  The lesson.
	 * @param array<int, array<string, mixed>>    $marks    Valid marks.
	 * @param array<string, array<string, mixed>> $records  Saved records by pupil.
	 * @param array<string, array<string, mixed>> $profiles Profiles by pupil.
	 * @param string                              $date     The day.
	 *
	 * @return void
	 */
	private function writeMarks(IUser $user, array $cohort, array $session, array $marks, array $records, array $profiles, string $date): void {
		$reports = $this->reader->reportsOn(learnerIds: array_map(static fn (array $m): string => (string)$m['learnerId'], $marks), date: $date);
		$minutes = $this->lengthOf(session: $session);
		$markedAt = (new DateTimeImmutable('@' . $this->time->getTime()))->format(DateTimeInterface::ATOM);

		foreach ($marks as $mark) {
			$learnerId = (string)$mark['learnerId'];
			$normal = $this->marks->normalise(mark: $mark);
			$record = ($records[$learnerId] ?? null);
			if ($record !== null && $this->marks->unchanged(record: $record, mark: $normal) === true) {
				continue;
			}

			$report = ($reports[$learnerId] ?? null);
			$excuseId = null;
			if ($normal['status'] === 'absent-excused' && $report !== null && ($report['lifecycle'] ?? null) === 'approved') {
				$excuseId = RollCallReader::idOf(row: $report);
			} else if ($normal['status'] === 'absent-excused') {
				$excuseId = ($record['excuseRequestId'] ?? null);
			}

			$body = array_merge(
				$normal,
				[
					'sessionId' => RollCallReader::idOf(row: $session),
					'learnerId' => $learnerId,
					'learnerRef' => (isset($profiles[$learnerId]) === true ? RollCallReader::idOf(row: $profiles[$learnerId]) : ($record['learnerRef'] ?? null)),
					'cohortId' => RollCallReader::idOf(row: $cohort),
					'minutesAttended' => $this->marks->minutesAttended(mark: $normal, minutes: $minutes, record: $record),
					'excuseRequestId' => $excuseId,
					'markedBy' => $user->getUID(),
					'markedAt' => $markedAt,
					'markedVia' => 'teacher',
					'tenant_id' => (string)($session['tenant_id'] ?? ($cohort['tenant_id'] ?? '')),
				]
			);

			$this->writeRecord(record: $record, body: $body);
		}//end foreach
	}//end writeMarks()

	/**
	 * Create or update one record.
	 *
	 * @param array<string, mixed>|null $record The saved record, or null.
	 * @param array<string, mixed>      $body   The fields to write.
	 *
	 * @return void
	 */
	private function writeRecord(?array $record, array $body): void {
		if ($record === null) {
			$this->objectService->saveObject(object: $body, register: self::REGISTER, schema: 'attendance-record', _rbac: false);
			return;
		}

		$object = array_merge($record, $body);
		unset($object['@self'], $object['id'], $object['uuid']);
		$this->objectService->saveObject(object: $object, register: self::REGISTER, schema: 'attendance-record', uuid: RollCallReader::idOf(row: $record), _rbac: false);
	}//end writeRecord()

	/**
	 * Create the day's lesson for a group, timed like its last lesson on the same weekday.
	 *
	 * @param array<string, mixed> $cohort The group.
	 * @param string               $date   The day.
	 * @param DateTimeZone         $zone   The caller's time zone.
	 *
	 * @return array<string, mixed> The created lesson.
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-day-without-a-lesson-gets-one-when-the-register-is-saved
	 */
	private function createSession(array $cohort, string $date, DateTimeZone $zone): array {
		$day = new DateTimeImmutable($date . 'T12:00:00', $zone);
		[$start, $end] = [self::DEFAULT_START, self::DEFAULT_END];
		$earlier = $this->reader->lessonsBefore(cohortId: RollCallReader::idOf(row: $cohort), date: $date, zone: $zone);
		$model = null;
		foreach ($earlier as $lesson) {
			$moment = RollCallReader::moment(value: $lesson['startsAt'])?->setTimezone($zone);
			if ($moment !== null && $moment->format('N') === $day->format('N')) {
				$model = $lesson;
				break;
			}
		}

		$model = ($model ?? ($earlier[0] ?? null));
		$modelEnd = RollCallReader::moment(value: ($model['endsAt'] ?? null));
		if ($model !== null && $modelEnd !== null) {
			$start = RollCallReader::moment(value: $model['startsAt'])?->setTimezone($zone)->format('H:i') ?? $start;
			$end = $modelEnd->setTimezone($zone)->format('H:i');
		}

		$session = [
			'cohortId' => RollCallReader::idOf(row: $cohort),
			'title' => (string)($cohort['name'] ?? '') . ', ' . (string)$this->l10n->l('date', $day, ['width' => 'full']),
			'startsAt' => (new DateTimeImmutable($date . 'T' . $start . ':00', $zone))->format(DATE_ATOM),
			'endsAt' => (new DateTimeImmutable($date . 'T' . $end . ':00', $zone))->format(DATE_ATOM),
			'tenant_id' => (string)($cohort['tenant_id'] ?? ''),
		];
		if (is_string($cohort['courseId'] ?? null) === true && $cohort['courseId'] !== '') {
			$session['courseId'] = $cohort['courseId'];
		}

		$created = $this->objectService->saveObject(object: $session, register: self::REGISTER, schema: 'session', _rbac: false);
		$row = (array)$created->jsonSerialize();
		$row['id'] = RollCallReader::idOf(row: $row);
		if ($row['id'] === '') {
			$row['id'] = (string)$created->getUuid();
		}

		$this->logger->info('[RollCallService] Created the lesson {title} for a roll-call.', ['title' => $session['title']]);

		return array_merge($session, $row);
	}//end createSession()

	/**
	 * Refuse the save when a mark is incomplete, naming the pupils.
	 *
	 * @param array<int, mixed>                   $marks      The posted marks.
	 * @param array<int, string>                  $learnerIds The group's pupils.
	 * @param array<string, array<string, mixed>> $profiles   Profiles by pupil.
	 *
	 * @return void
	 *
	 * @throws RollCallException 422 when a mark cannot be saved.
	 */
	private function refuseProblems(array $marks, array $learnerIds, array $profiles): void {
		$names = [];
		foreach ($marks as $mark) {
			if (is_array($mark) === false || $this->marks->problemWith(mark: $mark, learnerIds: $learnerIds) !== null) {
				$learnerId = (string)(is_array($mark) === true ? ($mark['learnerId'] ?? '') : '');
				$names[] = $this->nameOf(learnerId: $learnerId, profile: ($profiles[$learnerId] ?? null));
			}
		}

		if ($names !== []) {
			throw new RollCallException(
				$this->l10n->t('Check the marks of: %s. A late mark needs its minutes and an absence with permission needs a reason.', [implode(', ', $names)]),
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}
	}//end refuseProblems()

	/**
	 * Why the register cannot be saved, or '' when it can.
	 *
	 * @param IUser                               $user    The caller.
	 * @param string                              $date    The day.
	 * @param string                              $today   Today.
	 * @param array<string, array<string, mixed>> $records The saved records.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-the-register-can-be-changed-the-same-day
	 */
	private function lockReason(IUser $user, string $date, string $today, array $records): string {
		if ($date > $today) {
			return self::LOCKED_FUTURE;
		}

		if ($date === $today || $this->isSchoolWide(user: $user) === true) {
			return '';
		}

		foreach ($records as $record) {
			if (($record['markedVia'] ?? 'teacher') !== 'self-check-in') {
				return self::LOCKED_PAST_SAVED;
			}
		}

		return '';
	}//end lockReason()

	/**
	 * The groups the caller may open.
	 *
	 * @param IUser $user The caller.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws RollCallException 403 for someone who is neither a teacher nor school-wide staff.
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-who-may-open-which-groups-register
	 */
	private function cohortsFor(IUser $user): array {
		if ($this->isSchoolWide(user: $user) === true) {
			return $this->reader->currentCohorts();
		}

		$uid = $user->getUID();
		if ($this->groupManager->isInGroup($uid, self::TEACHER_GROUP) === false) {
			throw new RollCallException($this->l10n->t('Only teachers and coordinators can take the register.'), Http::STATUS_FORBIDDEN);
		}

		return array_values(
			array_filter(
				$this->reader->currentCohorts(),
				static fn (array $cohort): bool => in_array($uid, RollCallReader::teachersOf(cohort: $cohort), true)
			)
		);
	}//end cohortsFor()

	/**
	 * Whether the caller opens every group.
	 *
	 * @param IUser $user The caller.
	 *
	 * @return bool
	 */
	private function isSchoolWide(IUser $user): bool {
		$uid = $user->getUID();
		if ($this->groupManager->isAdmin($uid) === true) {
			return true;
		}

		foreach (self::SCHOOL_WIDE_GROUPS as $group) {
			if ($this->groupManager->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		return false;
	}//end isSchoolWide()

	/**
	 * The asked group among the caller's groups, or the first one.
	 *
	 * @param array<int, array<string, mixed>> $cohorts  The caller's groups.
	 * @param string|null                      $cohortId The asked group.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RollCallException 403 when the asked group is not one of them.
	 */
	private function chosenCohort(array $cohorts, ?string $cohortId): array {
		foreach ($cohorts as $cohort) {
			if ($cohortId === null || $cohortId === '' || RollCallReader::idOf(row: $cohort) === $cohortId) {
				return $cohort;
			}
		}

		throw new RollCallException($this->l10n->t('You cannot take the register of this group.'), Http::STATUS_FORBIDDEN);
	}//end chosenCohort()

	/**
	 * The asked lesson among the day's lessons, else the first, else none.
	 *
	 * @param array<int, array<string, mixed>> $sessions  The day's lessons.
	 * @param string|null                      $sessionId The asked lesson.
	 *
	 * @return array<string, mixed>|null
	 */
	private function chosenSession(array $sessions, ?string $sessionId): ?array {
		foreach ($sessions as $session) {
			if (RollCallReader::idOf(row: $session) === $sessionId) {
				return $session;
			}
		}

		return ($sessions[0] ?? null);
	}//end chosenSession()

	/**
	 * The page rows of a group's pupils, sorted by name.
	 *
	 * @param array<string, mixed>                $cohort  The group.
	 * @param array<string, array<string, mixed>> $records Saved records by pupil.
	 * @param string                              $date    The day.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function pupils(array $cohort, array $records, string $date): array {
		$learnerIds = $this->learnerIdsOf(cohort: $cohort);
		$profiles = $this->reader->profilesOf(learnerIds: $learnerIds);
		$reports = $this->reader->reportsOn(learnerIds: $learnerIds, date: $date);

		$rows = [];
		foreach ($learnerIds as $learnerId) {
			$profile = ($profiles[$learnerId] ?? null);
			$rows[] = $this->marks->pupilRow(
				learnerId: $learnerId,
				name: $this->nameOf(learnerId: $learnerId, profile: $profile),
				profile: $profile,
				record: ($records[$learnerId] ?? null),
				report: ($reports[$learnerId] ?? null)
			);
		}

		usort($rows, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

		return $rows;
	}//end pupils()

	/**
	 * The pupils of a group.
	 *
	 * @param array<string, mixed> $cohort The group.
	 *
	 * @return array<int, string>
	 */
	private function learnerIdsOf(array $cohort): array {
		return array_values(array_unique(array_filter((array)($cohort['learnerIds'] ?? []), static fn ($id): bool => is_string($id) === true && $id !== '')));
	}//end learnerIdsOf()

	/**
	 * A pupil's name: given and family name, else the user id.
	 *
	 * @param string                    $learnerId The pupil.
	 * @param array<string, mixed>|null $profile   The pupil's profile.
	 *
	 * @return string
	 */
	private function nameOf(string $learnerId, ?array $profile): string {
		$name = trim((string)($profile['givenName'] ?? '') . ' ' . (string)($profile['familyName'] ?? ''));

		return ($name === '' ? $learnerId : $name);
	}//end nameOf()

	/**
	 * The groups as the page lists them.
	 *
	 * @param array<int, array<string, mixed>> $cohorts The groups.
	 *
	 * @return array<int, array{id: string, name: string}>
	 */
	private function cohortList(array $cohorts): array {
		return array_map(static fn (array $c): array => ['id' => RollCallReader::idOf(row: $c), 'name' => (string)($c['name'] ?? '')], $cohorts);
	}//end cohortList()

	/**
	 * A lesson's length in minutes, or null.
	 *
	 * @param array<string, mixed> $session The lesson.
	 *
	 * @return int|null
	 */
	private function lengthOf(array $session): ?int {
		$start = RollCallReader::moment(value: ($session['startsAt'] ?? null));
		$end = RollCallReader::moment(value: ($session['endsAt'] ?? null));
		if ($start === null || $end === null || $end <= $start) {
			return null;
		}

		return intdiv(($end->getTimestamp() - $start->getTimestamp()), 60);
	}//end lengthOf()

	/**
	 * A valid `Y-m-d` date.
	 *
	 * @param string $date The date.
	 *
	 * @return string
	 *
	 * @throws RollCallException 400 when it is not one.
	 */
	private function validDate(string $date): string {
		$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
		if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
			throw new RollCallException($this->l10n->t('Choose a day.'), Http::STATUS_BAD_REQUEST);
		}

		return $date;
	}//end validDate()

	/**
	 * The caller's time zone.
	 *
	 * @param IUser $user The caller.
	 *
	 * @return DateTimeZone
	 */
	private function zone(IUser $user): DateTimeZone {
		return $this->timeZone->getTimeZone(false, $user->getUID());
	}//end zone()

	/**
	 * Today in a time zone.
	 *
	 * @param DateTimeZone $zone The time zone.
	 *
	 * @return string
	 */
	private function today(DateTimeZone $zone): string {
		return (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone($zone)->format('Y-m-d');
	}//end today()
}//end class
