<?php

/**
 * Learniq RolloverExecutionService characterization test.
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
 * @spec openspec/changes/archive/2026-06-15-school-year-rollover/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Tests\Support\FindCallShape;
use OCA\Learniq\Service\IntegriqExchangeClient;
use OCA\Learniq\Service\RolloverExecutionService;
use OCA\Learniq\Service\RolloverService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins every ObjectService call RolloverExecutionService::execute() makes, and
 * its result, per scenario, against tests/Unit/Service/golden/RolloverExecution.json.
 * With RECORD_GOLDEN=1 the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Service\RolloverExecutionService
 * @uses   \OCA\Learniq\Service\RolloverService
 */
class RolloverExecutionGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/RolloverExecution.json';

	/**
	 * Each scenario: the stored rows per schema and the plan.
	 *
	 * @return array<string, array{0: array<string, list<array<string, mixed>>>, 1: array<string, mixed>}>
	 */
	public static function scenarios(): array {
		$from       = ['id' => 'c1', 'name' => 'MV1A', 'programmeId' => 'p-mv', 'programmeYear' => 1, 'learnerIds' => ['l1', 'l2', 'l3', 'l4'], 'lifecycle' => 'active'];
		$noYear     = array_diff_key($from, ['programmeYear' => true]);
		$plan       = [
			'toAcademicYear' => '2026-2027', 'tenant_id' => 't1',
			'mappings' => [['fromCohortId' => 'c1', 'action' => 'promote', 'toCohortName' => 'MV2A']],
		];
		$enrolments = [
			['id' => 'e1', 'learnerId' => 'l1', 'lifecycle' => 'active', 'mandatory' => true, 'cohortId' => 'c1'],
			['id' => 'e2', 'learnerId' => 'l1', 'lifecycle' => 'completed', 'mandatory' => true, 'cohortId' => 'c1'],
			['id' => 'e3', 'learnerId' => 'l2', 'lifecycle' => 'active', 'mandatory' => false, 'cohortId' => 'c1'],
		];
		$existing   = ['id' => 'to-1', 'name' => 'MV2A', 'academicYear' => '2026-2027', 'tenant_id' => 't1', 'learnerIds' => ['l9', 'l1'], 'note' => 'kept'];
		$mapping    = ['fromCohortId' => 'c1', 'action' => 'promote', 'toCohortName' => 'MV2A'];
		return [
			'new cohort, programme kept and year raised' => [['cohort' => [$from], 'enrolment' => $enrolments], $plan],
			'new cohort with course and programme from the mapping' => [
				['cohort' => [$noYear], 'enrolment' => $enrolments],
				array_merge($plan, ['mappings' => [array_merge($mapping, ['toProgrammeId' => 'p-x', 'toCourseId' => 'k-1', 'carryNonMandatory' => true])]]),
			],
			'empty programme and course ids are left out' => [
				['cohort' => [$noYear]],
				array_merge($plan, ['mappings' => [array_merge($mapping, ['toProgrammeId' => '', 'toCourseId' => ''])]]),
			],
			'existing to-year cohort gains members once' => [['cohort' => [$from, $existing], 'enrolment' => $enrolments], $plan],
			'existing to-year cohort without members' => [['cohort' => [$from, array_diff_key($existing, ['learnerIds' => true])]], $plan],
			'overrides: retain, outflow, graduate' => [
				['cohort' => [$from], 'enrolment' => $enrolments],
				array_merge(
					$plan,
					[
						'learnerOverrides' => [
							['learnerId' => 'l2', 'action' => 'retain'],
							['learnerId' => 'l3', 'action' => 'outflow'],
							['learnerId' => 'l4', 'action' => 'graduate'],
						],
					]
				),
			],
			'no tenant' => [['cohort' => [$from]], array_merge($plan, ['tenant_id' => ''])],
			'no to-cohort name' => [['cohort' => [$from]], array_merge($plan, ['mappings' => [['fromCohortId' => 'c1', 'action' => 'promote']]])],
			'mapping already done' => [['cohort' => [$from]], array_merge($plan, ['perMappingProgress' => ['c1' => 'done']])],
			'duplicate from-cohort members are written once' => [['cohort' => [array_merge($from, ['learnerIds' => ['l1', 'l1', 'l2']])]], $plan],
			'a mapping without an action or a from-cohort id is skipped' => [
				['cohort' => [$from]],
				array_merge($plan, ['mappings' => [['fromCohortId' => 'c1', 'toCohortName' => 'MV2A'], array_merge($mapping, ['fromCohortId' => ''])]]),
			],
			'a missing from-cohort is skipped and the next mapping runs' => [
				['cohort' => [$from]],
				array_merge($plan, ['mappings' => [array_merge($mapping, ['fromCohortId' => 'c-gone']), ['fromCohortId' => 'c-gone-too', 'action' => 'dissolve'], $mapping]]),
			],
		];
	}//end scenarios()

	/**
	 * Every scenario's calls and result equal the recorded golden.
	 *
	 * @return void
	 */
	public function testEveryScenarioMakesTheRecordedCalls(): void {
		$actual = [];
		foreach (self::scenarios() as $name => [$rows, $plan]) {
			$actual[$name] = $this->callsFor($rows, $plan);
		}

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n";
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		$this->assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testEveryScenarioMakesTheRecordedCalls()

	/**
	 * Run execute() over one store and return the calls it made and its result.
	 *
	 * @param array<string, list<array<string, mixed>>> $rows The stored rows per schema.
	 * @param array<string, mixed>                      $plan The plan.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function callsFor(array $rows, array $plan): array {
		$calls   = [];
		$service = $this->createMock(ObjectService::class);
		$service->method('find')->willReturnCallback(
			static function (...$args) use ($rows, &$calls) {
				$named   = FindCallShape::named(args: $args);
				$calls[] = ['find' => $named];
				foreach (($rows['cohort'] ?? []) as $row) {
					if ($row['id'] === $named['id']) {
						return OrEntityFactory::make($row, 'cohort');
					}
				}

				throw new DoesNotExistException('Object not found');
			}
		);
		$service->method('findAll')->willReturnCallback(
			static function (array $config) use ($rows, &$calls): array {
				$calls[] = ['findAll' => $config];
				$found   = [];
				foreach (($rows[$config['filters']['schema']] ?? []) as $row) {
					if (self::rowMatches($row, $config['filters']) === true) {
						$found[] = $row;
					}
				}

				return array_slice($found, 0, (int)($config['limit'] ?? count($found)));
			}
		);
		$service->method('saveObject')->willReturnCallback(
			static function (...$args) use (&$calls) {
				$named = self::named('saveObject', $args);
				// An entity's @self block follows the loaded OpenRegister version, so it is not pinned.
				unset($named['object']['@self']);
				$calls[] = ['saveObject' => $named];
				return OrEntityFactory::make(array_merge(['id' => 'saved-' . count($calls)], $named['object']), (string)$named['schema']);
			}
		);

		$execution = new RolloverExecutionService(
			$service,
			$this->createMock(IGroupManager::class),
			$this->createMock(LoggerInterface::class),
			new RolloverService($service),
			$this->createMock(IntegriqExchangeClient::class)
		);
		$calls[]   = ['result' => $execution->execute($plan)];
		return $calls;
	}//end callsFor()

	/**
	 * A mock call's positional arguments keyed by the loaded ObjectService's parameter names.
	 *
	 * @param string      $method The ObjectService method.
	 * @param list<mixed> $args   The positional arguments.
	 *
	 * @return array<string, mixed>
	 */
	private static function named(string $method, array $args): array {
		$params = (new ReflectionMethod(ObjectService::class, $method))->getParameters();
		$named  = [];
		foreach ($args as $i => $value) {
			if ($params[$i]->isDefaultValueAvailable() === true && $params[$i]->getDefaultValue() === $value) {
				continue;
			}

			$named[$params[$i]->getName()] = $value;
		}

		return $named;
	}//end named()

	/**
	 * Whether a stored row has every filter value except register and schema.
	 *
	 * @param array<string, mixed> $row     The stored row.
	 * @param array<string, mixed> $filters The findAll filters.
	 *
	 * @return bool
	 */
	private static function rowMatches(array $row, array $filters): bool {
		foreach (array_diff_key($filters, ['register' => true, 'schema' => true]) as $key => $value) {
			if (($row[$key] ?? null) !== $value) {
				return false;
			}
		}

		return true;
	}//end rowMatches()
}//end class
