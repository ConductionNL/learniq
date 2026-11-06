<?php

/**
 * Learniq Bulk Enrol Service
 *
 * Enrols every learner of a group in one course, on the server. Learners with
 * an open enrolment for the course are skipped. Each new enrolment is created
 * as the caller with source `bulk`, so OpenRegister's create permission and
 * the prerequisite gate still decide per learner; a refusal is reported by
 * learner, not by aborting the rest.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Enrolment
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
 * @spec openspec/specs/enrolment/spec.md#requirement-bulk-enrolment-via-cohort-role-department-or-csv
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Enrolment;

use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Enrol a group in a course.
 *
 * @spec openspec/specs/enrolment/spec.md#requirement-bulk-enrolment-via-cohort-role-department-or-csv
 */
class BulkEnrolService {

	private const REGISTER = 'learniq';

	/**
	 * Enrolment states that no longer hold a place.
	 */
	private const CLOSED = ['withdrawn', 'failed', 'completed'];

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
	 * Enrol the learners of a group in a course.
	 *
	 * @param array<string, mixed> $course The course, as the caller reads it.
	 * @param array<string, mixed> $cohort The group, as the caller reads it.
	 *
	 * @return array{enrolled: int, skipped: int, refused: array<int, string>}
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-bulk-enrolment-via-cohort-role-department-or-csv
	 */
	public function enrol(array $course, array $cohort): array {
		$outcome = ['enrolled' => 0, 'skipped' => 0, 'refused' => []];
		$learnerIds = array_filter(
			(array)($cohort['learnerIds'] ?? []),
			static fn (mixed $id): bool => is_string($id) === true && $id !== ''
		);
		$learnerIds = array_values(array_unique($learnerIds));

		foreach ($learnerIds as $learnerId) {
			if ($this->hasOpenEnrolment(courseId: (string)$course['id'], learnerId: $learnerId) === true) {
				$outcome['skipped']++;
				continue;
			}

			try {
				$this->objectService->saveObject(
					object: [
						'learnerId' => $learnerId,
						'courseId' => (string)$course['id'],
						'cohortId' => (string)$cohort['id'],
						'source' => 'bulk',
						'tenant_id' => (string)($course['tenant_id'] ?? ''),
					],
					register: self::REGISTER,
					schema: 'enrolment'
				);
				$outcome['enrolled']++;
			} catch (Throwable $exception) {
				$outcome['refused'][] = $learnerId;
			}
		}//end foreach

		return $outcome;
	}//end enrol()

	/**
	 * Whether the learner already holds an open enrolment for the course.
	 *
	 * @param string $courseId  The course uuid.
	 * @param string $learnerId The learner's Nextcloud user id.
	 *
	 * @return bool
	 */
	private function hasOpenEnrolment(string $courseId, string $learnerId): bool {
		$rows = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => 'enrolment',
					'courseId' => $courseId,
					'learnerId' => $learnerId,
				],
			],
			_rbac: false
		);

		foreach ($rows as $row) {
			$data = $row;
			if (is_array($row) === false) {
				$data = $row->jsonSerialize();
			}

			if (in_array(($data['lifecycle'] ?? 'pending'), self::CLOSED, true) === false) {
				return true;
			}
		}

		return false;
	}//end hasOpenEnrolment()
}//end class
