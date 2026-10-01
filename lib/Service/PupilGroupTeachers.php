<?php

/**
 * Learniq Pupil Group Teachers
 *
 * Answers one question: which teachers teach this pupil's group? A pupil is
 * in a group when the Cohort lists the pupil's Nextcloud user id in
 * `learnerIds`; the group's teachers are its `teacherIds` plus every
 * `teacherAssignments[].teacherId` (a duo-partner may be listed there only).
 * A completed or archived group is last year's: its teachers no longer teach
 * the pupil, so they are left out.
 *
 * ExcuseRequest uses the answer as its read audience: OpenRegister's `match`
 * can only compare a field on the object itself with the caller, so the
 * teachers are stamped onto the report (`teacherIds`) when it is written.
 *
 * Runs without a session (a portal report, a repair step), so the read passes
 * `_rbac: false` and `_multitenancy: false`. A failed read throws: the caller
 * decides whether that keeps a stored value or stamps nobody.
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
 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#requirement-an-absence-report-is-read-by-the-teachers-of-the-pupils-group-and-by-school-wide-staff
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Resolves the teachers of the groups a pupil is in.
 *
 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#requirement-an-absence-report-is-read-by-the-teachers-of-the-pupils-group-and-by-school-wide-staff
 */
class PupilGroupTeachers {

	private const LEARNIQ_REGISTER = 'learniq';
	private const COHORT_SCHEMA = 'cohort';

	/**
	 * Group lifecycles whose teachers no longer teach the pupil.
	 */
	private const PAST_LIFECYCLES = ['completed', 'archived'];

	/**
	 * A pupil sits in a handful of groups at most; this bounds the read.
	 */
	private const MAX_GROUPS = 100;

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
	 * The distinct Nextcloud user ids of the teachers of the pupil's current
	 * groups, in the order the groups list them. Empty for an empty id or a
	 * pupil in no current group.
	 *
	 * @param string $learnerId Nextcloud user id of the pupil.
	 *
	 * @return array<int, string>
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#requirement-an-absence-report-is-read-by-the-teachers-of-the-pupils-group-and-by-school-wide-staff
	 */
	public function forLearner(string $learnerId): array {
		if ($learnerId === '') {
			return [];
		}

		// `learnerIds` is an array property: OpenRegister answers a scalar
		// filter on it with "the array contains this value".
		$cohorts = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::COHORT_SCHEMA,
					'learnerIds' => $learnerId,
				],
				'limit' => self::MAX_GROUPS,
			],
			_rbac: false,
			_multitenancy: false
		);

		$teachers = [];
		foreach ($cohorts as $cohort) {
			$row = $this->toRow(object: $cohort);
			if ($this->isCurrent(row: $row, learnerId: $learnerId) === false) {
				continue;
			}

			foreach ($this->teachersOf(row: $row) as $teacher) {
				$teachers[$teacher] = true;
			}
		}

		return array_map('strval', array_keys($teachers));
	}//end forLearner()

	/**
	 * Whether a group is current and really lists the pupil. The filter
	 * already asked for the pupil; checking again keeps a widened answer from
	 * widening the audience.
	 *
	 * @param array<string, mixed> $row The Cohort.
	 * @param string $learnerId Nextcloud user id of the pupil.
	 *
	 * @return bool
	 */
	private function isCurrent(array $row, string $learnerId): bool {
		if (in_array(($row['lifecycle'] ?? null), self::PAST_LIFECYCLES, true) === true) {
			return false;
		}

		return in_array($learnerId, (array)($row['learnerIds'] ?? []), true);
	}//end isCurrent()

	/**
	 * The teacher ids a group names, from `teacherIds` and `teacherAssignments`.
	 *
	 * @param array<string, mixed> $row The Cohort.
	 *
	 * @return array<int, string>
	 */
	private function teachersOf(array $row): array {
		$ids = (array)($row['teacherIds'] ?? []);
		foreach ((array)($row['teacherAssignments'] ?? []) as $assignment) {
			if (is_array($assignment) === true) {
				$ids[] = ($assignment['teacherId'] ?? null);
			}
		}

		return array_values(array_filter($ids, static fn ($id): bool => is_string($id) === true && $id !== ''));
	}//end teachersOf()

	/**
	 * Normalise an ObjectService result (array or entity) to a plain array.
	 *
	 * @param mixed $object The result row.
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
