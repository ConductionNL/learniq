<?php

/**
 * Repair step that back-fills ExcuseRequest.teacherIds on absence reports
 * written before the server started stamping them.
 *
 * @category Repair
 * @package  OCA\Learniq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#requirement-existing-absence-reports-get-their-group-teachers
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCA\Learniq\Kernel\Contracts\PupilGroupTeachersInterface;
use OCA\Learniq\Repair\Support\RepairRowWalk;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps on every existing ExcuseRequest what ExcuseRequestOwnerStamp stores
 * today: `teacherIds`, the teachers of the pupil's current groups. A teacher
 * reads only the reports that list them, so a report filed before the stamp
 * existed was invisible to the group teacher.
 *
 * Idempotent: a row whose stored teachers already equal the derived ones is
 * not saved, so a second run saves nothing. A failed lookup skips the row and
 * never overwrites a stored value. Runs without a session, so every read and
 * write passes `_rbac: false` and `_multitenancy: false`.
 *
 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#requirement-existing-absence-reports-get-their-group-teachers
 */
class BackfillExcuseRequestTeachers implements IRepairStep {

	private const LEARNIQ_REGISTER = 'learniq';
	private const EXCUSE_SCHEMA = 'excuse-request';

	/**
	 * Pages through the schema this step back-fills.
	 *
	 * @var RepairRowWalk
	 */
	private readonly RepairRowWalk $rows;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param PupilGroupTeachersInterface $groupTeachers The teachers of a pupil's current groups.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly PupilGroupTeachersInterface $groupTeachers,
		private readonly LoggerInterface $logger,
	) {
		$this->rows = new RepairRowWalk(objectService: $objectService, logger: $logger, label: 'BackfillExcuseRequestTeachers');
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#requirement-existing-absence-reports-get-their-group-teachers
	 */
	public function getName(): string {
		return 'Stamp the group teachers on existing absence reports so those teachers can read them';
	}//end getName()

	/**
	 * Page through every ExcuseRequest and stamp the ones whose teachers differ.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#requirement-existing-absence-reports-get-their-group-teachers
	 */
	public function run(IOutput $output): void {
		$cache = [];
		$counts = $this->rows->walk(
			schema: self::EXCUSE_SCHEMA,
			visit: function (array $row) use (&$cache): ?string {
				return $this->stampRow(row: $row, cache: $cache);
			}
		);

		$output->info(
			'BackfillExcuseRequestTeachers: ' . $counts->countOf(key: 'stamped') . ' stamped, ' . $counts->countOf(key: 'failed')
			. ' failed, of ' . $counts->countOf(key: 'scanned') . ' scanned.'
		);
	}//end run()

	/**
	 * Stamp one row when its stored teachers differ from the derived ones.
	 *
	 * @param array<string, mixed> $row The ExcuseRequest.
	 * @param array<string, array<int, string>> $cache Pupil id to teacher ids, per run.
	 *
	 * @return string|null Counter to bump, or null when the row needed nothing.
	 */
	private function stampRow(array $row, array &$cache): ?string {
		$learnerId = ($row['learnerId'] ?? null);
		$uuid = ($row['id'] ?? ($row['uuid'] ?? null));
		if (is_string($learnerId) === false || $learnerId === '' || is_string($uuid) === false || $uuid === '') {
			return null;
		}

		try {
			if (array_key_exists($learnerId, $cache) === false) {
				$cache[$learnerId] = $this->groupTeachers->forLearner(learnerId: $learnerId);
			}

			$derived = $cache[$learnerId];
			if ($this->sameSet(left: $derived, right: $this->stored(row: $row)) === true) {
				return null;
			}

			// The row as read carries OpenRegister's `@self` block. Saving it
			// back makes OpenRegister check the acting user may use the row's
			// folder, which a session-less step never may (a portal report
			// with an attachment then fails with "Access to folder denied").
			// The stored metadata stays on the object either way.
			$object = array_merge($row, ['teacherIds' => $derived]);
			unset($object['@self']);

			$this->objectService->saveObject(
				object: $object,
				register: self::LEARNIQ_REGISTER,
				schema: self::EXCUSE_SCHEMA,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[BackfillExcuseRequestTeachers] Could not stamp absence report {id}: {msg}',
				['id' => $uuid, 'msg' => $exception->getMessage()]
			);
			return 'failed';
		}//end try

		return 'stamped';
	}//end stampRow()

	/**
	 * The teacher ids the row carries now, as non-empty strings.
	 *
	 * @param array<string, mixed> $row The ExcuseRequest.
	 *
	 * @return array<int, string>
	 */
	private function stored(array $row): array {
		return array_values(
			array_filter((array)($row['teacherIds'] ?? []), static fn ($id): bool => is_string($id) === true && $id !== '')
		);
	}//end stored()

	/**
	 * Whether two id lists hold the same ids, whatever their order.
	 *
	 * @param array<int, string> $left One list.
	 * @param array<int, string> $right The other list.
	 *
	 * @return bool
	 */
	private function sameSet(array $left, array $right): bool {
		$left = array_unique($left);
		$right = array_unique($right);
		sort($left);
		sort($right);

		return $left === $right;
	}//end sameSet()
}//end class
