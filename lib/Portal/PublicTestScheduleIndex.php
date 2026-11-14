<?php

/**
 * Learniq PublicTestScheduleIndex
 *
 * The test schedule part of a portal's public index (portal-public-index): the
 * sittings of every test week the school published and marked public, each
 * with its day, start and end, subject, room, and the department and year of
 * its classes. A sitting never carries the name of a pupil or an invigilator:
 * only the subject, the time, the room and which classes sit it.
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

/**
 * Builds the test schedule items of the public index.
 *
 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-the-public-index-carries-a-schools-published-test-schedule
 */
class PublicTestScheduleIndex {

	/**
	 * The states of a test week whose schedule the school published.
	 */
	private const PUBLISHED = ['published', 'closed'];

	/**
	 * Short Dutch weekday names, Monday first, as the website block writes a day.
	 */
	private const WEEKDAYS = ['Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za', 'Zo'];

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
	 * The test schedule items: every sitting of a public, published test week
	 * that is not over and not cancelled.
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
			if ($this->isPublicAndToCome(period: $period, today: $today) === true) {
				$periods[$this->reads->idOf(row: $period)] = $period;
			}
		}

		if ($periods === []) {
			return [];
		}

		$lookups = [
			'assessments' => $this->byId(schema: 'exam', namespace: $namespace),
			'courses'     => $this->byId(schema: 'course', namespace: $namespace),
			'rooms'       => $this->byId(schema: 'room', namespace: $namespace),
			'cohorts'     => $this->byId(schema: 'cohort', namespace: $namespace),
			'programmes'  => $this->byId(schema: 'programme', namespace: $namespace),
			'plans'       => $this->reads->rows(schema: 'curriculum-plan', namespace: $namespace),
		];

		$out = [];
		foreach ($this->reads->rows(schema: 'exam-sitting', namespace: $namespace) as $sitting) {
			$period = ($periods[(string)($sitting['examPeriodId'] ?? '')] ?? null);
			if ($period === null || ($sitting['lifecycle'] ?? 'planned') === 'cancelled') {
				continue;
			}

			$item = $this->item(sitting: $sitting, period: $period, lookups: $lookups);
			if ($item !== null) {
				$out[] = $item;
			}
		}

		usort($out, static fn (array $a, array $b): int => [$a['date'], $a['startTime'], $a['title']] <=> [$b['date'], $b['startTime'], $b['title']]);

		return $out;
	}//end items()

	/**
	 * Whether the school marked a test week public, published its schedule,
	 * and the week is not over.
	 *
	 * @param array<string, mixed> $period The test week.
	 * @param string               $today  Today.
	 *
	 * @return bool
	 */
	private function isPublicAndToCome(array $period, string $today): bool {
		return ($period['public'] ?? false) === true
			&& in_array(($period['lifecycle'] ?? 'planning'), self::PUBLISHED, true) === true
			&& substr((string)($period['endsOn'] ?? ''), 0, 10) >= $today;
	}//end isPublicAndToCome()

	/**
	 * One sitting as a test schedule item, or null when it names no subject or day.
	 *
	 * @param array<string, mixed>                                    $sitting The sitting.
	 * @param array<string, mixed>                                    $period  Its test week.
	 * @param array<string, array<int|string, array<string, mixed>>> $lookups The rows it names, by id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function item(array $sitting, array $period, array $lookups): ?array {
		$assessment = ($lookups['assessments'][(string)($sitting['assessmentId'] ?? '')] ?? []);
		$subject    = $this->subjectOf(assessment: $assessment, courses: $lookups['courses']);
		$start      = (string)($sitting['startsAt'] ?? '');
		if ($subject === '' || strlen($start) < 16) {
			return null;
		}

		$end    = (string)($sitting['endsAt'] ?? '');
		$day    = substr($start, 0, 10);
		$from   = substr($start, 11, 5);
		$until  = '';
		if (strlen($end) >= 16) {
			$until = substr($end, 11, 5);
		}

		$groups = $this->groupsOf(sitting: $sitting, cohorts: $lookups['cohorts'], programmes: $lookups['programmes']);
		$week   = $this->periodLabel(period: $period);

		$item = [
			'id'        => 'test:' . $this->reads->idOf(row: $sitting),
			'type'      => 'test',
			'kind'      => $this->reads->word(text: 'Test schedule'),
			'title'     => $subject,
			'date'      => $day,
			'startTime' => $from,
			'endTime'   => $until,
			'meta'      => $this->meta(day: $day, from: $from, until: $until, subject: $subject),
			'periodId'  => $this->reads->idOf(row: $period),
			'period'    => $week,
			'groups'    => $groups,
			'facets'    => [
				$this->reads->word(text: 'Department and year') => $groups,
				$this->reads->word(text: 'Test week')           => [$week],
			],
		];

		$rooms = $this->roomsOf(sitting: $sitting, rooms: $lookups['rooms']);
		if ($rooms !== '') {
			$item['room'] = $rooms;
		}

		$bring = trim((string)($period['bringList'] ?? ''));
		if ($bring !== '') {
			$item['bringList'] = $bring;
		}

		$weight = $this->weightOf(assessment: $assessment, plans: $lookups['plans']);
		if ($weight !== null) {
			$item['weight'] = $weight;
		}

		return $item;
	}//end item()

	/**
	 * The subject a paper is in: the readable name of the assessment's course.
	 *
	 * @param array<string, mixed>                     $assessment The assessment.
	 * @param array<int|string, array<string, mixed>> $courses    The courses by id.
	 *
	 * @return string The subject, or '' when the paper names no known course.
	 */
	private function subjectOf(array $assessment, array $courses): string {
		$course = ($courses[(string)($assessment['courseId'] ?? '')] ?? []);
		$name   = trim((string)($course['name_nl'] ?? ''));
		if ($name === '') {
			$name = trim((string)($course['name'] ?? ''));
		}

		return $name;
	}//end subjectOf()

	/**
	 * The department and year of each class that sits the paper, "4 havo": the
	 * class's programme and year, else the class's own name.
	 *
	 * @param array<string, mixed>                     $sitting    The sitting.
	 * @param array<int|string, array<string, mixed>> $cohorts    The classes by id.
	 * @param array<int|string, array<string, mixed>> $programmes The programmes by id.
	 *
	 * @return array<int, string> The distinct labels, in the sitting's order.
	 */
	private function groupsOf(array $sitting, array $cohorts, array $programmes): array {
		$labels = [];
		foreach ((array)($sitting['cohortIds'] ?? []) as $cohortId) {
			$cohort = ($cohorts[(string)$cohortId] ?? null);
			if ($cohort === null) {
				continue;
			}

			$department = mb_strtolower(trim((string)(($programmes[(string)($cohort['programmeId'] ?? '')] ?? [])['name'] ?? '')));
			$year       = (int)($cohort['programmeYear'] ?? 0);
			$label      = trim((string)($cohort['name'] ?? ''));
			if ($department !== '' && $year > 0) {
				$label = $year . ' ' . $department;
			}

			if ($label !== '') {
				$labels[] = $label;
			}
		}

		return array_values(array_unique($labels));
	}//end groupsOf()

	/**
	 * The rooms of a sitting, by name.
	 *
	 * @param array<string, mixed>                     $sitting The sitting.
	 * @param array<int|string, array<string, mixed>> $rooms   The rooms by id.
	 *
	 * @return string The names, comma-separated, or ''.
	 */
	private function roomsOf(array $sitting, array $rooms): string {
		$names = [];
		foreach ((array)($sitting['roomIds'] ?? []) as $roomId) {
			$name = trim((string)(($rooms[(string)$roomId] ?? [])['name'] ?? ''));
			if ($name !== '') {
				$names[] = $name;
			}
		}

		return implode(', ', array_unique($names));
	}//end roomsOf()

	/**
	 * The paper's weight in the plan it counts in, when exactly one plan of its
	 * course names that component with a weight.
	 *
	 * @param array<string, mixed>             $assessment The assessment.
	 * @param array<int, array<string, mixed>> $plans      The curriculum plans.
	 *
	 * @return float|int|null The weight, or null when it is unknown or ambiguous.
	 */
	private function weightOf(array $assessment, array $plans): float|int|null {
		$course    = (string)($assessment['courseId'] ?? '');
		$component = (string)($assessment['curriculumPlanComponentId'] ?? '');
		if ($course === '' || $component === '') {
			return null;
		}

		$weights = [];
		foreach ($plans as $plan) {
			if (in_array($course, (array)($plan['requiredCourseIds'] ?? []), true) === false) {
				continue;
			}

			foreach ((array)($plan['components'] ?? []) as $part) {
				if (is_array($part) === true && ($part['componentId'] ?? null) === $component && is_numeric($part['weight'] ?? null) === true) {
					$weights[] = ($part['weight'] + 0);
				}
			}
		}

		$distinct = array_values(array_unique($weights));
		if (count($distinct) !== 1) {
			return null;
		}

		return $distinct[0];
	}//end weightOf()

	/**
	 * The line the website block shows: "Di 10 nov, 08.30 - 10.10, Wiskunde A".
	 *
	 * @param string $day     The day, `YYYY-MM-DD`.
	 * @param string $from    The start, `HH:MM`.
	 * @param string $until   The end, `HH:MM`, or ''.
	 * @param string $subject The subject.
	 *
	 * @return string
	 */
	private function meta(string $day, string $from, string $until, string $subject): string {
		$weekday = self::WEEKDAYS[((int)date('N', (int)strtotime($day))) - 1];
		$time    = str_replace(':', '.', $from);
		if ($until !== '') {
			$time .= ' - ' . str_replace(':', '.', $until);
		}

		return $weekday . ' ' . $this->reads->shortDay(day: $day) . ', ' . $time . ', ' . $subject;
	}//end meta()

	/**
	 * A test week as the website names it: "Toetsweek 1, 9 - 13 november".
	 *
	 * @param array<string, mixed> $period The test week.
	 *
	 * @return string
	 */
	private function periodLabel(array $period): string {
		$name  = trim((string)($period['name'] ?? ''));
		$first = substr((string)($period['startsOn'] ?? ''), 0, 10);
		$last  = substr((string)($period['endsOn'] ?? ''), 0, 10);
		if ($first === '' || $last === '') {
			return $name;
		}

		$from = $this->reads->dayAndMonth(day: $first);
		$to   = $this->reads->dayAndMonth(day: $last);
		if (substr($first, 0, 7) === substr($last, 0, 7)) {
			$from = (string)((int)substr($first, 8, 2));
		}

		return $name . ', ' . $from . ' - ' . $to;
	}//end periodLabel()

	/**
	 * The rows of a schema by id.
	 *
	 * @param string $schema    The schema slug.
	 * @param string $namespace The uuid namespace, '' for all.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function byId(string $schema, string $namespace): array {
		$rows = [];
		foreach ($this->reads->rows(schema: $schema, namespace: $namespace) as $row) {
			$rows[$this->reads->idOf(row: $row)] = $row;
		}

		return $rows;
	}//end byId()
}//end class
