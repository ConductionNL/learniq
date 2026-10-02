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
	use RollCallRows;

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
		usort($cohorts, static fn (array $left, array $right): int => strnatcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? '')));

		return $cohorts;
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
	public function teachersOf(array $cohort): array {
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
			fn (array $row): bool => ($row['cohortId'] ?? null) === $cohortId
				&& ($row['lifecycle'] ?? null) !== 'cancelled'
				&& $this->localDate(value: ($row['startsAt'] ?? null), zone: $zone) === $date
		);
		usort($sessions, fn (array $left, array $right): int => ($this->timestampOf(row: $left) <=> $this->timestampOf(row: $right)));

		return $sessions;
	}//end sessionsOn()

	/**
	 * The saved marks of a lesson, keyed by pupil.
	 *
	 * @param string|null $sessionId The lesson, or null when the day has none yet.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
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
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
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
		$filters = ['learnerId' => $learnerIds, 'lifecycle' => ['approved', 'submitted']];
		$rows = $this->rows(schema: 'excuse-request', filters: $filters, limit: self::MAX_ROWS);
		foreach ($rows as $row) {
			$learnerId = (string)($row['learnerId'] ?? '');
			if (in_array($learnerId, $learnerIds, true) === false || $this->covers(report: $row, date: $date) === false) {
				continue;
			}

			// An approved report wins over one still waiting for a decision.
			if (($reports[$learnerId]['lifecycle'] ?? '') !== 'approved') {
				$reports[$learnerId] = $row;
			}
		}

		return $reports;
	}//end reportsOn()

	/**
	 * Whether an approved or open report covers a day.
	 *
	 * @param array<string, mixed> $report The report.
	 * @param string               $date   The day, `Y-m-d`.
	 *
	 * @return bool
	 */
	private function covers(array $report, string $date): bool {
		$from = substr((string)($report['dateFrom'] ?? ''), 0, 10);
		$to = substr((string)($report['dateTo'] ?? ''), 0, 10);
		if ($from === '' || $to === '' || $date < $from || $date > $to) {
			return false;
		}

		return in_array(($report['lifecycle'] ?? null), ['approved', 'submitted'], true);
	}//end covers()

	/**
	 * The start of a lesson as a Unix time, 0 when unknown.
	 *
	 * @param array<string, mixed> $row The lesson.
	 *
	 * @return int
	 */
	private function timestampOf(array $row): int {
		return ($this->moment(value: ($row['startsAt'] ?? null))?->getTimestamp() ?? 0);
	}//end timestampOf()

	/**
	 * The date of a timestamp in a time zone, or null.
	 *
	 * @param mixed        $value An ISO 8601 date-time.
	 * @param DateTimeZone $zone  The time zone.
	 *
	 * @return string|null
	 */
	private function localDate(mixed $value, DateTimeZone $zone): ?string {
		return $this->moment(value: $value)?->setTimezone($zone)->format('Y-m-d');
	}//end localDate()

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

		return array_map(fn ($row): array => $this->toRow(object: $row), array_values($rows));
	}//end rows()

	/**
	 * An OpenRegister result as an array, with its id.
	 *
	 * @param mixed $object An entity or an array.
	 *
	 * @return array<string, mixed>
	 */
	private function toRow(mixed $object): array {
		$row = [];
		if (is_array($object) === true) {
			$row = $object;
		} else if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$row = (array)$object->jsonSerialize();
		}

		if ($row !== [] && isset($row['id']) === false) {
			$row['id'] = $this->idOf(row: $row);
		}

		return $row;
	}//end toRow()
}//end class
