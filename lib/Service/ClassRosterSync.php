<?php

/**
 * Learniq Class Roster Sync
 *
 * Keeps a Cohort and its enrolments in step with one class record from the
 * student administration system (the `class-roster` import kind integriq
 * hands over):
 *
 * - the cohort is upserted on `externalId` and `externalSource`, never on its
 *   name, and its name, school year, programme year, `learnerIds` and
 *   `teacherIds` become what the source sent;
 * - a pupil who joins gets an active enrolment, with source `roster-sync`, for
 *   every course the cohort runs;
 * - a pupil who leaves keeps their history: their open enrolments for the
 *   cohort move to `withdrawn` with a reason, and nothing is deleted;
 * - `syncedAt` and the number of membership changes are stamped on the cohort.
 *
 * Members are named by Nextcloud user id or by ECK iD and stored by Nextcloud
 * user id. Runs inside integriq's background job, so every read and write is
 * system-level. A refusal names a code and field names, never a value.
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
 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/specs/enrolment/spec.md#requirement-classes-from-the-student-administration-land-as-cohorts
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use RuntimeException;

/**
 * Lands one class from the student administration system.
 *
 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/specs/enrolment/spec.md#requirement-classes-from-the-student-administration-land-as-cohorts
 */
class ClassRosterSync {

	/**
	 * The source stamped on an enrolment the sync makes.
	 */
	public const ENROLMENT_SOURCE = 'roster-sync';

	/**
	 * The reason a leaver's enrolments are withdrawn with.
	 */
	public const LEAVER_REASON = 'Left the class in the student administration';

	/**
	 * The cohort fields the source owns.
	 */
	public const SOURCE_FIELDS = ['name', 'academicYear', 'programmeYear', 'learnerIds', 'teacherIds'];

	private const REGISTER = 'learniq';

	/**
	 * Enrolment states that are still open, and so withdrawn when a pupil leaves.
	 */
	private const OPEN = ['pending', 'active'];

	/**
	 * Enrolment states that no longer count as the cohort running the course for a pupil.
	 */
	private const CLOSED = ['withdrawn', 'failed'];

	/**
	 * How many rows one read may return: a class's enrolments, all courses.
	 */
	private const READ_LIMIT = 5000;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param ITimeFactory  $time          The sync time.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Land one class record.
	 *
	 * @param array<string, mixed> $data The record in learniq's field names, with `tenant_id`.
	 *
	 * @return array{code: string, fields: array<int, string>}|null The refusal, or null when taken.
	 *
	 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/specs/enrolment/spec.md#requirement-classes-from-the-student-administration-land-as-cohorts
	 */
	public function sync(array $data): ?array {
		$missing = $this->missing(data: $data);
		if ($missing !== []) {
			return ['code' => 'ROSTER-MISSING-FIELD', 'fields' => $missing];
		}

		$learnerIds = $this->resolveLearners(members: $data['learnerIds'] ?? []);
		if ($learnerIds === null) {
			return ['code' => 'ROSTER-UNKNOWN-PUPIL', 'fields' => ['learnerIds']];
		}

		$existing = $this->first(
			schema: 'cohort',
			filters: ['externalId' => (string)$data['externalId'], 'externalSource' => (string)$data['externalSource']]
		);
		$before = $this->strings(value: $existing['learnerIds'] ?? []);
		$joiners = array_values(array_diff($learnerIds, $before));
		$leavers = array_values(array_diff($before, $learnerIds));

		$cohort = $this->saveCohort(
			existing: $existing,
			data: $data,
			learnerIds: $learnerIds,
			changes: (count($joiners) + count($leavers))
		);
		$cohortId = $this->idOf(row: $cohort);
		if ($cohortId === '') {
			throw new RuntimeException('The cohort was saved without an id.');
		}

		$this->withdrawLeavers(cohortId: $cohortId, leavers: $leavers);
		$this->enrolJoiners(cohort: $cohort, cohortId: $cohortId, joiners: $joiners, tenantId: (string)$data['tenant_id']);

		return null;
	}//end sync()

	/**
	 * The required fields that are missing or empty, and `learnerIds` or `teacherIds` when not a list.
	 *
	 * @param array<string, mixed> $data The record.
	 *
	 * @return array<int, string>
	 */
	private function missing(array $data): array {
		$missing = [];
		foreach (['externalId', 'externalSource', 'name', 'academicYear'] as $field) {
			$value = ($data[$field] ?? null);
			if (is_scalar($value) === false || trim((string)$value) === '') {
				$missing[] = $field;
			}
		}

		foreach (['learnerIds', 'teacherIds'] as $field) {
			if (array_key_exists($field, $data) === true && is_array($data[$field]) === false) {
				$missing[] = $field;
			}
		}

		return $missing;
	}//end missing()

