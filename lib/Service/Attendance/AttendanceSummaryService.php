<?php

/**
 * Learniq Attendance Summary Service
 *
 * Recounts one learner's AttendanceSummary rows from their AttendanceRecords.
 * Used by AttendanceSummaryRecomputeJob (after every record write) and by the
 * BackfillAttendanceSummaries repair step.
 *
 * A recount always counts the whole school year from the records and never
 * adds to the stored numbers, so a lost or doubled event cannot make a row
 * drift. It saves only the rows whose numbers changed, so a second run of the
 * repair step saves nothing.
 *
 * Runs without a session (a background job, a repair step), so it reads and
 * writes with `_rbac: false` and `_multitenancy: false`, and saves with the
 * system identity as owner (`_unowned`): the schema grants nobody `create` or
 * `update`, and an owner could otherwise edit their row.
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
 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-the-summary-follows-every-attendance-write
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Attendance;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\PupilGroupTeachers;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Recounts and saves the attendance summaries of one learner.
 *
 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-the-summary-follows-every-attendance-write
 */
class AttendanceSummaryService {

	private const REGISTER = 'learniq';
	private const RECORD_SCHEMA = 'attendance-record';
	private const SESSION_SCHEMA = 'session';
	public const SUMMARY_SCHEMA = 'attendance-summary';

	/**
	 * Upper bound on one learner's counted records (absences and late
	 * arrivals across every year they were at school).
	 */
	private const MAX_RECORDS = 5000;

	/**
	 * Lessons read per query.
	 */
	private const SESSION_CHUNK = 200;

	/**
	 * Namespace of the stable summary uuid.
	 */
	private const UUID_SEED = 'learniq.attendance-summary';

	/**
	 * Lesson dates already read, by uuid; null when the lesson is unknown.
	 *
	 * @var array<string, array{date: string, minutes: int|null}|null>
	 */
	private array $sessionCache = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectService               $objectService OpenRegister object access.
	 * @param AttendanceSummaryCalculator $calculator    The counting rules.
	 * @param PupilGroupTeachers          $groupTeachers The teachers of a pupil's groups (read audience).
	 * @param LearnerRefResolver          $profiles      The learner's profile uuid.
	 * @param ITimeFactory                $time          Clock for updatedAt.
	 * @param LoggerInterface             $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly AttendanceSummaryCalculator $calculator,
		private readonly PupilGroupTeachers $groupTeachers,
		private readonly LearnerRefResolver $profiles,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Recount every school year of a learner and save what changed.
	 *
	 * The years written are those with counted records, those already stored
	 * (so a year whose last absence was removed drops to zero), and the years
	 * the caller names (so a pupil marked present gets a row of zeros).
	 *
	 * @param string            $learnerId   Nextcloud user id of the learner.
	 * @param array<int,string> $schoolYears School years to write even without counted records.
	 * @param string            $tenantId    Tenant to use when no record or row names one.
	 * @param string|null       $learnerRef  Profile uuid to use when no record or row names one.
	 *
	 * @return int The number of rows saved.
	 *
	 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-the-summary-follows-every-attendance-write
	 */
	public function recompute(string $learnerId, array $schoolYears=[], string $tenantId='', ?string $learnerRef=null): int {
		if ($learnerId === '') {
			return 0;
		}

		$records = $this->countedRecords(learnerId: $learnerId);
		$sessions = $this->sessionDays(ids: array_map(static fn (array $r): string => (string)($r['sessionId'] ?? ''), $records));
		$counts = $this->calculator->summarise(records: $records, sessions: $sessions);
		$existing = $this->storedRows(learnerId: $learnerId);

		$asked = array_filter($schoolYears, [AttendanceSummaryCalculator::class, 'isSchoolYear']);
		$years = array_unique(array_merge(array_keys($counts), array_keys($existing), $asked));
		sort($years);

		$tenantId = ($this->firstText(rows: $records, field: 'tenant_id') ?? $tenantId);
		$learnerRef = ($this->firstText(rows: $records, field: 'learnerRef') ?? $learnerRef);
		$teachers = $this->teachersOf(learnerId: $learnerId);

		$saved = 0;
		foreach ($years as $year) {
			$row = ($existing[$year] ?? null);
			$data = array_merge(
				[
					'learnerId' => $learnerId,
					'learnerRef' => ($learnerRef ?? $this->text(value: ($row['learnerRef'] ?? null)) ?? $this->resolveRef(learnerId: $learnerId)),
					'schoolYear' => $year,
				],
				($counts[$year] ?? $this->calculator->emptyCounts()),
				[
					'teacherIds' => ($teachers ?? $this->storedTeachers(row: $row)),
					'tenant_id' => ($this->text(value: ($row['tenant_id'] ?? null)) ?? $tenantId),
				]
			);

			if ($data['tenant_id'] === '' || ($row !== null && $this->calculator->unchanged(row: $row, data: $data) === true)) {
				continue;
			}

			$this->save(row: $row, data: $data);
			$saved++;
		}//end foreach

		return $saved;
	}//end recompute()

