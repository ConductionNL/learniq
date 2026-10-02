<?php

/**
 * Learniq Roll Call Reader
 *
 * The OpenRegister reads behind the roll-call: groups, the day's lessons, the
 * saved marks, the pupils' names and the absence reports that cover the day.
 * Every read passes `_rbac: false`: RollCallService has already decided that
 * the caller may see this group, and a coordinator has no read rule on
 * Session or LearnerProfile.
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
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Reads what one group's register of one day needs.
 *
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
 */
class RollCallReader {

	private const REGISTER = 'learniq';

	/**
	 * A group that ended is last year's.
	 */
	private const PAST_LIFECYCLES = ['completed', 'archived'];

	/**
	 * Upper bounds on one read.
	 */
	private const MAX_GROUPS = 500;
	private const MAX_ROWS = 1000;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Every current group, sorted by name.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-who-may-open-which-groups-register
	 */
	public function currentCohorts(): array {
		$cohorts = array_filter(
			$this->rows(schema: 'cohort', filters: [], limit: self::MAX_GROUPS),
			static fn (array $row): bool => in_array(($row['lifecycle'] ?? null), self::PAST_LIFECYCLES, true) === false
		);
		usort($cohorts, static fn (array $a, array $b): int => strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));

		return array_values($cohorts);
	}//end currentCohorts()

	/**
	 * The teachers of a group: its teacherIds and every teacherAssignments[].teacherId.
	 *
	 * @param array<string, mixed> $cohort The group.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-who-may-open-which-groups-register
	 */
	public static function teachersOf(array $cohort): array {
		$ids = (array)($cohort['teacherIds'] ?? []);
		foreach ((array)($cohort['teacherAssignments'] ?? []) as $assignment) {
			if (is_array($assignment) === true) {
				$ids[] = ($assignment['teacherId'] ?? null);
			}
		}

		return array_values(array_filter($ids, static fn ($id): bool => is_string($id) === true && $id !== ''));
	}//end teachersOf()

	/**
	 * The group's lessons on a day, not cancelled, in order.
	 *
	 * The query asks a window around the day and the day itself is decided in
	 * PHP, in the caller's time zone, so a lesson is never matched on the
	 * wrong side of midnight.
	 *
	 * @param string       $cohortId The group.
	 * @param string       $date     The day, `Y-m-d`.
	 * @param DateTimeZone $zone     The caller's time zone.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-day-without-a-lesson-gets-one-when-the-register-is-saved
	 */
	public function sessionsOn(string $cohortId, string $date, DateTimeZone $zone): array {
		$start = new DateTimeImmutable($date . 'T00:00:00', $zone);
		$rows = $this->rows(
			schema: 'session',
			filters: [
				'cohortId' => $cohortId,
				'startsAt' => [
					'gte' => $start->modify('-1 day')->format(DATE_ATOM),
					'lte' => $start->modify('+2 days')->format(DATE_ATOM),
				],
			],
			limit: self::MAX_ROWS
		);

		$sessions = array_filter(
			$rows,
			static fn (array $row): bool => ($row['cohortId'] ?? null) === $cohortId
				&& ($row['lifecycle'] ?? null) !== 'cancelled'
				&& self::localDate(value: ($row['startsAt'] ?? null), zone: $zone) === $date
		);
		usort($sessions, static fn (array $a, array $b): int => strcmp((string)self::moment(value: $a['startsAt'])?->getTimestamp(), (string)self::moment(value: $b['startsAt'])?->getTimestamp()));

		return array_values($sessions);
	}//end sessionsOn()

	/**
	 * The group's most recent lessons before a day, newest first.
	 *
	 * @param string       $cohortId The group.
	 * @param string       $date     The day, `Y-m-d`.
	 * @param DateTimeZone $zone     The caller's time zone.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function lessonsBefore(string $cohortId, string $date, DateTimeZone $zone): array {
		$start = new DateTimeImmutable($date . 'T00:00:00', $zone);
		$rows = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => 'session',
					'cohortId' => $cohortId,
					'startsAt' => ['lt' => $start->format(DATE_ATOM), 'gte' => $start->modify('-60 days')->format(DATE_ATOM)],
				],
				'sort' => ['startsAt' => 'DESC'],
				'limit' => 20,
			],
			_rbac: false,
			_multitenancy: false
		);

		$lessons = array_filter(
			array_map(fn ($row): array => self::toRow(object: $row), $rows),
			static fn (array $row): bool => ($row['cohortId'] ?? null) === $cohortId
				&& (self::moment(value: ($row['startsAt'] ?? null))?->getTimestamp() ?? PHP_INT_MAX) < $start->getTimestamp()
		);
		usort($lessons, static fn (array $a, array $b): int => (self::moment(value: $b['startsAt'])?->getTimestamp() <=> self::moment(value: $a['startsAt'])?->getTimestamp()));

		return array_values($lessons);
	}//end lessonsBefore()

	/**
	 * The saved marks of a lesson, keyed by pupil.
	 *
	 * @param string|null $sessionId The lesson, or null when the day has none yet.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function recordsOf(?string $sessionId): array {
		if ($sessionId === null) {
			return [];
		}

		$records = [];
		foreach ($this->rows(schema: 'attendance-record', filters: ['sessionId' => $sessionId], limit: self::MAX_ROWS) as $row) {
			$learnerId = (string)($row['learnerId'] ?? '');
			if (($row['sessionId'] ?? null) === $sessionId && $learnerId !== '') {
				$records[$learnerId] = $row;
			}
		}

		return $records;
	}//end recordsOf()

	/**
	 * The pupils' profiles, keyed by Nextcloud user id.
	 *
	 * @param array<int, string> $learnerIds The pupils.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function profilesOf(array $learnerIds): array {
		if ($learnerIds === []) {
			return [];
		}

		$profiles = [];
		foreach ($this->rows(schema: 'learner-profile', filters: ['ncUserId' => $learnerIds], limit: self::MAX_ROWS) as $row) {
			$userId = (string)($row['ncUserId'] ?? '');
			if (in_array($userId, $learnerIds, true) === true && isset($profiles[$userId]) === false) {
				$profiles[$userId] = $row;
			}
		}

		return $profiles;
	}//end profilesOf()

	/**
	 * The approved or still open absence reports that cover a day, keyed by pupil.
	 * An approved report wins over an open one.
	 *
	 * @param array<int, string> $learnerIds The pupils.
	 * @param string             $date       The day, `Y-m-d`.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-an-approved-absence-report-fills-in-the-register
	 */
	public function reportsOn(array $learnerIds, string $date): array {
		if ($learnerIds === []) {
			return [];
		}

		$reports = [];
		$rows = $this->rows(schema: 'excuse-request', filters: ['learnerId' => $learnerIds, 'lifecycle' => ['approved', 'submitted']], limit: self::MAX_ROWS);
		foreach ($rows as $row) {
			$learnerId = (string)($row['learnerId'] ?? '');
			$from = substr((string)($row['dateFrom'] ?? ''), 0, 10);
			$to = substr((string)($row['dateTo'] ?? ''), 0, 10);
			if (in_array($learnerId, $learnerIds, true) === false || $from === '' || $to === '' || $date < $from || $date > $to) {
				continue;
			}

			$lifecycle = (string)($row['lifecycle'] ?? '');
			if (in_array($lifecycle, ['approved', 'submitted'], true) === false || (($reports[$learnerId]['lifecycle'] ?? '') === 'approved')) {
				continue;
			}

			$reports[$learnerId] = $row;
		}

		return $reports;
	}//end reportsOn()

	/**
	 * The date of a timestamp in a time zone, or null.
	 *
	 * @param mixed        $value An ISO 8601 date-time.
	 * @param DateTimeZone $zone  The time zone.
	 *
	 * @return string|null
	 */
	public static function localDate(mixed $value, DateTimeZone $zone): ?string {
		return self::moment(value: $value)?->setTimezone($zone)->format('Y-m-d');
	}//end localDate()

	/**
	 * A timestamp, or null.
	 *
	 * @param mixed $value An ISO 8601 date-time.
	 *
	 * @return DateTimeImmutable|null
	 */
	public static function moment(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || preg_match('/^\d{4}-\d{2}-\d{2}T/', $value) !== 1) {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable) {
			return null;
		}
	}//end moment()

	/**
	 * The id of an OpenRegister row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	public static function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['uuid'] ?? ($row['@self']['id'] ?? '')));
	}//end idOf()

	/**
	 * Rows of a learniq schema, as arrays.
	 *
	 * @param string               $schema  Schema slug.
	 * @param array<string, mixed> $filters Filters; a list is an IN filter.
	 * @param int                  $limit   Upper bound.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(string $schema, array $filters, int $limit): array {
		$rows = $this->objectService->findAll(
			config: [
				'filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters),
				'limit' => $limit,
			],
			_rbac: false,
			_multitenancy: false
		);

		return array_values(array_map(fn ($row): array => self::toRow(object: $row), $rows));
	}//end rows()

	/**
	 * An OpenRegister result as an array, with its id.
	 *
	 * @param mixed $object An entity or an array.
	 *
	 * @return array<string, mixed>
	 */
	private static function toRow(mixed $object): array {
		$row = [];
		if (is_array($object) === true) {
			$row = $object;
		} else if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$row = (array)$object->jsonSerialize();
		}

		if ($row !== [] && isset($row['id']) === false) {
			$row['id'] = self::idOf(row: $row);
		}

		return $row;
	}//end toRow()
}//end class
