<?php

/**
 * Learniq PublicCourseIndex
 *
 * The courses part of a portal's public index (portal-public-index): one item
 * per offered course with a run still to come, with the first run's first and
 * last day, its number of days, the first days of the other runs, the place
 * and the start months.
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
 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-school-offers-its-portal-an-index-of-its-public-courses-programmes-and-school-days
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * Builds the course items of the public index.
 *
 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-school-offers-its-portal-an-index-of-its-public-courses-programmes-and-school-days
 */
class PublicCourseIndex {

	/**
	 * The most other dates a course card names.
	 */
	private const MAX_OTHER_RUNS = 3;

	/**
	 * Cohort lifecycles that run no more lessons.
	 */
	private const ENDED = ['completed', 'archived'];

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
	 * The course items.
	 *
	 * @param string $namespace The uuid namespace, '' for all.
	 * @param string $today     Today, `YYYY-MM-DD`.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-school-offers-its-portal-an-index-of-its-public-courses-programmes-and-school-days
	 */
	public function items(string $namespace, string $today): array {
		$offered = [];
		foreach ($this->reads->rows(schema: 'course', namespace: $namespace) as $course) {
			if ($this->isOffered(course: $course) === true) {
				$offered[$this->reads->idOf(row: $course)] = $course;
			}
		}

		if ($offered === []) {
			return [];
		}

		$runs = $this->runsOf(courseIds: array_keys($offered), namespace: $namespace, today: $today);
		$out  = [];
		foreach ($offered as $id => $course) {
			if (isset($runs[$id]) === true) {
				$out[] = $this->item(course: $course, runs: $runs[$id]);
			}
		}

		return $out;
	}//end items()

	/**
	 * Whether a course is offered to the public: published, and a training
	 * course (`level: corporate`) or one a learner may sign up for herself
	 * (`selfEnrolment`). The school-year courses of po, vo and mbo are taught
	 * to groups; their groups are not runs anyone can join.
	 *
	 * @param array<string, mixed> $course The course.
	 *
	 * @return bool
	 */
	private function isOffered(array $course): bool {
		if (($course['lifecycle'] ?? null) !== 'published') {
			return false;
		}

		return ($course['level'] ?? null) === 'corporate' || ($course['selfEnrolment'] ?? null) === true;
	}//end isOffered()

	/**
	 * The runs still to come of the courses, by course id, first run first:
	 * `{days, place}` per cohort with a lesson day to come.
	 *
	 * @param array<int, int|string> $courseIds The offered courses.
	 * @param string                 $namespace The uuid namespace.
	 * @param string                 $today     Today.
	 *
	 * @return array<string, array<int, array{days: array<int, string>, place: string}>>
	 */
	private function runsOf(array $courseIds, string $namespace, string $today): array {
		$places = [];
		foreach ($this->reads->rows(schema: 'vestiging', namespace: $namespace) as $place) {
			$places[$this->reads->idOf(row: $place)] = (string)($place['name'] ?? '');
		}

		$wanted = array_flip(array_map('strval', $courseIds));
		$runs   = [];
		foreach ($this->reads->rows(schema: 'cohort', namespace: $namespace) as $cohort) {
			$courseId = (string)($cohort['courseId'] ?? '');
			if (isset($wanted[$courseId]) === false || in_array(($cohort['lifecycle'] ?? null), self::ENDED, true) === true) {
				continue;
			}

			$days = $this->daysOf(cohortId: $this->reads->idOf(row: $cohort), today: $today);
			if ($days !== []) {
				$runs[$courseId][] = ['days' => $days, 'place' => ($places[(string)($cohort['locationId'] ?? '')] ?? '')];
			}
		}

		foreach (array_keys($runs) as $courseId) {
			usort($runs[$courseId], static fn (array $a, array $b): int => $a['days'][0] <=> $b['days'][0]);
		}

		return $runs;
	}//end runsOf()

	/**
	 * The days still to come of one cohort's lessons, in order; a cancelled
	 * lesson is no day.
	 *
	 * @param string $cohortId The cohort.
	 * @param string $today    Today.
	 *
	 * @return array<int, string>
	 */
	private function daysOf(string $cohortId, string $today): array {
		$days = [];
		foreach ($this->reads->rows(schema: 'session', namespace: '', filters: ['cohortId' => $cohortId]) as $session) {
			$day  = substr((string)($session['startsAt'] ?? ''), 0, 10);
			$live = ((string)($session['cohortId'] ?? '') === $cohortId && ($session['lifecycle'] ?? null) !== 'cancelled');
			if ($live === true && $day !== '' && $day >= $today) {
				$days[$day] = true;
			}
		}

		$list = array_keys($days);
		sort($list);

		return $list;
	}//end daysOf()

	/**
	 * One course card.
	 *
	 * @param array<string, mixed>             $course The course.
	 * @param array<int, array<string, mixed>> $runs   Its runs, first first.
	 *
	 * @return array<string, mixed>
	 */
	private function item(array $course, array $runs): array {
		$first = $runs[0];
		$count = count($first['days']);
		$title = trim((string)($course['name_nl'] ?? ''));
		if ($title === '') {
			$title = trim((string)($course['name'] ?? ''));
		}

		$item = [
			'id'      => 'course:' . $this->reads->idOf(row: $course),
			'type'    => 'course',
			'kind'    => $this->reads->word(text: 'Course'),
			'title'   => $title,
			'summary' => (string)($course['description'] ?? ''),
			'date'    => $first['days'][0],
			'endDate' => $first['days'][$count - 1],
			'meta'    => $this->meta(count: $count, runs: $runs),
			'facets'  => [$this->reads->word(text: 'Start in') => $this->distinct(runs: $runs, part: 'month')],
		];
		$places = $this->distinct(runs: $runs, part: 'place');
		if ($places !== []) {
			$item['facets'][$this->reads->word(text: 'Venue')] = $places;
		}

		return $item;
	}//end item()

	/**
	 * "1 dag", "ook op 22 okt, 5 nov".
	 *
	 * @param int                              $count The first run's number of days.
	 * @param array<int, array<string, mixed>> $runs  The runs.
	 *
	 * @return array<int, string>
	 */
	private function meta(int $count, array $runs): array {
		$meta = [$this->reads->word(text: '%s days', args: [$count])];
		if ($count === 1) {
			$meta = [$this->reads->word(text: '1 day')];
		}

		$other = [];
		foreach (array_slice($runs, 1, self::MAX_OTHER_RUNS) as $run) {
			$other[] = $this->reads->shortDay(day: $run['days'][0]);
		}

		if ($other !== []) {
			$meta[] = $this->reads->word(text: 'also on %s', args: [implode(', ', $other)]);
		}

		return $meta;
	}//end meta()

	/**
	 * The distinct start months or places of the runs, in run order.
	 *
	 * @param array<int, array<string, mixed>> $runs The runs.
	 * @param string                           $part `month` or `place`.
	 *
	 * @return array<int, string>
	 */
	private function distinct(array $runs, string $part): array {
		$out = [];
		foreach ($runs as $run) {
			$value = (string)$run['place'];
			if ($part === 'month') {
				$value = $this->reads->month(day: $run['days'][0]);
			}

			if ($value !== '' && in_array($value, $out, true) === false) {
				$out[] = $value;
			}
		}

		return $out;
	}//end distinct()
}//end class