	/**
	 * The school years the given lessons fall in.
	 *
	 * @param array<int,string> $sessionIds Session uuids.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-the-summary-follows-every-attendance-write
	 */
	public function schoolYearsOf(array $sessionIds): array {
		$years = [];
		foreach ($this->sessionDays(ids: $sessionIds) as $session) {
			$year = $this->calculator->schoolYearOf(date: $session['date']);
			if ($year !== null) {
				$years[$year] = true;
			}
		}

		$years = array_keys($years);
		sort($years);

		return $years;
	}//end schoolYearsOf()

	/**
	 * The date and length of each known lesson, read in chunks and cached.
	 *
	 * @param array<int,string> $ids Session uuids.
	 *
	 * @return array<string, array{date: string, minutes: int|null}>
	 */
	private function sessionDays(array $ids): array {
		$ids = array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
		$missing = array_values(array_filter($ids, fn (string $id): bool => array_key_exists($id, $this->sessionCache) === false));

		foreach (array_chunk($missing, self::SESSION_CHUNK) as $chunk) {
			$rows = $this->objectService->findAll(
				config: [
					'ids' => $chunk,
					'filters' => ['register' => self::REGISTER, 'schema' => self::SESSION_SCHEMA],
					'limit' => count($chunk),
				],
				_rbac: false,
				_multitenancy: false
			);
			$found = $this->calculator->sessionDays(rows: array_map(fn ($row): array => $this->toRow(object: $row), $rows));
			foreach ($chunk as $id) {
				$this->sessionCache[$id] = ($found[$id] ?? null);
			}
		}

		$days = [];
		foreach ($ids as $id) {
			if (($this->sessionCache[$id] ?? null) !== null) {
				$days[$id] = $this->sessionCache[$id];
			}
		}

		return $days;
	}//end sessionDays()

	/**
	 * The learner's absences and late arrivals.
	 *
	 * @param string $learnerId Nextcloud user id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function countedRecords(string $learnerId): array {
		$rows = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::RECORD_SCHEMA,
					'learnerId' => $learnerId,
					// A list is an IN filter.
					'status' => AttendanceSummaryCalculator::COUNTED_STATUSES,
				],
				'limit' => self::MAX_RECORDS,
			],
			_rbac: false,
			_multitenancy: false
		);

		$records = [];
		foreach ($rows as $row) {
			$record = $this->toRow(object: $row);
			// Never let a widened answer count another pupil's records.
			if (($record['learnerId'] ?? null) === $learnerId) {
				$records[] = $record;
			}
		}

		return $records;
	}//end countedRecords()

	/**
	 * The learner's stored summaries, keyed by school year.
	 *
	 * @param string $learnerId Nextcloud user id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function storedRows(string $learnerId): array {
		$rows = $this->objectService->findAll(
			config: [
				'filters' => ['register' => self::REGISTER, 'schema' => self::SUMMARY_SCHEMA, 'learnerId' => $learnerId],
				'limit' => 100,
			],
			_rbac: false,
			_multitenancy: false
		);

		$byYear = [];
		foreach ($rows as $row) {
			$summary = $this->toRow(object: $row);
			$year = $this->text(value: ($summary['schoolYear'] ?? null));
			if (($summary['learnerId'] ?? null) === $learnerId && $year !== null) {
				$byYear[$year] = $summary;
			}
		}

		return $byYear;
	}//end storedRows()

	/**
	 * Save a recounted row: an update of the stored row, or a new row with a
	 * uuid derived from the learner and the year, so two racing recounts
	 * write one row.
	 *
	 * @param array<string, mixed>|null $row  The stored row, or null.
	 * @param array<string, mixed>      $data The recounted row.
	 *
	 * @return void
	 */
	private function save(?array $row, array $data): void {
		$data['updatedAt'] = (new DateTimeImmutable('@' . $this->time->getTime()))->format(DateTimeInterface::ATOM);

		$uuid = self::stableUuid(learnerId: (string)$data['learnerId'], schoolYear: (string)$data['schoolYear']);
		$object = $data;
		if ($row !== null) {
			$uuid = (string)($row['id'] ?? ($row['uuid'] ?? ($row['@self']['id'] ?? $uuid)));
			// The row as read carries OpenRegister's @self block; saving it back
			// is not ours to do.
			$object = array_merge($row, $data);
			unset($object['@self'], $object['id'], $object['uuid']);
		}

		$this->objectService->saveObject(
			object: $object,
			register: self::REGISTER,
			schema: self::SUMMARY_SCHEMA,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false,
			_unowned: true
		);
	}//end save()

