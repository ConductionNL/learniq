<?php

/**
 * Tests for ComplianceRollupService::byRegulation: one row per active rule,
 * figures that agree with the department roll-up, RAG from the rule's own
 * thresholds, and the department filter.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#requirement-per-rule-coverage-table
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Service\ComplianceRollupService;
use OCA\Learniq\Service\ExternalTrainingService;
use OCA\Learniq\Service\RegulationAudienceResolver;
use OCA\Learniq\Service\RegulationCoverageService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\RegulationCoverageService
 * @covers \OCA\Learniq\Service\ComplianceRollupService
 * @uses \OCA\Learniq\Service\RegulationAudienceResolver
 * @uses \OCA\Learniq\Service\RunningExemptions
 */
class RegulationCoverageTest extends TestCase {

	/**
	 * Store rows per schema.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $store = [];

	/**
	 * Four learners across two directorates and five regulations, one of which reaches nobody.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = [
			'learner-profile' => [
				['id' => 'p-ann', 'ncUserId' => 'ann', 'department' => 'Operations/Logistics', 'roles' => ['learner'], 'lifecycle' => 'active'],
				['id' => 'p-bob', 'ncUserId' => 'bob', 'department' => 'Operations/Logistics', 'roles' => ['learner', 'manager'], 'lifecycle' => 'active'],
				['id' => 'p-cas', 'ncUserId' => 'cas', 'department' => 'Operations/Stations', 'roles' => ['learner'], 'lifecycle' => 'active'],
				['id' => 'p-dee', 'ncUserId' => 'dee', 'department' => 'Finance', 'roles' => ['learner'], 'lifecycle' => 'active'],
				['id' => 'p-old', 'ncUserId' => 'old', 'department' => 'Operations/Logistics', 'roles' => ['learner'], 'lifecycle' => 'merged'],
			],
			'regulation' => [
				['id' => 'reg-avg', 'slug' => 'AVG', 'name' => 'AVG', 'audienceScope' => 'all-employees', 'lifecycle' => 'published', 'ragRedThreshold' => 40, 'ragAmberThreshold' => 80],
				['id' => 'reg-vca', 'slug' => 'VCA', 'name' => 'VCA', 'audienceScope' => 'department', 'audienceDepartments' => ['Operations'], 'lifecycle' => 'published'],
				['id' => 'reg-bhv', 'slug' => 'BHV', 'name' => 'BHV', 'audienceScope' => 'role-specific', 'audienceRoles' => ['manager'], 'lifecycle' => 'published'],
				['id' => 'reg-nis', 'slug' => 'NIS2', 'name' => 'NIS2', 'audienceScope' => 'role-specific', 'audienceRoles' => ['ciso'], 'lifecycle' => 'published'],
				['id' => 'reg-bio', 'slug' => 'BIO', 'name' => 'BIO', 'audienceScope' => 'department', 'audienceDepartments' => ['Operations'], 'lifecycle' => 'published', 'ragRedThreshold' => 30, 'ragAmberThreshold' => 60],
				['id' => 'reg-off', 'slug' => 'OFF', 'name' => 'Retired', 'audienceScope' => 'all-employees', 'lifecycle' => 'published', 'active' => false],
				['id' => 'reg-new', 'slug' => 'NEW', 'name' => 'Draft', 'audienceScope' => 'all-employees', 'lifecycle' => 'draft'],
			],
			'regulation-exemption' => [
				['id' => 'ex-1', 'learnerId' => 'p-cas', 'regulationSlug' => 'BIO', 'lifecycle' => 'granted', 'validFrom' => '2026-01-01', 'validUntil' => '2026-12-31'],
			],
		];
	}//end setUp()

	/**
	 * The roll-up over the store; `$covered` lists "learnerKey|SLUG" pairs that count as covered.
	 *
	 * @param array<int,string> $covered Covered pairs.
	 *
	 * @return ComplianceRollupService
	 */
	private function rollup(array $covered): ComplianceRollupService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				$hits = [];
				foreach ($this->store[(string)($config['filters']['schema'] ?? '')] ?? [] as $row) {
					$match = true;
					foreach (array_diff_key(($config['filters'] ?? []), ['register' => true, 'schema' => true]) as $field => $value) {
						if (($row[$field] ?? null) !== $value) {
							$match = false;
						}
					}

					if ($match === true) {
						$hits[] = OrEntityFactory::make($row, (string)$config['filters']['schema']);
					}
				}

