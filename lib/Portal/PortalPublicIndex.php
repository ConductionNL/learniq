<?php

/**
 * Learniq PortalPublicIndex
 *
 * What a school shows a visitor of its portal without signing in
 * (portal-public-index, portaliq `portal-public-catalogue`): the courses it
 * offers (training courses, or courses open to sign up) with their next dates
 * and place, its programmes, and the days on its
 * calendar for the whole school. Only published courses and programmes, only
 * course runs that are still to come and only school-wide events; never a
 * person, a group's own day or anything a learner did.
 *
 * Portaliq asks through `PortalContributionProvider::getPublicIndex()` with
 * the portal's slug. On an instance that carries several example sets, an
 * example portal shows only its own set's objects, recognised by the fixed
 * uuid namespace each example set is generated in (`scripts/example-sets`,
 * "a fixed uuid in the ee06 namespace"); any other portal shows everything.
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

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds a portal's public index of courses, programmes and school days.
 *
 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-school-offers-its-portal-an-index-of-its-public-courses-programmes-and-school-days
 */
class PortalPublicIndex {

	private const REGISTER = 'learniq';

	/**
	 * The uuid namespace each example set is generated in (scripts/example-sets).
	 */
	public const SET_NAMESPACE = [
		'po'        => 'ee01',
		'vo'        => 'ee02',
		'mbo'       => 'ee03',
		'he'        => 'ee04',
		'corporate' => 'ee05',
		'training'  => 'ee06',
	];

	/**
	 * The most rows of one schema read.
	 */
	private const LIMIT = 1000;

	/**
	 * The most other dates a course card names.
	 */
	private const MAX_OTHER_RUNS = 3;

	/**
	 * Cohort lifecycles that run no more lessons.
	 */
	private const ENDED = ['completed', 'archived'];

