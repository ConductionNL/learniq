<?php

/**
 * Learniq Curriculum Coverage Rollup
 *
 * Loads one framework, its goals and the Lesson, Course, Assignment and
 * Assessment rows that align to them, runs CurriculumCoverageCalculator and
 * upserts the CurriculumCoverage rows (curriculum-coverage-rollup). It saves
 * only rows whose numbers changed, deletes rows whose year or subject bucket
 * no longer exists, and deletes every row of a framework that is gone.
 *
 * Referencing rows are found with one "contains any" query per schema on
 * `competencyIds`, which goal-alignment-depth keeps derived from the
 * alignments; goal ids are chunked so the OR-chain stays small.
 *
 * All reads and writes use `_rbac: false` and `_multitenancy: false`: the
 * rollup is a system computation, the rows are derived, and each row carries
 * its framework's tenant.
 *
 * Consumed by CurriculumCoverageRollupHandler and RecomputeCurriculumCoverage.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/changes/curriculum-coverage-rollup/specs/competency/spec.md#requirement-coverage-is-recomputed-on-save-for-the-touched-frameworks-only-never-by-a-timedjob
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Recomputes and stores a framework's curriculum coverage.
 *
 * @spec openspec/changes/curriculum-coverage-rollup/specs/competency/spec.md#requirement-coverage-is-recomputed-on-save-for-the-touched-frameworks-only-never-by-a-timedjob
 */
class CurriculumCoverageRollup {

	private const REGISTER          = 'learniq';
	private const COVERAGE_SCHEMA   = 'curriculum-coverage';
	private const COMPETENCY_SCHEMA = 'competency';
	private const FRAMEWORK_SCHEMA  = 'competency-framework';

	/**
	 * Schemas whose alignments plan a goal, and schemas whose alignments
	 * assess it. Assessment's slug is `exam`.
	 */
	private const PLANNED_SCHEMAS  = ['lesson', 'course'];
	private const ASSESSED_SCHEMAS = ['assignment', 'exam'];

	/**
	 * Goal ids per "contains any" query, and the row cap per query.
	 */
	private const ID_CHUNK = 100;
	private const LIMIT    = 5000;

