<?php

/**
 * Unit tests for CurriculumCoverageRollup (curriculum-coverage-rollup).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/curriculum-coverage-rollup/tasks.md#task-3-curriculumcoveragerollup
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\CompetencyAlignmentNormaliser;
use OCA\Learniq\Service\CompetencyTree;
use OCA\Learniq\Service\CurriculumCoverageCalculator;
use OCA\Learniq\Service\CurriculumCoverageRollup;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Drives the rollup against an in-memory OpenRegister double.
 */
class CurriculumCoverageRollupTest extends TestCase {

	/**
	 * Objects by schema, then id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Recorded saveObject calls: [uuid, object].
	 *
	 * @var array<int, array{0: string|null, 1: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * Recorded deleteObject uuids.
	 *
	 * @var array<int, string>
	 */
	private array $deleted = [];

	/**
	 * Recorded findAll configs.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * Recorded find ids.
	 *
	 * @var array<int, string>
	 */
	private array $finds = [];

	/**
	 * The real calculator, also used to build expected stored rows.
	 *
	 * @var CurriculumCoverageCalculator
	 */
	private CurriculumCoverageCalculator $calculator;

	/**
	 * Build the calculator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calculator = new CurriculumCoverageCalculator(new CompetencyAlignmentNormaliser(), new CompetencyTree());

	}//end setUp()

	/**
	 * The rollup over the in-memory store.
	 *
	 * @return CurriculumCoverageRollup
	 */
	private function rollup(): CurriculumCoverageRollup {
		$service = $this->createMock(ObjectService::class);
		$service->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend=[], bool $files=false, mixed $register=null, mixed $schema=null, bool $_rbac=true): ?object {
				$this->finds[] = (string) $id;
				self::assertFalse($_rbac);
				if (isset($this->store[$schema][$id]) === false) {
					throw new RuntimeException('Object not found');
				}

				return OrEntityFactory::make($this->store[$schema][$id], (string) $schema, 'learniq', (string) $id);
			}
		);
		$service->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac=true, bool $_multitenancy=true): array {
				self::assertFalse($_rbac);
				self::assertFalse($_multitenancy);
				$this->queries[] = $config;
				return array_values(array_filter($this->store[$config['schema']] ?? [], fn (array $row): bool => $this->rowMatches($row, $config['filters'])));
			}
		);
		$service->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], mixed $register=null, mixed $schema=null, ?string $uuid=null): object {
				$this->saved[] = [$uuid, $object];
				return OrEntityFactory::make($object, (string) $schema);
			}
		);
		$service->method('deleteObject')->willReturnCallback(
			function (string $uuid): bool {
				$this->deleted[] = $uuid;
				return true;
			}
		);

		return new CurriculumCoverageRollup(objectService: $service, calculator: $this->calculator, logger: new NullLogger());
	}//end rollup()

	/**
	 * Whether a row matches findAll filters: equality, or "contains any"
	 * for a list filter on a list property.
	 *
	 * @param array<string, mixed> $row     The row.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return bool
	 */
	private function rowMatches(array $row, array $filters): bool {
		foreach ($filters as $key => $value) {
			if (is_array($value) === true) {
				if (array_intersect($value, (array) ($row[$key] ?? [])) === []) {
					return false;
				}

				continue;
			}

			if (($row[$key] ?? null) !== $value) {
				return false;
			}
		}

		return true;
	}//end rowMatches()

	/**
	 * One framework, one leaf goal, one lesson aligned to it.
	 *
	 * @return void
	 */
	private function seedOneGoal(): void {
		$this->store['competency-framework']['fw'] = ['id' => 'fw', 'tenant_id' => 't1', 'proficiencyLevels' => [['levelId' => 'competent', 'order' => 1]]];
		$this->store['competency']['g1']           = ['id' => 'g1', 'frameworkId' => 'fw', 'code' => 'G1'];
		$this->store['lesson']['l1']               = ['id' => 'l1', 'competencyIds' => ['g1']];
	}//end seedOneGoal()

	/**
	 * Store the rows the calculator would write, with ids; the no-subject
	 * row as OpenRegister returns it (nulls dropped, 0 for 0.0).
	 *
	 * @return void
	 */
	private function seedStoredRows(): void {
		$rows = $this->calculator->compute($this->store['competency-framework']['fw'], array_values($this->store['competency']), array_values($this->store['lesson']), [], 'earlier');
		foreach ($rows as $row) {
			if ($row['subjectScope'] === 'none') {
				$row = array_filter($row, static fn (mixed $value): bool => $value !== null);
				$row['assessedPercent'] = 0;
				$this->store['curriculum-coverage']['row-none'] = array_merge($row, ['id' => 'row-none']);
				continue;
			}

			$row['plannedCount'] = 0;
			$this->store['curriculum-coverage']['row-all'] = array_merge($row, ['id' => 'row-all']);
		}

		$this->store['curriculum-coverage']['row-stale'] = [
			'id'           => 'row-stale',
			'frameworkId'  => 'fw',
			'year'         => 'groep 9',
			'subjectScope' => 'all',
		];
	}//end seedStoredRows()

	/**
	 * Only the changed row is saved, as an update of its stored id; the
	 * identical row is left alone even though OpenRegister returns it
	 * without nulls and with an integer zero.
	 *
	 * @return void
	 */
	public function testRecomputeUpsertsOnlyChangedRows(): void {
		$this->seedOneGoal();
		$this->seedStoredRows();

		$result = $this->rollup()->recompute('fw');

		self::assertSame(1, $result['saved']);
		self::assertSame(1, $result['unchanged']);
		self::assertCount(1, $this->saved);
		self::assertSame('row-all', $this->saved[0][0]);
		self::assertSame(1, $this->saved[0][1]['plannedCount']);
		self::assertSame('t1', $this->saved[0][1]['tenant_id']);

	}//end testRecomputeUpsertsOnlyChangedRows()

	/**
	 * A bucket no goal uses any more is deleted; a first run creates rows.
	 *
	 * @return void
	 */
	public function testRecomputeDeletesVanishedBuckets(): void {
		$this->seedOneGoal();
		$this->seedStoredRows();

		$result = $this->rollup()->recompute('fw');

		self::assertSame(1, $result['deleted']);
		self::assertSame(['row-stale'], $this->deleted);

		$this->store['curriculum-coverage'] = [];
		$this->saved = [];
		$first = $this->rollup()->recompute('fw');
		self::assertSame(2, $first['saved']);
		self::assertNull($this->saved[0][0], 'a first run creates, it does not update');

	}//end testRecomputeDeletesVanishedBuckets()

	/**
	 * A framework that no longer exists loses every coverage row.
	 *
	 * @return void
	 */
	public function testMissingFrameworkDeletesItsRows(): void {
		$this->store['curriculum-coverage']['r1'] = ['id' => 'r1', 'frameworkId' => 'gone', 'subjectScope' => 'all'];
		$this->store['curriculum-coverage']['r2'] = ['id' => 'r2', 'frameworkId' => 'gone', 'year' => 'groep 5', 'subjectScope' => 'all'];
		$this->store['curriculum-coverage']['r3'] = ['id' => 'r3', 'frameworkId' => 'other', 'subjectScope' => 'all'];

		$result = $this->rollup()->recompute('gone');

		self::assertSame(['saved' => 0, 'deleted' => 2, 'unchanged' => 0], $result);
		self::assertSame(['r1', 'r2'], $this->deleted);

	}//end testMissingFrameworkDeletesItsRows()

	/**
	 * Goal ids resolve to their distinct frameworks, each goal loaded once;
	 * an unknown goal is skipped.
	 *
	 * @return void
	 */
	public function testFrameworksForGoalsResolvesEachGoalOnce(): void {
		$this->store['competency'] = [
			'g1' => ['id' => 'g1', 'frameworkId' => 'fw1'],
			'g2' => ['id' => 'g2', 'frameworkId' => 'fw1'],
			'g3' => ['id' => 'g3', 'frameworkId' => 'fw2'],
		];

		self::assertSame(['fw1', 'fw2'], $this->rollup()->frameworksForGoals(['g1', 'g2', 'g1', 'g3', 'missing']));
		self::assertSame(['g1', 'g2', 'g3', 'missing'], $this->finds);

	}//end testFrameworksForGoalsResolvesEachGoalOnce()

	/**
	 * Referencing rows come from "contains any" queries on competencyIds,
	 * chunked by 100 goal ids, for all four schemas.
	 *
	 * @return void
	 */
	public function testReferencesAreQueriedInChunksAcrossAllFourSchemas(): void {
		$this->store['competency-framework']['fw'] = ['id' => 'fw', 'proficiencyLevels' => []];
		for ($i = 0; $i < 150; $i++) {
			$this->store['competency']['g' . $i] = ['id' => 'g' . $i, 'frameworkId' => 'fw', 'code' => 'G' . $i];
		}

		$this->store['exam']['e1'] = ['id' => 'e1', 'competencyIds' => ['g149']];

		$this->rollup()->recompute('fw');

		$bySchema = [];
		foreach ($this->queries as $query) {
			if (isset($query['filters']['competencyIds']) === true) {
				$bySchema[$query['schema']][] = count($query['filters']['competencyIds']);
			}
		}

		self::assertSame(['lesson' => [100, 50], 'course' => [100, 50], 'assignment' => [100, 50], 'exam' => [100, 50]], $bySchema);
		$total = $this->saved[0][1];
		self::assertSame(150, $total['goalCount']);
		self::assertSame(1, $total['assessedCount']);

	}//end testReferencesAreQueriedInChunksAcrossAllFourSchemas()

	/**
	 * The command helpers list frameworks and check one exists.
	 *
	 * @return void
	 */
	public function testFrameworkListingForTheCommand(): void {
		$this->store['competency-framework'] = ['a' => ['id' => 'a'], 'b' => ['id' => 'b']];

		self::assertSame(['a', 'b'], $this->rollup()->allFrameworkIds());
		self::assertTrue($this->rollup()->frameworkExists('a'));
		self::assertFalse($this->rollup()->frameworkExists('zzz'));

	}//end testFrameworkListingForTheCommand()
}//end class
