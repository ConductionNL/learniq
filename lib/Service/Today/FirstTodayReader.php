<?php

/**
 * Learniq First Today Reader
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The OpenRegister reads behind the First today card. The groups and their
 * registers go through the roll-call's own reads, so a teacher reads only the
 * groups she teaches (RollCallAccess) and the head every current group. Every
 * other read runs with the caller's own rights, so the card counts only rows
 * the caller may read.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Today
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-each-role-tile-counts-what-that-role-acts-on-and-opens-the-list-it-counts
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Today;

use DateTimeZone;
use OCA\Learniq\Service\Attendance\RollCallAccess;
use OCA\Learniq\Service\Attendance\RollCallException;
use OCA\Learniq\Service\Attendance\RollCallReader;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;

/**
 * Reads the rows the First today rules count.
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-each-role-tile-counts-what-that-role-acts-on-and-opens-the-list-it-counts
 */
class FirstTodayReader {

	/**
	 * The register every read targets.
	 *
	 * @var string
	 */
	private const REGISTER = 'learniq';

	/**
	 * The most rows one read asks for.
	 *
	 * @var int
	 */
	private const MAX_ROWS = 500;

	/**
	 * Constructor.
	 *
	 * @param ObjectService  $objectService OpenRegister object access.
	 * @param RollCallAccess $access        Who may open which group, the time zone and today.
	 * @param RollCallReader $reader        The groups, their lessons, marks and absence reports.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly RollCallAccess $access,
		private readonly RollCallReader $reader,
	) {
	}//end __construct()

	/**
	 * The caller's time zone.
	 *
	 * @param IUser $user The caller.
	 *
	 * @return DateTimeZone
	 *
	 * @spec openspec/changes/today-first-per-school-role/tasks.md#T1
	 */
	public function zone(IUser $user): DateTimeZone {
		return $this->access->zone(user: $user);
	}//end zone()

	/**
	 * Today's groups with their lessons, saved marks per lesson and absence reports.
	 *
	 * With `$own` only the groups the caller teaches; otherwise every group
	 * she may open (all current groups for school-wide staff). A caller who
	 * may open no register gets no groups.
	 *
	 * @param IUser        $user  The caller.
	 * @param bool         $own   Only the groups she teaches.
	 * @param string       $today Today, `Y-m-d`.
	 * @param DateTimeZone $zone  The school's time zone.
	 *
	 * @return list<array<string, mixed>> Each `{cohort, sessions, records, reports}`.
	 *
	 * @spec openspec/changes/today-first-per-school-role/tasks.md#T1
	 */
	public function groupsToday(IUser $user, bool $own, string $today, DateTimeZone $zone): array {
		try {
			$cohorts = $this->access->cohortsFor(user: $user);
		} catch (RollCallException) {
			return [];
		}

		$uid = $user->getUID();
		$groups = [];
		foreach ($cohorts as $cohort) {
			if ($own === true && in_array($uid, $this->reader->teachersOf(cohort: $cohort), true) === false) {
				continue;
			}

			$cohortId = (string)($cohort['id'] ?? ($cohort['uuid'] ?? ''));
			$sessions = $this->reader->sessionsOn(cohortId: $cohortId, date: $today, zone: $zone);
			$records = [];
			foreach ($sessions as $session) {
				$sessionId = (string)($session['id'] ?? ($session['uuid'] ?? ''));
				$records[$sessionId] = count($this->reader->recordsOf(sessionId: $sessionId));
			}

			$learnerIds = array_values(array_filter((array)($cohort['learnerIds'] ?? []), static fn (mixed $id): bool => is_string($id) === true && $id !== ''));
			$groups[] = [
				'cohort' => $cohort,
				'sessions' => $sessions,
				'records' => $records,
				'reports' => count($this->reader->reportsOn(learnerIds: $learnerIds, date: $today)),
			];
		}//end foreach

		return $groups;
	}//end groupsToday()

	/**
	 * Rows of a schema, read with the caller's own rights.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Field filters.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-each-role-tile-counts-what-that-role-acts-on-and-opens-the-list-it-counts
	 */
	public function rows(string $schema, array $filters=[]): array {
		$found = $this->objectService->findAll(
			config: [
				'filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters),
				'limit' => self::MAX_ROWS,
			]
		);

		$rows = [];
		foreach ($found as $object) {
			$row = $object;
			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$row = $object->jsonSerialize();
			}

			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rows()
}//end class
