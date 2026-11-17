<?php

/**
 * Learniq Pupil List Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The staff list of the pupils of one group: every pupil with today's state,
 * absence and lateness this school year, the conference time, care, a
 * signal, the grade average and the placement, plus how many pupils each
 * saved view holds. A member of staff reads the list of a group they may open
 * (a teacher of the group, or school-wide staff), the same rule as the group's
 * register and the pupil's page.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Pupil
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupil-list-shows-what-the-school-kind-acts-on-and-its-saved-views-count
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Pupil;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Service\Attendance\RollCallAccess;
use OCA\Learniq\Service\Attendance\RollCallException;
use OCA\Learniq\Service\Attendance\TodayState;
use OCA\Learniq\Service\Attendance\TodayStateService;
use OCA\Learniq\Service\Calendar\SchoolYear as SchoolCalendarYear;
use OCA\Learniq\Support\SchoolYear;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;

/**
 * Reads and builds a group's pupil list.
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupil-list-shows-what-the-school-kind-acts-on-and-its-saved-views-count
 */
class PupilListService {

	/**
	 * The schemas read per pupil for a row.
	 *
	 * @var array<int, string>
	 */
	private const RECORD_SCHEMAS = ['conference-slot', 'learning-plan', 'support-request', 'attendance-flag', 'grade-entry', 'bpv-placement'];

	/**
	 * Constructor.
	 *
	 * @param RollCallAccess    $access      Who may open which group.
	 * @param TodayStateService $todayStates Today's state per pupil of a group.
	 * @param PupilPageReader   $reader      The pupils' records.
	 * @param ITimeFactory      $time        Clock.
	 * @param SchoolCalendarYear $schoolYear The school's school year, which says the day it starts.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RollCallAccess $access,
		private readonly TodayStateService $todayStates,
		private readonly PupilPageReader $reader,
		private readonly ITimeFactory $time,
		private readonly SchoolCalendarYear $schoolYear=new SchoolCalendarYear(),
	) {
	}//end __construct()

	/**
	 * The list of a group's pupils.
	 *
	 * The query holds `cohortId` (the group; null or '' for the caller's first
	 * group), `view` (a saved view, PupilList::VIEWS, narrowing the rows; '' or
	 * absent for all) and `sort` (PupilList::SORT_SIGNAL_FIRST, else by name).
	 *
	 * @param IUser                $user  The caller.
	 * @param array<string, mixed> $query The group, view and sort asked for.
	 *
	 * @return array<string, mixed> cohortId, cohortName, schoolYear, results, total and views (the count per saved view).
	 *
	 * @throws RollCallException 403 for a caller who may not open the group.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T1
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T2
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#scenario-groep-7-on-monday-morning
	 */
	public function forCohort(IUser $user, array $query=[]): array {
		$view = (string)($query['view'] ?? '');
		$cohortId = $query['cohortId'] ?? null;
		if (is_string($cohortId) === false) {
			$cohortId = null;
		}

		$cohort = $this->access->chosenCohort(cohorts: $this->access->cohortsFor(user: $user), cohortId: $cohortId);
		$learnerIds = array_values(
			array_filter((array)($cohort['learnerIds'] ?? []), static fn (mixed $id): bool => is_string($id) === true && $id !== '')
		);

		$zone = $this->access->zone(user: $user);
		$today = $this->access->today(zone: $zone);
		$now = (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone($zone);
		$schoolYear = (SchoolYear::of(date: $today, start: $this->schoolYear->start()) ?? '');

		$rows = [];
		if ($learnerIds !== []) {
			$moment = ['today' => $today, 'schoolYear' => $schoolYear, 'now' => $now, 'zone' => $zone];
			$rows = $this->rows(group: ['cohort' => $cohort, 'learnerIds' => $learnerIds], moment: $moment);
		}

		$inView = array_values(array_filter($rows, static fn (array $row): bool => PupilList::inView(row: $row, view: $view)));

		return [
			'cohortId' => $this->reader->idOf(row: $cohort),
			'cohortName' => (string)($cohort['name'] ?? ''),
			'schoolYear' => $schoolYear,
			'results' => PupilList::ordered(rows: $inView, sort: (string)($query['sort'] ?? '')),
			'total' => count($inView),
			'views' => PupilList::viewCounts(rows: $rows),
		];
	}//end forCohort()

	/**
	 * One row per pupil of the group.
	 *
	 * The moment holds `today` (`Y-m-d`), `schoolYear`, `now` (a
	 * DateTimeImmutable) and `zone` (the school's DateTimeZone).
	 *
	 * @param array{cohort: array<string, mixed>, learnerIds: list<string>} $group  The group and its pupils.
	 * @param array<string, mixed>                                          $moment Today, this school year, now and the school's zone.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(array $group, array $moment): array {
		$cohort = $group['cohort'];
		$learnerIds = $group['learnerIds'];
		$states = $this->todayStates->forCohort(cohort: $cohort, date: $moment['today'], zone: $moment['zone']);
		$profiles = $this->reader->ofPupils(schema: 'learner-profile', learnerIds: $learnerIds, field: 'ncUserId');
		$summaries = $this->reader->ofPupils(schema: 'attendance-summary', learnerIds: $learnerIds);

		$records = [];
		foreach (self::RECORD_SCHEMAS as $schema) {
			foreach ($this->reader->ofPupils(schema: $schema, learnerIds: $learnerIds) as $learnerId => $rows) {
				$records[$learnerId][$schema] = $rows;
			}
		}

		$result = [];
		foreach ($learnerIds as $learnerId) {
			$result[] = PupilList::row(
				pupil: [
					'learnerId' => $learnerId,
					'profile' => ($profiles[$learnerId][0] ?? null),
					'todayState' => ($states[$learnerId] ?? TodayState::NOT_REGISTERED),
					'summary' => self::summaryOf(summaries: ($summaries[$learnerId] ?? []), schoolYear: $moment['schoolYear']),
					'records' => ($records[$learnerId] ?? []),
				],
				now: $moment['now']
			);
		}

		return $result;
	}//end rows()

	/**
	 * The summary of a school year among a pupil's summaries, or null.
	 *
	 * @param list<array<string, mixed>> $summaries  The pupil's attendance summaries.
	 * @param string                     $schoolYear The school year.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function summaryOf(array $summaries, string $schoolYear): ?array {
		foreach ($summaries as $summary) {
			if ((string)($summary['schoolYear'] ?? '') === $schoolYear) {
				return $summary;
			}
		}

		return null;
	}//end summaryOf()
}//end class
