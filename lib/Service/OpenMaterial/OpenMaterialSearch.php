<?php

/**
 * Learniq Open Material Search
 *
 * The search panel's server side: the panel's settings (source on or off, the
 * school's default level, collections off), the search with the licence
 * decision on every record, and one record's detail. Every answer from the
 * source is reported to the connections page.
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
 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\OpenMaterial;

use OCA\Learniq\Exception\IntegriqUnavailableException;
use OCA\Learniq\Exception\MaterialImportRefusedException;
use OCA\Learniq\Kernel\Contracts\OpenMaterialSearchInterface;
use OCA\Learniq\Service\ConnectionReportService;
use OCA\Learniq\Service\SegmentService;
use OCP\IUser;

/**
 * Search, detail and panel settings for open learning material.
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
 */
class OpenMaterialSearch implements OpenMaterialSearchInterface {

	/**
	 * The connection key on the connections page.
	 */
	public const CONNECTION_KEY = ConnectionReportService::OPEN_MATERIAL_KEY;

	/**
	 * The school profile's default NL-LOM levels.
	 */
	private const LEVELS_BY_SEGMENT = [
		'po'  => ['PO'],
		'vo'  => ['VO'],
		'mbo' => ['BVE'],
		'he'  => ['HBO', 'WO'],
	];

	/**
	 * The filters a teacher may set, each a list of values.
	 */
	private const FILTERS = ['levels', 'licenses', 'collections', 'formats', 'excludeLicenses', 'excludeFormats'];

	/**
	 * Constructor.
	 *
	 * @param OpenMaterialSourceClient  $client   Searches the source.
	 * @param OpenMaterialImportService $imports  Adds the licence decision to a record.
	 * @param OpenMaterialSettings      $settings The administrator's switches.
	 * @param SegmentService            $segments The school profile.
	 * @param ConnectionReportService   $reports  Records the source's status.
	 * @param MaterialImportTarget      $targets  Tells whether a course is commercial.
	 */
	public function __construct(
		private readonly OpenMaterialSourceClient $client,
		private readonly OpenMaterialImportService $imports,
		private readonly OpenMaterialSettings $settings,
		private readonly SegmentService $segments,
		private readonly ConnectionReportService $reports,
		private readonly MaterialImportTarget $targets,
	) {
	}//end __construct()

	/**
	 * What the panel needs before a search, and for a course whether it is commercial.
	 *
	 * @param array{user?: IUser, courseId?: string} $course The caller and the course, when the panel opens on one.
	 *
	 * @return array<string, mixed> enabled, available, defaultLevels, collectionsOff, linkCollections, commercialCourse.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-sources-are-connections-an-administrator-switches
	 */
	public function panel(array $course = []): array {
		$panel = array_merge(
			$this->settings->toArray(),
			[
				'available'     => $this->client->isAvailable(),
				'defaultLevels' => (self::LEVELS_BY_SEGMENT[$this->segments->currentSegment()] ?? []),
			]
		);
		if (isset($course['user']) === true && ($course['courseId'] ?? '') !== '') {
			$panel['commercialCourse'] = $this->commercial(user: $course['user'], courseId: $course['courseId']);
		}

		return $panel;
	}//end panel()

	/**
	 * Whether a course of the caller's tenant is commercial; false when it is not found.
	 *
	 * @param IUser  $user     The caller.
	 * @param string $courseId The course UUID.
	 *
	 * @return bool True for a corporate or paid course.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#scenario-non-commercial-material-is-flagged-in-a-paid-course
	 */
	private function commercial(IUser $user, string $courseId): bool {
		try {
			return $this->targets->resolve(user: $user, request: ['courseId' => $courseId])['commercial'];
		} catch (MaterialImportRefusedException) {
			return false;
		}
	}//end commercial()

	/**
	 * Search the source and decide on every record.
	 *
	 * @param array<string, mixed> $request query, page, the filter lists, includeCollectionsOff and facets.
	 *
	 * @return array<string, mixed> The search result with a decision per record.
	 *
	 * @throws IntegriqUnavailableException When the source does not answer.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#scenario-a-teacher-searches-from-the-lesson-editor
	 */
	public function search(array $request): array {
		$query  = [
			'query'   => trim((string)($request['query'] ?? '')),
			'filters' => $this->filters(request: $request),
			'page'    => max(1, (int)($request['page'] ?? 1)),
			'facets'  => (($request['facets'] ?? true) !== false),
		];
		$result = $this->reported(call: fn (): array => $this->client->search(request: $query));
		$result['records'] = array_map(fn (array $record): array => $this->imports->withDecision(record: $record), (array)($result['records'] ?? []));

		return $result;
	}//end search()

	/**
	 * One record with its decision, or null when the source has none.
	 *
	 * @param string $sourceId The record identifier.
	 *
	 * @return array<string, mixed>|null The record.
	 *
	 * @throws IntegriqUnavailableException When the source does not answer.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
	 */
	public function record(string $sourceId): ?array {
		$found = $this->reported(call: fn (): array => ['record' => $this->client->find(sourceId: $sourceId)]);

		if ($found['record'] === null) {
			return null;
		}

		return $this->imports->withDecision(record: $found['record']);
	}//end record()

	/**
	 * The filters to send: the teacher's lists, and the collections left out.
	 *
	 * Without includeCollectionsOff the administrator's collections-off are left out too.
	 *
	 * @param array<string, mixed> $request The search request.
	 *
	 * @return array<string, array<int, string>> The filters.
	 */
	private function filters(array $request): array {
		$filters = [];
		foreach (self::FILTERS as $name) {
			$filters[$name] = $this->listOf(value: $request[$name] ?? []);
		}

		$excluded = $this->listOf(value: $request['excludeCollections'] ?? []);
		if (($request['includeCollectionsOff'] ?? false) !== true) {
			$excluded = array_values(array_unique(array_merge($excluded, array_diff($this->settings->collectionsOff(), $filters['collections']))));
		}

		$filters['excludeCollections'] = $excluded;

		return array_filter($filters);
	}//end filters()

	/**
	 * A request value as a list of non-empty strings.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<int, string> The list.
	 */
	private function listOf(mixed $value): array {
		return array_values(array_filter(array_map('strval', (array)$value)));
	}//end listOf()

	/**
	 * Run one call to the source and report how it went.
	 *
	 * @param callable(): array<string, mixed> $call The call.
	 *
	 * @return array<string, mixed> The call's result.
	 *
	 * @throws IntegriqUnavailableException When the source does not answer.
	 */
	private function reported(callable $call): array {
		try {
			$result = $call();
		} catch (IntegriqUnavailableException $exception) {
			$this->reports->observe(key: self::CONNECTION_KEY, status: 'error', reason: $exception->getMessage());
			throw $exception;
		}

		$this->reports->observe(key: self::CONNECTION_KEY, status: 'configured', reason: 'The source answered the last search.');

		return $result;
	}//end reported()
}//end class
