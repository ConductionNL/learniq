<?php

/**
 * Learniq Commercial Course Check
 *
 * Tells whether a course is commercial: a corporate course, or one a learner
 * pays a compulsory fee for. Non-commercial (NC) material is not allowed there.
 *
 * @category Service
 * @package  OCA\Learniq\Service\OpenMaterial
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
 * @spec openspec/specs/open-learning-material/spec.md#scenario-non-commercial-material-is-flagged-in-a-paid-course
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\OpenMaterial;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Corporate or paid: the courses NC material may not be used in.
 *
 * @spec openspec/specs/open-learning-material/spec.md#scenario-non-commercial-material-is-flagged-in-a-paid-course
 */
class CommercialCourseCheck {

	/**
	 * Fee kinds that charge for taking the course itself.
	 */
	private const COURSE_FEE_KINDS = ['course-enrolment', 'mbo-contractonderwijs'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads the course's fee items.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Whether the course is corporate or charges a compulsory fee.
	 *
	 * @param array<string, mixed> $course The serialised course.
	 *
	 * @return bool True for a commercial course.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#scenario-non-commercial-material-is-flagged-in-a-paid-course
	 */
	public function isCommercial(array $course): bool {
		if (($course['level'] ?? null) === 'corporate') {
			return true;
		}

		foreach ($this->feeItems(courseId: (string)($course['id'] ?? '')) as $fee) {
			if ($this->chargesForTheCourse(fee: $fee) === true) {
				return true;
			}
		}

		return false;
	}//end isCommercial()

	/**
	 * The fee items linked to one course.
	 *
	 * @param string $courseId The course UUID.
	 *
	 * @return array<int, array<string, mixed>> The serialised fee items.
	 */
	private function feeItems(string $courseId): array {
		if ($courseId === '') {
			return [];
		}

		$rows = $this->objectService->findAll(
			['filters' => ['register' => AppIdentity::APP_ID, 'schema' => 'fee-item', 'linkedCourseId' => $courseId], 'limit' => 50],
			_rbac: false
		);

		return array_map(fn (mixed $row): array => $this->rowOf(row: $row), $rows);
	}//end feeItems()

	/**
	 * A row as an array, whether OpenRegister returned an entity or an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed> The serialised row.
	 */
	private function rowOf(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return (array)$row;
	}//end rowOf()

	/**
	 * Whether one fee item is a compulsory charge for taking the course.
	 *
	 * @param array<string, mixed> $fee The serialised fee item.
	 *
	 * @return bool True when a learner must pay for the course.
	 */
	private function chargesForTheCourse(array $fee): bool {
		return in_array(($fee['kind'] ?? null), self::COURSE_FEE_KINDS, true) === true
			&& (float)($fee['amount'] ?? 0) > 0
			&& ($fee['voluntary'] ?? false) !== true;
	}//end chargesForTheCourse()
}//end class