	/**
	 * The Nextcloud user ids of the members, each named by user id or ECK iD; null when one is unknown.
	 *
	 * @param mixed $members The members as sent.
	 *
	 * @return array<int, string>|null
	 */
	private function resolveLearners(mixed $members): ?array {
		$resolved = [];
		foreach ($this->strings(value: $members) as $member) {
			$profile = $this->first(schema: 'learner-profile', filters: ['ncUserId' => $member]);
			if ($profile === null) {
				$profile = $this->first(schema: 'learner-profile', filters: ['eckId' => $member]);
			}

			$userId = (string)($profile['ncUserId'] ?? '');
			if ($userId === '') {
				return null;
			}

			$resolved[] = $userId;
		}

		return array_values(array_unique($resolved));
	}//end resolveLearners()

	/**
	 * Upsert the cohort with the source's fields, the sync time and the number of changes.
	 *
	 * @param array<string, mixed>|null $existing   The stored cohort, or null for a new class.
	 * @param array<string, mixed>      $data       The record.
	 * @param array<int, string>        $learnerIds The members by Nextcloud user id.
	 * @param int                       $changes    Joiners plus leavers.
	 *
	 * @return array<string, mixed> The cohort as saved.
	 */
	private function saveCohort(?array $existing, array $data, array $learnerIds, int $changes): array {
		$row = ($existing ?? ['lifecycle' => 'planned', 'period' => (string)$data['academicYear']]);
		$row['externalId'] = (string)$data['externalId'];
		$row['externalSource'] = (string)$data['externalSource'];
		$row['name'] = (string)$data['name'];
		$row['academicYear'] = (string)$data['academicYear'];
		$row['learnerIds'] = $learnerIds;
		$row['teacherIds'] = $this->strings(value: $data['teacherIds'] ?? []);
		if (is_numeric($data['programmeYear'] ?? null) === true) {
			$row['programmeYear'] = (int)$data['programmeYear'];
		}

		if (is_string($data['period'] ?? null) === true && trim($data['period']) !== '') {
			$row['period'] = $data['period'];
		}

		$row['tenant_id'] = (string)($row['tenant_id'] ?? $data['tenant_id']);
		$row['syncedAt'] = $this->time->getDateTime()->format(DATE_ATOM);
		$row['syncChanges'] = $changes;

		return $this->save(schema: 'cohort', row: $row);
	}//end saveCohort()

	/**
	 * Withdraw the open enrolments a leaver has for the cohort, with the reason.
	 *
	 * @param string             $cohortId The cohort id.
	 * @param array<int, string> $leavers  The leavers by Nextcloud user id.
	 *
	 * @return void
	 */
	private function withdrawLeavers(string $cohortId, array $leavers): void {
		foreach ($leavers as $learnerId) {
			foreach ($this->all(schema: 'enrolment', filters: ['cohortId' => $cohortId, 'learnerId' => $learnerId]) as $enrolment) {
				if (in_array((string)($enrolment['lifecycle'] ?? 'pending'), self::OPEN, true) === false) {
					continue;
				}

				$enrolment['lifecycle'] = 'withdrawn';
				$enrolment['reason'] = self::LEAVER_REASON;
				$this->save(schema: 'enrolment', row: $enrolment);
			}
		}
	}//end withdrawLeavers()

	/**
	 * Give each joiner an active enrolment for every course the cohort runs, unless they hold an open one.
	 *
	 * @param array<string, mixed> $cohort   The cohort as saved.
	 * @param string               $cohortId The cohort id.
	 * @param array<int, string>   $joiners  The joiners by Nextcloud user id.
	 * @param string               $tenantId The record's tenant.
	 *
	 * @return void
	 */
	private function enrolJoiners(array $cohort, string $cohortId, array $joiners, string $tenantId): void {
		if ($joiners === []) {
			return;
		}

		$enrolments = $this->all(schema: 'enrolment', filters: ['cohortId' => $cohortId]);
		foreach ($joiners as $learnerId) {
			foreach ($this->courses(cohort: $cohort, enrolments: $enrolments) as $courseId) {
				if ($this->holdsOpen(enrolments: $enrolments, learnerId: $learnerId, courseId: $courseId) === true) {
					continue;
				}

				$this->save(
					schema: 'enrolment',
					row: [
						'learnerId' => $learnerId,
						'courseId'  => $courseId,
						'cohortId'  => $cohortId,
						'source'    => self::ENROLMENT_SOURCE,
						'lifecycle' => 'active',
						'tenant_id' => (string)($cohort['tenant_id'] ?? $tenantId),
					]
				);
			}
		}
	}//end enrolJoiners()

