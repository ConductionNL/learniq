<?php

/**
 * Learniq Roll Call Service
 *
 * The day's register of one group: what the page shows and what one save
 * writes. Who may open which group and day is RollCallAccess; the lesson a
 * register writes against (and a new one when the day has none) is
 * RollCallLessons; the rules of one mark are RollCallMarks.
 *
 * - Every pupil starts as present; a saved mark wins, then an approved
 *   absence report covering the day.
 * - A save writes only the pupils whose mark changed, and leaves a self
 *   check-in the teacher did not touch as it was.
 * - The server writes the records itself (`_rbac: false`) after the access
 *   check, so a coordinator needs no write rule on AttendanceRecord.
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
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IUser;

/**
 * Opens and saves one group's register of one day.
 *
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
 */
class RollCallService {
	use RollCallRows;

	private const REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param RollCallReader  $reader        The reads behind the page.
	 * @param RollCallAccess  $access        Who opens and saves what.
	 * @param RollCallLessons $lessons       The lesson a register writes against.
	 * @param RollCallMarks   $marks         The rules of one mark.
	 * @param ObjectService   $objectService OpenRegister writes.
	 * @param ITimeFactory    $time          Clock for markedAt.
	 * @param IL10N           $l10n          Messages.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RollCallReader $reader,
		private readonly RollCallAccess $access,
		private readonly RollCallLessons $lessons,
		private readonly RollCallMarks $marks,
		private readonly ObjectService $objectService,
		private readonly ITimeFactory $time,
		private readonly IL10N $l10n,
	) {
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
		$zone = $this->access->zone(user: $user);
		$today = $this->access->today(zone: $zone);
		$date = $this->validDate(date: ($date ?? $today));
		$cohorts = $this->access->cohortsFor(user: $user);
		$register = [
			'date' => $date,
			'today' => $today,
			'cohorts' => array_map(fn (array $cohort): array => ['id' => $this->idOf(row: $cohort), 'name' => (string)($cohort['name'] ?? '')], $cohorts),
			'cohortId' => null,
			'cohortName' => '',
			'sessions' => [],
			'sessionId' => null,
			'editable' => false,
			'locked' => '',
			'pupils' => [],
		];
		if ($cohorts === []) {
			return $register;
		}

		$cohort = $this->access->chosenCohort(cohorts: $cohorts, cohortId: $cohortId);
		$sessions = $this->reader->sessionsOn(cohortId: $this->idOf(row: $cohort), date: $date, zone: $zone);
		$session = $this->lessons->chosen(sessions: $sessions, sessionId: $sessionId);
		$records = $this->reader->recordsOf(sessionId: $this->idOrNull(row: $session));
		$locked = $this->access->lockReason(user: $user, date: $date, today: $today, records: $records);

		return array_merge(
			$register,
			[
				'cohortId' => $this->idOf(row: $cohort),
				'cohortName' => (string)($cohort['name'] ?? ''),
				'sessions' => array_map(fn (array $lesson): array => $this->lessonOption(lesson: $lesson), $sessions),
				'sessionId' => $this->idOrNull(row: $session),
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
	 * @param array<int, mixed>                $marks     One mark per pupil: learnerId, status, lateMinutes, absenceReasonKind, reason.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RollCallException When the caller may not save it, or a mark is incomplete.
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
	 */
	public function save(IUser $user, string $cohortId, string $date, ?string $sessionId, array $marks): array {
		$zone = $this->access->zone(user: $user);
		$date = $this->validDate(date: $date);
		$cohort = $this->access->chosenCohort(cohorts: $this->access->cohortsFor(user: $user), cohortId: $cohortId);
		$profiles = $this->reader->profilesOf(learnerIds: $this->learnerIdsOf(cohort: $cohort));
		$this->refuseProblems(marks: $marks, learnerIds: $this->learnerIdsOf(cohort: $cohort), profiles: $profiles);

		$session = $this->lessons->chosen(sessions: $this->reader->sessionsOn(cohortId: $cohortId, date: $date, zone: $zone), sessionId: $sessionId);
		$records = $this->reader->recordsOf(sessionId: $this->idOrNull(row: $session));
		$this->refuseLocked(locked: $this->access->lockReason(user: $user, date: $date, today: $this->access->today(zone: $zone), records: $records));

		$session = ($session ?? $this->lessons->create(cohort: $cohort, date: $date, zone: $zone));
		$context = ['user' => $user->getUID(), 'cohort' => $cohort, 'session' => $session, 'profiles' => $profiles, 'date' => $date];
		foreach ($marks as $mark) {
			$this->writeMark(mark: $mark, record: ($records[(string)$mark['learnerId']] ?? null), context: $context);
		}

		return $this->open(user: $user, cohortId: $cohortId, date: $date, sessionId: $this->idOf(row: $session));
	}//end save()

	/**
	 * Write one mark as an AttendanceRecord, unless the saved record already holds it.
	 *
	 * @param array<string, mixed>      $mark    A valid mark.
	 * @param array<string, mixed>|null $record  The pupil's saved record.
	 * @param array<string, mixed>      $context user, cohort, session, profiles and date.
	 *
	 * @return void
	 */
	private function writeMark(array $mark, ?array $record, array $context): void {
		$learnerId = (string)$mark['learnerId'];
		$normal = $this->marks->normalise(mark: $mark);
		if ($record !== null && $this->marks->unchanged(record: $record, mark: $normal) === true) {
			return;
		}

		$profile = ($context['profiles'][$learnerId] ?? null);
		$minutes = $this->lessons->lengthOf(session: $context['session']);
		$body = array_merge(
			$normal,
			[
				'sessionId' => $this->idOf(row: $context['session']),
				'learnerId' => $learnerId,
				'learnerRef' => $this->idOrNull(row: $profile) ?? ($record['learnerRef'] ?? null),
				'cohortId' => $this->idOf(row: $context['cohort']),
				'minutesAttended' => $this->marks->minutesAttended(mark: $normal, minutes: $minutes, record: $record),
				'excuseRequestId' => $this->excuseFor(status: $normal['status'], learnerId: $learnerId, date: $context['date'], record: $record),
				'markedBy' => $context['user'],
				'markedAt' => (new DateTimeImmutable('@' . $this->time->getTime()))->format(DATE_ATOM),
				'markedVia' => 'teacher',
				'tenant_id' => (string)($context['session']['tenant_id'] ?? ($context['cohort']['tenant_id'] ?? '')),
			]
		);

		if ($record === null) {
			$this->objectService->saveObject(object: $body, register: self::REGISTER, schema: 'attendance-record', _rbac: false);
			return;
		}

		$object = array_merge($record, $body);
		unset($object['@self'], $object['id'], $object['uuid']);
		$uuid = $this->idOf(row: $record);
		$this->objectService->saveObject(object: $object, register: self::REGISTER, schema: 'attendance-record', uuid: $uuid, _rbac: false);
	}//end writeMark()

	/**
	 * The absence report an excused record points at: the approved report
	 * covering the day, else the one it already pointed at; none for any
	 * other status.
	 *
	 * @param string                    $status    The new status.
	 * @param string                    $learnerId The pupil.
	 * @param string                    $date      The day.
	 * @param array<string, mixed>|null $record    The saved record.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-an-approved-absence-report-fills-in-the-register
	 */
	private function excuseFor(string $status, string $learnerId, string $date, ?array $record): ?string {
		if ($status !== 'absent-excused') {
			return null;
		}

		$report = ($this->reader->reportsOn(learnerIds: [$learnerId], date: $date)[$learnerId] ?? null);
		if ($report !== null && ($report['lifecycle'] ?? null) === 'approved') {
			return $this->idOf(row: $report);
		}

		return ($record['excuseRequestId'] ?? null);
	}//end excuseFor()

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
			if (is_array($mark) === false) {
				$names[] = '?';
				continue;
			}

			if ($this->marks->problemWith(mark: $mark, learnerIds: $learnerIds) !== null) {
				$learnerId = (string)($mark['learnerId'] ?? '');
				$names[] = $this->nameOf(learnerId: $learnerId, profile: ($profiles[$learnerId] ?? null));
			}
		}

		if ($names !== []) {
			throw new RollCallException(
				$this->l10n->t('Check the marks of: %s. A late mark needs its minutes and an absence with permission needs a reason.', [implode(', ', $names)]),
				RollCallException::UNPROCESSABLE
			);
		}
	}//end refuseProblems()

	/**
	 * Refuse the save of a locked day.
	 *
	 * @param string $locked The lock reason, '' when the day can be saved.
	 *
	 * @return void
	 *
	 * @throws RollCallException 403 for a later day or an earlier saved day.
	 */
	private function refuseLocked(string $locked): void {
		if ($locked === RollCallAccess::LOCKED_FUTURE) {
			throw new RollCallException($this->l10n->t('You can only take the register for today or an earlier day.'), RollCallException::FORBIDDEN);
		}

		if ($locked === RollCallAccess::LOCKED_PAST_SAVED) {
			throw new RollCallException($this->l10n->t('This day\'s register is saved. Ask a coordinator to change it.'), RollCallException::FORBIDDEN);
		}
	}//end refuseLocked()

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

		usort($rows, static fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));

		return $rows;
	}//end pupils()

	/**
	 * What the page shows of a lesson.
	 *
	 * @param array<string, mixed> $lesson The lesson.
	 *
	 * @return array<string, mixed>
	 */
	private function lessonOption(array $lesson): array {
		return [
			'id' => $this->idOf(row: $lesson),
			'title' => (string)($lesson['title'] ?? ''),
			'startsAt' => ($lesson['startsAt'] ?? null),
			'endsAt' => ($lesson['endsAt'] ?? null),
		];
	}//end lessonOption()

	/**
	 * The pupils of a group.
	 *
	 * @param array<string, mixed> $cohort The group.
	 *
	 * @return array<int, string>
	 */
	private function learnerIdsOf(array $cohort): array {
		$ids = array_filter((array)($cohort['learnerIds'] ?? []), static fn ($id): bool => is_string($id) === true && $id !== '');

		return array_values(array_unique($ids));
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
		if ($name === '') {
			return $learnerId;
		}

		return $name;
	}//end nameOf()

	/**
	 * The id of a row, or null for no row.
	 *
	 * @param array<string, mixed>|null $row The row.
	 *
	 * @return string|null
	 */
	private function idOrNull(?array $row): ?string {
		if ($row === null) {
			return null;
		}

		return $this->idOf(row: $row);
	}//end idOrNull()

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
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1 || checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]) === false) {
			throw new RollCallException($this->l10n->t('Choose a day.'), RollCallException::BAD_REQUEST);
		}

		return $date;
	}//end validDate()
}//end class