	/**
	 * The uuid a learner's summary of one school year is created with.
	 *
	 * @param string $learnerId  Nextcloud user id.
	 * @param string $schoolYear The school year.
	 *
	 * @return string A version 5 style uuid.
	 *
	 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-the-summary-follows-every-attendance-write
	 */
	public static function stableUuid(string $learnerId, string $schoolYear): string {
		$hash = sha1(self::UUID_SEED . '|' . $learnerId . '|' . $schoolYear);

		return sprintf(
			'%s-%s-5%s-%s%s-%s',
			substr($hash, 0, 8),
			substr($hash, 8, 4),
			substr($hash, 13, 3),
			dechex((hexdec($hash[16]) & 0x3) | 0x8),
			substr($hash, 17, 3),
			substr($hash, 20, 12)
		);
	}//end stableUuid()

	/**
	 * The teachers of the pupil's groups, or null when they cannot be read.
	 *
	 * @param string $learnerId Nextcloud user id.
	 *
	 * @return array<int,string>|null
	 */
	private function teachersOf(string $learnerId): ?array {
		try {
			return array_values($this->groupTeachers->forLearner(learnerId: $learnerId));
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[AttendanceSummaryService] Could not read the group teachers of {learner}, keeping the stored ones: {msg}',
				['learner' => $learnerId, 'msg' => $exception->getMessage()]
			);
			return null;
		}
	}//end teachersOf()

	/**
	 * The teachers stored on a row; none for a new row, which never widens who reads it.
	 *
	 * @param array<string, mixed>|null $row The stored row.
	 *
	 * @return array<int,string>
	 */
	private function storedTeachers(?array $row): array {
		if ($row === null) {
			return [];
		}

		return array_values(array_filter((array)($row['teacherIds'] ?? []), static fn ($id): bool => is_string($id) === true && $id !== ''));
	}//end storedTeachers()

	/**
	 * The learner's profile uuid, or null.
	 *
	 * @param string $learnerId Nextcloud user id.
	 *
	 * @return string|null
	 */
	private function resolveRef(string $learnerId): ?string {
		try {
			return $this->profiles->resolveAcrossTenants(learnerId: $learnerId);
		} catch (Throwable) {
			return null;
		}
	}//end resolveRef()

	/**
	 * The first non-empty text value of a field across rows.
	 *
	 * @param array<int, array<string, mixed>> $rows  Rows.
	 * @param string                           $field Field name.
	 *
	 * @return string|null
	 */
	private function firstText(array $rows, string $field): ?string {
		foreach ($rows as $row) {
			$value = $this->text(value: ($row[$field] ?? null));
			if ($value !== null) {
				return $value;
			}
		}

		return null;
	}//end firstText()

	/**
	 * A non-empty string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private function text(mixed $value): ?string {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end text()

	/**
	 * An OpenRegister result as an array.
	 *
	 * @param mixed $object An entity or an array.
	 *
	 * @return array<string, mixed>
	 */
	private function toRow(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			return (array)$object->jsonSerialize();
		}

		return [];
	}//end toRow()
}//end class
