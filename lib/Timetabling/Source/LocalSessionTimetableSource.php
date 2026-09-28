<?php

/**
 * Learniq Local Session Timetable Source
 *
 * Learniq's own `Session` schema as a timetable source: the fallback for a
 * school without planninq, and the home of sessions a teacher creates by hand.
 * Reads go through OpenRegister's ObjectService, so RBAC and multitenancy
 * scope them (ADR-022). Sessions are fetched per cohort with an equality
 * filter, so no other cohort's session is ever loaded. The window is applied
 * by the caller ({@see \OCA\Learniq\Service\TimetableProjector}), because the
 * same rows also back the same-day changes list, whatever their start time.
 *
 * @category Timetabling
 * @package  OCA\Learniq\Timetabling\Source
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
 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-a-resolver-picks-planninq-when-it-is-installed-req-001
 */

declare(strict_types=1);

namespace OCA\Learniq\Timetabling\Source;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Reads timetable sessions from learniq's own Session schema.
 *
 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-a-resolver-picks-planninq-when-it-is-installed-req-001
 */
class LocalSessionTimetableSource implements TimetableSource {

	public const NAME = 'learniq';

	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object query service (RBAC-scoped).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The source's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-a-resolver-picks-planninq-when-it-is-installed-req-001
	 */
	public function name(): string {
		return self::NAME;
	}//end name()

	/**
	 * Every Session of the given cohorts. The window is left to the caller.
	 *
	 * @param array<int,string> $cohortIds Cohort UUIDs.
	 * @param string|null       $from      Unused: the caller windows the rows.
	 * @param string|null       $to        Unused: the caller windows the rows.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-both-timetable-pages-read-through-the-adapter-req-005
	 */
	public function sessionsForCohorts(array $cohortIds, ?string $from, ?string $to): array {
		unset($from, $to);

		$rows = [];
		foreach (array_unique($cohortIds) as $cohortId) {
			if ($cohortId === '') {
				continue;
			}

			$results = $this->objectService->findAll(
				[
					'filters' => [
						'register' => self::LEARNIQ_REGISTER,
						'schema' => 'session',
						'cohortId' => $cohortId,
					],
					'sort' => ['startsAt' => 'ASC'],
				]
			);

			foreach ($results as $row) {
				$data = $this->toArray(row: $row);
				// Defensive: never let a mismatched cohort through.
				if ((string)($data['cohortId'] ?? '') !== $cohortId) {
					continue;
				}

				$data['source'] = self::NAME;
				$rows[] = $data;
			}
		}//end foreach

		return $rows;
	}//end sessionsForCohorts()

	/**
	 * A learniq Session has no teacher of its own: its teachers come through
	 * its cohort, which the caller already reads. So this is always empty.
	 *
	 * @param string      $userId Unused.
	 * @param string|null $from   Unused.
	 * @param string|null $to     Unused.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-both-timetable-pages-read-through-the-adapter-req-005
	 */
	public function sessionsForTeacher(string $userId, ?string $from, ?string $to): array {
		unset($userId, $from, $to);
		return [];
	}//end sessionsForTeacher()

	/**
	 * Normalise an ObjectService row (entity or array) to a plain array.
	 *
	 * @param mixed $row The row returned by ObjectService::findAll.
	 *
	 * @return array<string,mixed>
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end toArray()
}//end class