	/**
	 * Dutch month names, so a facet reads the same on every server.
	 */
	private const MONTHS = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService                   $objectService OpenRegister reads (system context: the index holds public things only).
	 * @param LoggerInterface                 $logger        Logs a read that failed.
	 * @param IL10N|null                      $l10n          The words in the portal's language.
	 * @param ExamplePortalDeclarations|null  $declarations  Which portal is an example set's.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly ?IL10N $l10n=null,
		private readonly ?ExamplePortalDeclarations $declarations=null,
	) {
	}//end __construct()

	/**
	 * The public index of one portal.
	 *
	 * @param string                 $portal The portal slug.
	 * @param DateTimeImmutable|null $today  Today; a test passes a fixed day.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-school-offers-its-portal-an-index-of-its-public-courses-programmes-and-school-days
	 */
	public function forPortal(string $portal, ?DateTimeImmutable $today=null): array {
		$namespace = $this->namespaceOf(portal: $portal);
		$day       = ($today ?? new DateTimeImmutable('today'))->format('Y-m-d');

		return array_merge(
			$this->courseItems(namespace: $namespace, today: $day),
			$this->programmeItems(namespace: $namespace),
			$this->eventItems(namespace: $namespace, today: $day)
		);
	}//end forPortal()

	/**
	 * The uuid namespace of an example portal, or '' for any other portal.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return string
	 */
	private function namespaceOf(string $portal): string {
		$declarations = ($this->declarations ?? new ExamplePortalDeclarations());
		foreach ($declarations->declaredSets() as $set) {
			$declared = $declarations->forSet(setId: $set);
			if (($declared['portal']['slug'] ?? null) === $portal && isset(self::SET_NAMESPACE[$set]) === true) {
				return self::SET_NAMESPACE[$set];
			}
		}

		return '';
	}//end namespaceOf()

	/**
	 * One card per published course with a run still to come: the first run's
	 * days, the other runs' first days, the place and the start months.
	 *
	 * @param string $namespace The uuid namespace, '' for all.
	 * @param string $today     Today, `YYYY-MM-DD`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function courseItems(string $namespace, string $today): array {
		$courses = array_filter($this->rows(schema: 'course', namespace: $namespace), fn (array $c): bool => $this->isOffered(course: $c));
		if ($courses === []) {
			return [];
		}

		$places = [];
		foreach ($this->rows(schema: 'vestiging', namespace: $namespace) as $place) {
			$places[$this->idOf(row: $place)] = (string)($place['name'] ?? '');
		}

		$published = [];
		foreach ($courses as $course) {
			$published[$this->idOf(row: $course)] = true;
		}

		$runs = [];
		foreach ($this->rows(schema: 'cohort', namespace: $namespace) as $cohort) {
			$courseId = (string)($cohort['courseId'] ?? '');
			if (in_array(($cohort['lifecycle'] ?? null), self::ENDED, true) === true || isset($published[$courseId]) === false) {
				continue;
			}

			$days = $this->runDays(cohortId: $this->idOf(row: $cohort), today: $today);
			if ($days !== []) {
				$runs[$courseId][] = ['days' => $days, 'place' => ($places[(string)($cohort['locationId'] ?? '')] ?? '')];
			}
		}

		$out = [];
		foreach ($courses as $course) {
			$courseRuns = ($runs[$this->idOf(row: $course)] ?? []);
			if ($courseRuns === []) {
				continue;
			}

			usort($courseRuns, static fn (array $a, array $b): int => $a['days'][0] <=> $b['days'][0]);
			$out[] = $this->courseItem(course: $course, runs: $courseRuns);
		}

		return $out;
	}//end courseItems()

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
	 * The days still to come of one cohort's lessons, in order; a cancelled
	 * lesson is no day.
	 *
	 * @param string $cohortId The cohort.
	 * @param string $today    Today.
	 *
	 * @return array<int, string>
	 */
	private function runDays(string $cohortId, string $today): array {
		$days = [];
		foreach ($this->rows(schema: 'session', namespace: '', filters: ['cohortId' => $cohortId]) as $session) {
			$day = substr((string)($session['startsAt'] ?? ''), 0, 10);
			if ((string)($session['cohortId'] ?? '') !== $cohortId || $day === '' || $day < $today || ($session['lifecycle'] ?? null) === 'cancelled') {
				continue;
			}

			$days[$day] = true;
		}

		$list = array_keys($days);
		sort($list);

		return $list;
	}//end runDays()

	/**
	 * One course card.
	 *
	 * @param array<string, mixed>             $course The course.
	 * @param array<int, array<string, mixed>> $runs   Its runs, first first.
	 *
	 * @return array<string, mixed>
	 */
	private function courseItem(array $course, array $runs): array {
		$first = $runs[0];
		$count = count($first['days']);
		$meta  = [$count === 1 ? $this->t('1 day') : $this->t('%s days', [$count])];
		$other = array_map(fn (array $run): string => $this->shortDay(day: $run['days'][0]), array_slice($runs, 1, self::MAX_OTHER_RUNS));
		if ($other !== []) {
			$meta[] = $this->t('also on %s', [implode(', ', $other)]);
		}

		$months = [];
		$where  = [];
		foreach ($runs as $run) {
			$months[] = $this->month(day: $run['days'][0]);
			if ($run['place'] !== '') {
				$where[] = $run['place'];
			}
		}

		$item = [
			'id'      => 'course:' . $this->idOf(row: $course),
			'type'    => 'course',
			'kind'    => $this->t('Course'),
			'title'   => (string)($course['name_nl'] ?? '') !== '' ? (string)$course['name_nl'] : (string)($course['name'] ?? ''),
			'summary' => (string)($course['description'] ?? ''),
			'date'    => $first['days'][0],
			'endDate' => $first['days'][$count - 1],
			'meta'    => $meta,
			'facets'  => [$this->t('Start in') => array_values(array_unique($months))],
		];
		if ($where !== []) {
			$item['facets'][$this->t('Venue')] = array_values(array_unique($where));
		}

		return $item;
	}//end courseItem()

	/**
	 * One card per published programme, with its level and learning path read
	 * from the opening of its description ("Niveau 4, beroepsopleidende
	 * leerweg (bol)", the way a school writes it); a programme that does not
	 * say so carries no such facet.
	 *
	 * @param string $namespace The uuid namespace.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function programmeItems(string $namespace): array {
		$out = [];
		foreach ($this->rows(schema: 'programme', namespace: $namespace) as $programme) {
			if (($programme['lifecycle'] ?? null) !== 'published' || trim((string)($programme['name'] ?? '')) === '') {
				continue;
			}

			$description = (string)($programme['description'] ?? '');
			$facets      = [];
			$meta        = [];
			if (preg_match('/\bniveau\s+([1-4])\b/i', $description, $level) === 1) {
				$facets[$this->t('Level')] = [$this->t('Level %s', [$level[1]])];
				$meta[]                    = $this->t('Level %s', [$level[1]]);
			}

			$paths = [];
			foreach (['bol' => 'BOL', 'bbl' => 'BBL'] as $token => $label) {
				if (preg_match('/\(' . $token . '\)/i', $description) === 1) {
					$paths[] = $label;
				}
			}

			if ($paths !== []) {
				$facets[$this->t('Learning path')] = $paths;
				$meta[] = implode(' ' . $this->t('or') . ' ', $paths);
			}

			$item = [
				'id'      => 'programme:' . $this->idOf(row: $programme),
				'type'    => 'programme',
				'kind'    => $this->t('Programme'),
				'title'   => trim((string)$programme['name']),
				'summary' => $description,
			];
			if ($meta !== []) {
				$item['meta'] = $meta;
			}

			if ($facets !== []) {
				$item['facets'] = $facets;
			}

			$out[] = $item;
		}//end foreach

		return $out;
	}//end programmeItems()

	/**
	 * The school-wide days still to come; a day for some groups only stays out.
	 *
	 * @param string $namespace The uuid namespace.
	 * @param string $today     Today.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function eventItems(string $namespace, string $today): array {
		$out = [];
		foreach ($this->rows(schema: 'school-event', namespace: $namespace) as $event) {
			$start = (string)($event['startsAt'] ?? '');
			$end   = (string)($event['endsAt'] ?? '');
			$last  = substr($end !== '' ? $end : $start, 0, 10);
			if (($event['audience'] ?? null) !== 'school' || $start === '' || $last < $today || trim((string)($event['title'] ?? '')) === '') {
				continue;
			}

			$item = [
				'id'    => 'event:' . $this->idOf(row: $event),
				'type'  => 'event',
				'kind'  => $this->t('Calendar'),
				'title' => trim((string)$event['title']),
				'date'  => substr($start, 0, 10),
			];
			if ($end !== '' && substr($end, 0, 10) !== $item['date']) {
				$item['endDate'] = substr($end, 0, 10);
			}

			if (trim((string)($event['description'] ?? '')) !== '') {
				$item['summary'] = trim((string)$event['description']);
			}

			$out[] = $item;
		}//end foreach

		return $out;
	}//end eventItems()

	/**
	 * "22 okt".
	 *
	 * @param string $day A day, `YYYY-MM-DD`.
	 *
	 * @return string
	 */
	private function shortDay(string $day): string {
		$month = self::MONTHS[((int)substr($day, 5, 2)) - 1] ?? '';

		return ((int)substr($day, 8, 2)) . ' ' . mb_substr($month, 0, 3);
	}//end shortDay()

	/**
	 * "Oktober 2026".
	 *
	 * @param string $day A day, `YYYY-MM-DD`.
	 *
	 * @return string
	 */
	private function month(string $day): string {
		$month = self::MONTHS[((int)substr($day, 5, 2)) - 1] ?? '';

		return ucfirst($month) . ' ' . substr($day, 0, 4);
	}//end month()

	/**
	 * A word in the portal's language.
	 *
	 * @param string            $text The English text.
	 * @param array<int, mixed> $args Its placeholders.
	 *
	 * @return string
	 */
	private function t(string $text, array $args=[]): string {
		if ($this->l10n !== null) {
			return $this->l10n->t($text, $args);
		}

		return vsprintf($text, $args);
	}//end t()

	/**
	 * A row's id: `id`, `uuid` or the `@self` envelope's.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['uuid'] ?? ($row['@self']['id'] ?? '')));
	}//end idOf()

	/**
	 * The rows of a schema, as arrays, in the namespace when one is given.
	 *
	 * @param string               $schema    The schema slug.
	 * @param string               $namespace The uuid namespace, '' for all.
	 * @param array<string, mixed> $filters   Extra equality filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(string $schema, string $namespace, array $filters=[]): array {
		try {
			$objects = $this->objectService->findAll(
				config: ['filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters), 'limit' => self::LIMIT],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Learniq: public index read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return [];
		}

		$rows = [];
		foreach ((array)$objects as $object) {
			$row = null;
			if (is_array($object) === true) {
				$row = $object;
			} else if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$row = (array)$object->jsonSerialize();
			}

			if ($row !== null && ($namespace === '' || str_starts_with($this->idOf(row: $row), $namespace) === true)) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rows()
}//end class