	/**
	 * The courses the cohort runs: its own course and those of its enrolments that still count.
	 *
	 * @param array<string, mixed>             $cohort     The cohort.
	 * @param array<int, array<string, mixed>> $enrolments The cohort's enrolments.
	 *
	 * @return array<int, string>
	 */
	private function courses(array $cohort, array $enrolments): array {
		$courses = [];
		if (is_string($cohort['courseId'] ?? null) === true && $cohort['courseId'] !== '') {
			$courses[] = $cohort['courseId'];
		}

		foreach ($enrolments as $enrolment) {
			$courseId = (string)($enrolment['courseId'] ?? '');
			if ($courseId !== '' && in_array((string)($enrolment['lifecycle'] ?? 'pending'), self::CLOSED, true) === false) {
				$courses[] = $courseId;
			}
		}

		return array_values(array_unique($courses));
	}//end courses()

	/**
	 * Whether the pupil already holds an open enrolment for the course in this cohort.
	 *
	 * @param array<int, array<string, mixed>> $enrolments The cohort's enrolments.
	 * @param string                           $learnerId  The pupil.
	 * @param string                           $courseId   The course.
	 *
	 * @return bool
	 */
	private function holdsOpen(array $enrolments, string $learnerId, string $courseId): bool {
		foreach ($enrolments as $enrolment) {
			if ((string)($enrolment['learnerId'] ?? '') === $learnerId
				&& (string)($enrolment['courseId'] ?? '') === $courseId
				&& in_array((string)($enrolment['lifecycle'] ?? 'pending'), self::OPEN, true) === true
			) {
				return true;
			}
		}

		return false;
	}//end holdsOpen()

	/**
	 * The non-empty strings of a list, in order and once each.
	 *
	 * @param mixed $value The list.
	 *
	 * @return array<int, string>
	 */
	private function strings(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$strings = [];
		foreach ($value as $item) {
			if (is_scalar($item) === true && trim((string)$item) !== '') {
				$strings[] = trim((string)$item);
			}
		}

		return array_values(array_unique($strings));
	}//end strings()

	/**
	 * The id of a row: `id`, `@self.id` or `uuid`.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		foreach ([$row['id'] ?? null, $row['@self']['id'] ?? null, $row['uuid'] ?? null] as $candidate) {
			if (is_scalar($candidate) === true && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return '';
	}//end idOf()

	/**
	 * The first row matching the filters, read without RBAC.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<string, mixed>|null
	 */
	private function first(string $schema, array $filters): ?array {
		$rows = $this->read(schema: $schema, filters: $filters, limit: 1);

		return ($rows[0] ?? null);
	}//end first()

	/**
	 * Every row matching the filters, read without RBAC.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function all(string $schema, array $filters): array {
		return $this->read(schema: $schema, filters: $filters, limit: self::READ_LIMIT);
	}//end all()

	/**
	 * Rows matching the filters as arrays, read without RBAC.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 * @param int                  $limit   The most rows to read.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function read(string $schema, array $filters, int $limit): array {
		$rows = $this->objectService->findAll(
			['filters' => array_merge($filters, ['register' => self::REGISTER, 'schema' => $schema]), 'limit' => $limit],
			false,
			false
		);

		$arrays = [];
		foreach ((array)$rows as $row) {
			$arrays[] = $this->toArray(row: $row);
		}

		return $arrays;
	}//end read()

	/**
	 * A row as an array.
	 *
	 * @param mixed $row An array or an entity.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $row): array {
		$data = [];
		if (is_array($row) === true) {
			$data = $row;
		} else if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$data = (array)$row->jsonSerialize();
		}

		// OpenRegister serves the id under `@self`; the sync keeps it as `id`
		// and never writes the metadata block back.
		$id = $this->idOf(row: $data);
		unset($data['@self']);
		if ($id !== '') {
			$data['id'] = $id;
		}

		return $data;
	}//end toArray()

	/**
	 * Write a row as the system and return it as saved.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $row    The row.
	 *
	 * @return array<string, mixed>
	 */
	private function save(string $schema, array $row): array {
		$uuid = $this->idOf(row: $row);
		if ($uuid === '') {
			$uuid = null;
		}

		unset($row['id'], $row['@self']);
		$saved = $this->objectService->saveObject(
			object: $row,
			register: self::REGISTER,
			schema: $schema,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);

		return array_merge($row, $this->toArray(row: $saved));
	}//end save()
}//end class
