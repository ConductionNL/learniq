<?php

/**
 * Learniq Report Seen Stamp
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Records a teacher's first look at an absence report: the first time a
 * teacher named in the report's `teacherIds` opens the register that lists
 * it, the report gets `seenBy` and `seenAt`. Later openings, by the same or
 * another teacher, change nothing, and seeing never changes the lifecycle.
 * A parent reads the stamp as "Gezien door juf Esra om 8.12 uur".
 *
 * @category Service
 * @package  OCA\Learniq\Service\Attendance
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/absence-reports-seen-and-ended/specs/attendance/spec.md#requirement-a-teachers-first-look-at-an-absence-report-is-recorded
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Attendance;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Stamps `seenBy` / `seenAt` on the open absence reports a group teacher opens.
 *
 * @spec openspec/changes/absence-reports-seen-and-ended/specs/attendance/spec.md#requirement-a-teachers-first-look-at-an-absence-report-is-recorded
 */
class ReportSeenStamp {

	private const REGISTER = 'learniq';
	private const SCHEMA = 'excuse-request';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param ITimeFactory  $time          The clock.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Stamp the reports this teacher sees for the first time, and answer with
	 * the reports as they now stand.
	 *
	 * @param array<string, array<string, mixed>> $reports The reports the register lists, keyed by pupil.
	 * @param string                              $userId  The teacher opening the register.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/absence-reports-seen-and-ended/specs/attendance/spec.md#scenario-juf-esra-opens-the-register
	 */
	public function seen(array $reports, string $userId): array {
		foreach ($reports as $learnerId => $report) {
			if ($this->firstLook(report: $report, userId: $userId) === false) {
				continue;
			}

			$reports[$learnerId] = $this->stamp(report: $report, userId: $userId);
		}

		return $reports;
	}//end seen()

	/**
	 * Whether this is the first look at a report still waiting for a
	 * decision, by one of its own group teachers.
	 *
	 * @param array<string, mixed> $report The report.
	 * @param string               $userId The teacher.
	 *
	 * @return bool
	 */
	private function firstLook(array $report, string $userId): bool {
		$teacherIds = $report['teacherIds'] ?? [];

		return $userId !== ''
			&& ($report['lifecycle'] ?? null) === 'submitted'
			&& in_array(($report['seenAt'] ?? null), [null, ''], true) === true
			&& is_array($teacherIds) === true
			&& in_array($userId, $teacherIds, true) === true
			&& $this->idOf(report: $report) !== '';
	}//end firstLook()

	/**
	 * Write the stamp on one report.
	 *
	 * @param array<string, mixed> $report The report.
	 * @param string               $userId The teacher.
	 *
	 * @return array<string, mixed> The report with the stamp.
	 */
	private function stamp(array $report, string $userId): array {
		$stamped = array_merge(
			$report,
			[
				'seenBy' => $userId,
				'seenAt' => (new DateTimeImmutable('@' . $this->time->getTime()))->format(DATE_ATOM),
			]
		);
		$object = $stamped;
		unset($object['@self'], $object['id'], $object['uuid']);
		$this->objectService->saveObject(
			object: $object,
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: $this->idOf(report: $report),
			_rbac: false
		);

		return $stamped;
	}//end stamp()

	/**
	 * A report's id.
	 *
	 * @param array<string, mixed> $report The report.
	 *
	 * @return string
	 */
	private function idOf(array $report): string {
		$id = ($report['id'] ?? ($report['uuid'] ?? ''));

		return is_string($id) === true ? $id : '';
	}//end idOf()
}//end class