				return $hits;
			}
		);

		$training = $this->createMock(ExternalTrainingService::class);
		$training->method('isLearnerCovered')->willReturnCallback(
			static fn (string $learnerId, string $regulationSlug): bool => in_array($learnerId . '|' . $regulationSlug, $covered, true)
		);

		return new ComplianceRollupService($objectService, new RegulationAudienceResolver(), $training);
	}//end rollup()

	/**
	 * The per-rule service over the same store.
	 *
	 * @param array<int,string> $covered Covered pairs.
	 *
	 * @return RegulationCoverageService
	 */
	private function coverage(array $covered): RegulationCoverageService {
		return new RegulationCoverageService($this->rollup(covered: $covered), new RegulationAudienceResolver());
	}//end coverage()

	/**
	 * The evaluation day.
	 *
	 * @return DateTimeImmutable
	 */
	private static function today(): DateTimeImmutable {
		return new DateTimeImmutable('2026-10-01', new DateTimeZone('UTC'));
	}//end today()

	/**
	 * Five active rules give five rows with in scope, covered, percent and RAG.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#scenario-an-officer-compares-all-rules
	 */
	public function testAnOfficerComparesAllRules(): void {
		$rows = $this->coverage(covered: ['ann|AVG', 'bob|AVG', 'p-dee|AVG', 'ann|VCA', 'bob|BHV', 'ann|BIO'])->byRegulation(now: self::today());
		$bySlug = array_column($rows, null, 'slug');

		self::assertSame(['AVG', 'BHV', 'BIO', 'NIS2', 'VCA'], array_column($rows, 'slug'));
		self::assertSame(
			['id' => 'reg-avg', 'slug' => 'AVG', 'name' => 'AVG', 'inScope' => 4, 'covered' => 3, 'excused' => 0, 'coveragePercent' => 75.0, 'rag' => 'amber'],
			$bySlug['AVG']
		);
		// Default thresholds 70 and 90: one of three is red.
		self::assertSame([3, 1, 33.3, 'red'], [$bySlug['VCA']['inScope'], $bySlug['VCA']['covered'], $bySlug['VCA']['coveragePercent'], $bySlug['VCA']['rag']]);
		self::assertSame([1, 1, 100.0, 'green'], [$bySlug['BHV']['inScope'], $bySlug['BHV']['covered'], $bySlug['BHV']['coveragePercent'], $bySlug['BHV']['rag']]);
		// Cas is exempt from BIO: two in scope, one excused, 50% against 30/60 is amber.
		self::assertSame([2, 1, 1, 50.0, 'amber'], [$bySlug['BIO']['inScope'], $bySlug['BIO']['covered'], $bySlug['BIO']['excused'], $bySlug['BIO']['coveragePercent'], $bySlug['BIO']['rag']]);
	}//end testAnOfficerComparesAllRules()

	/**
	 * A rule that reaches nobody shows zero in scope and no percentage or RAG.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#scenario-a-rule-with-nobody-in-scope
	 */
	public function testARuleWithNobodyInScope(): void {
		$bySlug = array_column($this->coverage(covered: [])->byRegulation(now: self::today()), null, 'slug');

		self::assertSame([0, 0, null, null], [$bySlug['NIS2']['inScope'], $bySlug['NIS2']['covered'], $bySlug['NIS2']['coveragePercent'], $bySlug['NIS2']['rag']]);
	}//end testARuleWithNobodyInScope()

	/**
	 * The department filter counts only learners of that department and its teams.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#scenario-the-department-filter-narrows-the-table
	 */
	public function testTheDepartmentFilterNarrowsTheTable(): void {
		$rollup = $this->coverage(covered: ['ann|AVG', 'p-dee|AVG']);

		$logistics = array_column($rollup->byRegulation(department: 'Operations/Logistics', now: self::today()), null, 'slug');
		self::assertSame([2, 1, 50.0], [$logistics['AVG']['inScope'], $logistics['AVG']['covered'], $logistics['AVG']['coveragePercent']]);
		self::assertSame(2, $logistics['VCA']['inScope']);

		$operations = array_column($rollup->byRegulation(department: 'Operations', now: self::today()), null, 'slug');
		self::assertSame(3, $operations['AVG']['inScope']);
		self::assertSame(1, $operations['AVG']['covered']);

		$nowhere = array_column($rollup->byRegulation(department: 'Operations/Logis', now: self::today()), null, 'slug');
		self::assertSame(0, $nowhere['AVG']['inScope']);
		self::assertCount(5, $nowhere);
	}//end testTheDepartmentFilterNarrowsTheTable()

	/**
	 * The table cannot disagree with the department roll-up: the same obligations, covered and excused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#requirement-per-rule-coverage-table
	 */
	public function testTheTotalsMatchTheDepartmentRollUp(): void {
		$rollup = $this->rollup(covered: ['ann|AVG', 'bob|AVG', 'ann|VCA', 'bob|BHV', 'ann|BIO']);
		$rules = (new RegulationCoverageService($rollup, new RegulationAudienceResolver()))->byRegulation(now: self::today());
		$departments = array_filter($rollup->byDepartment(now: self::today()), static fn (array $node): bool => $node['depth'] === 0);

		self::assertSame(array_sum(array_column($departments, 'obligations')), array_sum(array_column($rules, 'inScope')));
		self::assertSame(array_sum(array_column($departments, 'covered')), array_sum(array_column($rules, 'covered')));
		self::assertSame(array_sum(array_column($departments, 'excused')), array_sum(array_column($rules, 'excused')));
	}//end testTheTotalsMatchTheDepartmentRollUp()
}//end class