	/**
	 * Keys ignored when deciding whether a stored row changed.
	 */
	private const VOLATILE_KEYS = ['id', 'uuid', '@self', 'lastRecomputedAt'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService                $objectService OpenRegister object access.
	 * @param CurriculumCoverageCalculator $calculator    The pure counting.
	 * @param LoggerInterface              $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly CurriculumCoverageCalculator $calculator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Recompute one framework's coverage and store what changed.
	 *
	 * @param string $frameworkId UUID of the CompetencyFramework.
	 *
	 * @return array{saved: int, deleted: int, unchanged: int}
	 *
	 * @spec openspec/changes/curriculum-coverage-rollup/specs/competency/spec.md#requirement-coverage-is-recomputed-on-save-for-the-touched-frameworks-only-never-by-a-timedjob
	 */
	public function recompute(string $frameworkId): array {
		$result   = ['saved' => 0, 'deleted' => 0, 'unchanged' => 0];
		$existing = $this->storedRows(frameworkId: $frameworkId);
		$framework = $this->load(schema: self::FRAMEWORK_SCHEMA, id: $frameworkId);
		$computed  = [];
		if ($framework !== null) {
			$goals    = $this->findRows(schema: self::COMPETENCY_SCHEMA, filters: ['frameworkId' => $frameworkId]);
			$goalIds  = array_values(array_filter(array_map(fn (array $goal): string => $this->idOf(row: $goal), $goals)));
			$computed = $this->calculator->compute(
				framework: array_merge($framework, ['id' => $frameworkId]),
				goals: $goals,
				planned: $this->rowsAligningTo(schemas: self::PLANNED_SCHEMAS, goalIds: $goalIds),
				assessed: $this->rowsAligningTo(schemas: self::ASSESSED_SCHEMAS, goalIds: $goalIds),
				now: (new DateTimeImmutable())->format(\DATE_ATOM)
			);
		}

		foreach ($computed as $row) {
			$key    = $this->calculator->bucketKey(row: $row);
			$stored = ($existing[$key] ?? null);
			unset($existing[$key]);
			if ($stored !== null && $this->comparable(row: $stored) === $this->comparable(row: $row)) {
				$result['unchanged']++;
				continue;
			}

			$this->save(row: $row, storedId: $this->idOf(row: ($stored ?? [])));
			$result['saved']++;
		}

		foreach ($existing as $stale) {
			$this->delete(id: $this->idOf(row: $stale));
			$result['deleted']++;
		}

		return $result;
	}//end recompute()

	/**
	 * The distinct framework ids of a set of goals; unknown goals are skipped.
	 *
	 * @param array<int, string> $goalIds Goal UUIDs.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/curriculum-coverage-rollup/specs/competency/spec.md#requirement-coverage-is-recomputed-on-save-for-the-touched-frameworks-only-never-by-a-timedjob
	 */
	public function frameworksForGoals(array $goalIds): array {
		$frameworks = [];
		foreach (array_unique($goalIds) as $goalId) {
			$goal        = $this->load(schema: self::COMPETENCY_SCHEMA, id: (string) $goalId);
			$frameworkId = (string) ($goal['frameworkId'] ?? '');
			if ($frameworkId !== '') {
				$frameworks[$frameworkId] = true;
			}
		}

		return array_keys($frameworks);
	}//end frameworksForGoals()

	/**
	 * Every framework id in the register.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/curriculum-coverage-rollup/specs/competency/spec.md#requirement-an-occ-command-fills-coverage-for-existing-data
	 */
	public function allFrameworkIds(): array {
		return array_values(
			array_filter(
				array_map(fn (array $row): string => $this->idOf(row: $row), $this->findRows(schema: self::FRAMEWORK_SCHEMA, filters: []))
			)
		);
	}//end allFrameworkIds()

	/**
	 * Whether a framework exists.
	 *
	 * @param string $frameworkId UUID of the CompetencyFramework.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/curriculum-coverage-rollup/specs/competency/spec.md#requirement-an-occ-command-fills-coverage-for-existing-data
	 */
	public function frameworkExists(string $frameworkId): bool {
		return $this->load(schema: self::FRAMEWORK_SCHEMA, id: $frameworkId) !== null;
	}//end frameworkExists()

	/**
	 * The framework's stored coverage rows by bucket key.
	 *
	 * @param string $frameworkId UUID of the CompetencyFramework.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function storedRows(string $frameworkId): array {
		$rows = [];
		foreach ($this->findRows(schema: self::COVERAGE_SCHEMA, filters: ['frameworkId' => $frameworkId]) as $row) {
			$rows[$this->calculator->bucketKey(row: $row)] = $row;
		}

		return $rows;
	}//end storedRows()

	/**
	 * Rows of the given schemas whose `competencyIds` contain any goal id,
	 * deduplicated by id.
	 *
	 * @param array<int, string> $schemas Schema slugs.
	 * @param array<int, string> $goalIds Goal UUIDs.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rowsAligningTo(array $schemas, array $goalIds): array {
		$rows = [];
		foreach ($schemas as $schema) {
			foreach (array_chunk($goalIds, self::ID_CHUNK) as $chunk) {
				foreach ($this->findRows(schema: $schema, filters: ['competencyIds' => $chunk]) as $row) {
					$rows[$schema . ':' . $this->idOf(row: $row)] = $row;
				}
			}
		}

		return array_values($rows);
	}//end rowsAligningTo()

	/**
	 * A row reduced to what decides whether it changed: volatile keys and
	 * nulls dropped, keys sorted, percentages as floats. So a stored row that
	 * OpenRegister returns without its null properties, or with 0 for 0.0,
	 * still compares equal.
	 *
	 * @param array<string, mixed> $row A coverage row.
	 *
	 * @return string Canonical JSON.
	 */
	private function comparable(array $row): string {
		$fields = array_diff_key($row, array_flip(self::VOLATILE_KEYS));
		foreach (['plannedPercent', 'assessedPercent'] as $percent) {
			if (isset($fields[$percent]) === true) {
				$fields[$percent] = (float) $fields[$percent];
			}
		}

		return (string) json_encode($this->canonical(value: $fields), \JSON_PRESERVE_ZERO_FRACTION);
	}//end comparable()

	/**
	 * Recursively drop nulls from maps and sort their keys; lists keep order.
	 *
	 * @param mixed $value Any decoded JSON value.
	 *
	 * @return mixed
	 */
	private function canonical(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$value = array_map(fn (mixed $item): mixed => $this->canonical(value: $item), $value);
		if (array_is_list($value) === true) {
			return $value;
		}

		$value = array_filter($value, static fn (mixed $item): bool => $item !== null);
		ksort($value);
		return $value;
	}//end canonical()

	/**
	 * Save a coverage row, as an update when it was stored before.
	 *
	 * @param array<string, mixed> $row      The computed row.
	 * @param string               $storedId The stored row's id, or ''.
	 *
	 * @return void
	 */
	private function save(array $row, string $storedId): void {
		$uuid = null;
		if ($storedId !== '') {
			$uuid = $storedId;
		}

		$this->objectService->saveObject(
			object: $row,
			register: self::REGISTER,
			schema: self::COVERAGE_SCHEMA,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);
	}//end save()

	/**
	 * Delete a coverage row whose bucket no longer exists.
	 *
	 * @param string $id The row's id.
	 *
	 * @return void
	 */
	private function delete(string $id): void {
		if ($id === '') {
			return;
		}

		$this->objectService->deleteObject(
			uuid: $id,
			register: self::REGISTER,
			schema: self::COVERAGE_SCHEMA,
			_rbac: false,
			_multitenancy: false
		);
	}//end delete()

	/**
	 * Load one row by id, or null when it does not exist or cannot be read.
	 *
	 * @param string $schema Schema slug.
	 * @param string $id     Object UUID.
	 *
	 * @return array<string, mixed>|null
	 */
	private function load(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objectService->find(
				id: $id,
				register: self::REGISTER,
				schema: $schema,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->debug(
				'[CurriculumCoverageRollup] {schema} {id} not loadable: {msg}',
				['schema' => $schema, 'id' => $id, 'msg' => $exception->getMessage()]
			);
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $object->jsonSerialize();
	}//end load()

	/**
	 * Every row of a schema matching the filters, as arrays.
	 *
	 * @param string               $schema  Schema slug.
	 * @param array<string, mixed> $filters Property filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function findRows(string $schema, array $filters): array {
		$results = $this->objectService->findAll(
			['register' => self::REGISTER, 'schema' => $schema, 'filters' => $filters, 'limit' => self::LIMIT],
			_rbac: false,
			_multitenancy: false
		);

		$rows = [];
		foreach ($results as $result) {
			if (is_array($result) === false) {
				$result = $result->jsonSerialize();
			}

			$rows[] = $result;
		}

		return $rows;
	}//end findRows()

	/**
	 * The id of an object row, wherever OpenRegister put it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		$id = ($row['id'] ?? ($row['@self']['id'] ?? ($row['uuid'] ?? '')));
		if (is_string($id) === false) {
			return '';
		}

		return $id;
	}//end idOf()
}//end class
