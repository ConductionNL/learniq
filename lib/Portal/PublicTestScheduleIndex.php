<?php

/**
 * Learniq PublicTestScheduleIndex
 *
 * The test schedule part of a portal's public index (portal-public-index):
 * the sittings of every exam period the school marked public, each with its
 * day, start and end, the subject, the room, and the department and year of
 * the classes that sit it. A period still in planning, a period that is over
 * and a cancelled sitting stay out. A sitting never carries the names of
 * pupils or invigilators: only the fields below leave this class.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
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
 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-the-public-index-carries-a-schools-published-test-schedule
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use DateTimeImmutable;
use Throwable;

/**
 * Builds the test schedule items of the public index.
 *
 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-the-public-index-carries-a-schools-published-test-schedule
 */
class PublicTestScheduleIndex {

	/**
	 * Dutch month names, as the rest of the index writes them.
	 */
	private const MONTHS = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];

	/**
	 * Dutch weekday abbreviations, Sunday first (PHP `w`).
	 */
	private const WEEKDAYS = ['Zo', 'Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za'];

	/**
	 * Rows read once per index, by schema and id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $byId = [];

	/**
	 * Constructor.
	 *
	 * @param PublicIndexReads $reads The shared reads and words.
	 */
	public function __construct(
		private readonly PublicIndexReads $reads,
	) {
	}//end __construct()

	/**
	 * The test schedule items.
	 *
	 * @param string $namespace The uuid namespace, '' for all.
	 * @param string $today     Today, `YYYY-MM-DD`.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-the-public-index-carries-a-schools-published-test-schedule
	 */
	public function items(string $namespace, string $today): array {
		$periods = [];
		foreach ($this->reads->rows(schema: 'exam-period', namespace: $namespace) as $period) {
			if (self::isPublicAndToCome(period: $period, today: $today) === true) {
				$periods[$this->reads->idOf(row: $period)] = $period;
			}
		}

		if ($periods === []) {
			return [];
		}

		$out = [];
		foreach ($this->reads->rows(schema: 'exam-sitting', namespace: $namespace) as $sitting) {
			$period = ($periods[(string)($sitting['examPeriodId'] ?? '')] ?? null);
			if ($period === null || ($sitting['lifecycle'] ?? null) === 'cancelled') {
				continue;
			}

			$item = $this->item(sitting: $sitting, period: $period, namespace: $namespace);
			if ($item !== null) {
				$out[] = $item;
			}
		}

		usort($out, static fn (array $a, array $b): int => [$a['date'], $a['startTime']] <=> [$b['date'], $b['startTime']]);

		return $out;
	}//end items()

	/**
	 * Whether the school marked a period public, it is no longer in planning
	 * and its last day has not passed.
	 *
	 * @param array<string, mixed> $period The exam period.
	 * @param string               $today  Today.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-the-public-index-carries-a-schools-published-test-schedule
	 */
	public static function isPublicAndToCome(array $period, string $today): bool {
		$last = (string)($period['endsOn'] ?? ($period['startsOn'] ?? ''));

		return ($period['public'] ?? false) === true
			&& in_array(($period['lifecycle'] ?? 'planning'), ['published', 'closed'], true)
			&& $last !== ''
			&& substr($last, 0, 10) >= $today;
	}//end isPublicAndToCome()

	/**
	 * "Toetsweek 1, 9 - 13 november", or "30 november - 4 december" across a month.
	 *
	 * @param array<string, mixed> $period The exam period.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-the-public-index-carries-a-schools-published-test-schedule
	 */
	public static function periodLabel(array $period): string {
		$name  = trim((string)($period['name'] ?? ''));
		$first = substr((string)($period['startsOn'] ?? ''), 0, 10);
		$last  = substr((string)($period['endsOn'] ?? ''), 0, 10);
		if ($first === '') {
			return $name;
		}

		if ($last === '' || $last === $first) {
			$range = self::dayAndMonth(day: $first);
		} else if (substr($first, 0, 7) === substr($last, 0, 7)) {
			$range = ((int)substr($first, 8, 2)) . ' - ' . self::dayAndMonth(day: $last);
		} else {
			$range = self::dayAndMonth(day: $first) . ' - ' . self::dayAndMonth(day: $last);
		}

		if ($name === '') {
			return $range;
		}

		return $name . ', ' . $range;
	}//end periodLabel()

	/**
	 * "Di 10 nov, 08.30 - 10.10, Wiskunde A".
	 *
	 * @param string $startsAt The sitting's start, ISO-8601 as stored.
	 * @param string $endsAt   The sitting's end, ISO-8601 as stored.
	 * @param string $subject  The subject name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-the-public-index-carries-a-schools-published-test-schedule
	 */
	public static function line(string $startsAt, string $endsAt, string $subject): string {
		$day   = substr($startsAt, 0, 10);
		$parts = [self::weekday(day: $day) . ' ' . ((int)substr($day, 8, 2)) . ' ' . mb_substr((self::MONTHS[((int)substr($day, 5, 2)) - 1] ?? ''), 0, 3)];

		$start = self::clock(moment: $startsAt);
		$end   = self::clock(moment: $endsAt);
		if ($start !== '' && $end !== '') {
			$parts[] = $start . ' - ' . $end;
		} else if ($start !== '') {
			$parts[] = $start;
		}

		if ($subject !== '') {
			$parts[] = $subject;
		}

		return implode(', ', $parts);
	}//end line()

	/**
	 * One test schedule item, or null when the sitting has no day.
	 *
	 * @param array<string, mixed> $sitting   The sitting.
	 * @param array<string, mixed> $period    Its public period.
	 * @param string               $namespace The uuid namespace.
	 *
	 * @return array<string, mixed>|null
	 */
	private function item(array $sitting, array $period, string $namespace): ?array {
		$startsAt = (string)($sitting['startsAt'] ?? '');
		if (strlen($startsAt) < 10) {
			return null;
		}

		$endsAt     = (string)($sitting['endsAt'] ?? '');
		$assessment = $this->row(schema: 'exam', id: (string)($sitting['assessmentId'] ?? ''), namespace: $namespace);
		$course     = $this->row(schema: 'course', id: (string)($assessment['courseId'] ?? ''), namespace: $namespace);
		$subject    = trim((string)($course['name'] ?? ($course['title'] ?? '')));
		$periodName = self::periodLabel(period: $period);
		$groups     = $this->groupsOf(cohortIds: (array)($sitting['cohortIds'] ?? []), namespace: $namespace);

		$item = [
			'id'        => 'test:' . $this->reads->idOf(row: $sitting),
			'type'      => 'test',
			'kind'      => $this->reads->word(text: 'Exam schedule'),
			'title'     => $subject,
			'date'      => substr($startsAt, 0, 10),
			'startTime' => self::clock(moment: $startsAt),
			'endTime'   => self::clock(moment: $endsAt),
			'line'      => self::line(startsAt: $startsAt, endsAt: $endsAt, subject: $subject),
			'periodId'  => $this->reads->idOf(row: $period),
			'period'    => $periodName,
			'groups'    => $groups,
			'facets'    => [
				$this->reads->word(text: 'Department and year') => array_values(array_unique(array_column($groups, 'label'))),
				$this->reads->word(text: 'Test week')           => [$periodName],
			],
		];

		$room = $this->roomsOf(roomIds: (array)($sitting['roomIds'] ?? []), namespace: $namespace);
		if ($room !== '') {
			$item['room'] = $room;
		}

		$bring = trim((string)($period['bringList'] ?? ''));
		if ($bring !== '') {
			$item['bring'] = $bring;
		}

		$weight = $this->weightOf(assessment: $assessment, namespace: $namespace);
		if ($weight !== null) {
			$item['weight'] = $weight;
		}

		return $item;
	}//end item()

	/**
	 * The department and year of each class: "4 havo" from the class's
	 * programme and its year in it. A class without a programme names none.
	 *
	 * @param array<int, mixed> $cohortIds The sitting's classes.
	 * @param string            $namespace The uuid namespace.
	 *
	 * @return array<int, array{department: string, year: int|null, label: string}>
	 */
	private function groupsOf(array $cohortIds, string $namespace): array {
		$groups = [];
		foreach ($cohortIds as $cohortId) {
			$cohort    = $this->row(schema: 'cohort', id: (string)$cohortId, namespace: $namespace);
			$programme = $this->row(schema: 'programme', id: (string)($cohort['programmeId'] ?? ''), namespace: $namespace);
			$name      = mb_strtolower(trim((string)($programme['name'] ?? '')));
			if ($name === '') {
				continue;
			}

			$year  = null;
			$label = $name;
			if (is_numeric($cohort['programmeYear'] ?? null) === true) {
				$year  = (int)$cohort['programmeYear'];
				$label = $year . ' ' . $name;
			}

			$groups[$label] = ['department' => $name, 'year' => $year, 'label' => $label];
		}

		return array_values($groups);
	}//end groupsOf()

	/**
	 * The room names, joined.
	 *
	 * @param array<int, mixed> $roomIds   The sitting's rooms.
	 * @param string            $namespace The uuid namespace.
	 *
	 * @return string
	 */
	private function roomsOf(array $roomIds, string $namespace): string {
		$names = [];
		foreach ($roomIds as $roomId) {
			$name = trim((string)($this->row(schema: 'room', id: (string)$roomId, namespace: $namespace)['name'] ?? ''));
			if ($name !== '') {
				$names[] = $name;
			}
		}

		return implode(', ', $names);
	}//end roomsOf()

	/**
	 * The weight of the assessment's component in its plan, when exactly one
	 * published plan of its course carries that component.
	 *
	 * @param array<string, mixed> $assessment The assessment.
	 * @param string               $namespace  The uuid namespace.
	 *
	 * @return float|int|null
	 */
	private function weightOf(array $assessment, string $namespace): float|int|null {
		$component = (string)($assessment['curriculumPlanComponentId'] ?? '');
		$courseId  = (string)($assessment['courseId'] ?? '');
		if ($component === '' || $courseId === '') {
			return null;
		}

		$weights = [];
		foreach ($this->all(schema: 'curriculum-plan', namespace: $namespace) as $plan) {
			if (in_array($courseId, (array)($plan['requiredCourseIds'] ?? []), true) === false) {
				continue;
			}

			foreach ((array)($plan['components'] ?? []) as $entry) {
				if (is_array($entry) === true && ($entry['componentId'] ?? null) === $component && is_numeric($entry['weight'] ?? null) === true) {
					$weights[] = $entry['weight'] + 0;
				}
			}
		}

		if (count($weights) !== 1) {
			return null;
		}

		return $weights[0];
	}//end weightOf()

	/**
	 * One row of a schema by id, or [] when there is none.
	 *
	 * @param string $schema    The schema slug.
	 * @param string $id        The row id.
	 * @param string $namespace The uuid namespace.
	 *
	 * @return array<string, mixed>
	 */
	private function row(string $schema, string $id, string $namespace): array {
		if ($id === '') {
			return [];
		}

		return ($this->all(schema: $schema, namespace: $namespace)[$id] ?? []);
	}//end row()

	/**
	 * Every row of a schema by id, read once.
	 *
	 * @param string $schema    The schema slug.
	 * @param string $namespace The uuid namespace.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function all(string $schema, string $namespace): array {
		if (isset($this->byId[$schema]) === false) {
			$this->byId[$schema] = [];
			foreach ($this->reads->rows(schema: $schema, namespace: $namespace) as $row) {
				$this->byId[$schema][$this->reads->idOf(row: $row)] = $row;
			}
		}

		return $this->byId[$schema];
	}//end all()

	/**
	 * "08.30" from a moment as stored, in the school's own time; '' without a time.
	 *
	 * @param string $moment ISO-8601.
	 *
	 * @return string
	 */
	private static function clock(string $moment): string {
		if (strlen($moment) < 16 || $moment[10] !== 'T') {
			return '';
		}

		return str_replace(':', '.', substr($moment, 11, 5));
	}//end clock()

	/**
	 * "Di" for a day.
	 *
	 * @param string $day `YYYY-MM-DD`.
	 *
	 * @return string
	 */
	private static function weekday(string $day): string {
		try {
			return self::WEEKDAYS[(int)(new DateTimeImmutable($day))->format('w')];
		} catch (Throwable) {
			return '';
		}
	}//end weekday()

	/**
	 * "13 november".
	 *
	 * @param string $day `YYYY-MM-DD`.
	 *
	 * @return string
	 */
	private static function dayAndMonth(string $day): string {
		return ((int)substr($day, 8, 2)) . ' ' . (self::MONTHS[((int)substr($day, 5, 2)) - 1] ?? '');
	}//end dayAndMonth()
}//end class
