<?php

/**
 * Tests for the MoveInstanceIdTenantToDefaultTenant repair step.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-29-fix-cross-tenant-idor-planid-lookups/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use OCA\Learniq\Repair\MoveInstanceIdTenantToDefaultTenant;
use OCA\Learniq\Service\CallerTenantResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The repair moves only instance-id rows, pages correctly, and is idempotent.
 */
class MoveInstanceIdTenantToDefaultTenantTest extends TestCase {

	private const INSTANCE = 'ocuhb9wy3beh';

	/**
	 * Rows by schema, as the store holds them.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Every save: [schema, uuid, tenant_id, rbac, multitenancy].
	 *
	 * @var array<int, array{0: string, 1: string, 2: mixed, 3: bool, 4: bool}>
	 */
	private array $saves = [];

	/**
	 * Uuids whose save throws.
	 *
	 * @var array<int, string>
	 */
	private array $failing = [];

	/**
	 * Whether findAll ignores the tenant_id filter (returns every row of the schema).
	 *
	 * @var bool
	 */
	private bool $ignoreFilter = false;

	/**
	 * Build the step over the in-memory store.
	 *
	 * @param string $instanceId The instance id the config reports.
	 *
	 * @return MoveInstanceIdTenantToDefaultTenant
	 */
	private function step(string $instanceId = self::INSTANCE): MoveInstanceIdTenantToDefaultTenant {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				self::assertFalse($_rbac, 'reads with RBAC off');
				self::assertFalse($_multitenancy, 'reads with multitenancy off');
				$filters = $config['filters'];
				$rows    = array_values(
					array_filter(
						($this->rows[$filters['schema']] ?? []),
						fn (array $row): bool => $this->ignoreFilter === true || ($row['tenant_id'] ?? null) === $filters['tenant_id']
					)
				);
				$rows    = array_slice($rows, (int)$config['offset'], (int)$config['limit']);

				return array_map(
					function (array $row): ObjectEntity {
						$entity = $this->createMock(ObjectEntity::class);
						$entity->method('jsonSerialize')->willReturn($row);
						return $entity;
					},
					$rows
				);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				if (in_array($uuid, $this->failing, true) === true) {
					throw new RuntimeException('guard refused');
				}

				$this->saves[] = [(string)$schema, (string)$uuid, $object['tenant_id'] ?? null, $_rbac, $_multitenancy];
				foreach ($this->rows[(string)$schema] as $i => $row) {
					if ($row['id'] === $uuid) {
						$this->rows[(string)$schema][$i] = $object;
					}
				}

				return $this->createMock(ObjectEntity::class);
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, mixed $default = ''): mixed => ($key === 'instanceid' ? $instanceId : $default)
		);

		return new MoveInstanceIdTenantToDefaultTenant(objectService: $objects, config: $config, logger: new NullLogger());
	}//end step()

	/**
	 * Only instance-id rows move, to the default tenant, with RBAC and multitenancy off.
	 *
	 * @return void
	 */
	public function testOnlyInstanceIdRowsMove(): void {
		$this->rows = [
			'work-group'  => [
				['id' => 'wg-1', 'tenant_id' => self::INSTANCE, 'name' => 'Group A'],
				['id' => 'wg-2', 'tenant_id' => '00000000-0000-0000-0000-000000000001', 'name' => 'Seeded'],
				['id' => 'wg-3', 'tenant_id' => 'tenant-school-b', 'name' => 'Bound tenant'],
			],
			'grade-entry' => [['id' => 'ge-1', 'tenant_id' => self::INSTANCE]],
		];

		$this->step()->run($this->createMock(IOutput::class));

		self::assertSame(
			[
				['grade-entry', 'ge-1', CallerTenantResolver::DEFAULT_TENANT, false, false],
				['work-group', 'wg-1', CallerTenantResolver::DEFAULT_TENANT, false, false],
			],
			$this->sortedSaves()
		);
		self::assertSame('Group A', $this->rows['work-group'][0]['name'], 'the rest of the row is kept');
	}//end testOnlyInstanceIdRowsMove()

	/**
	 * A second run finds nothing to move.
	 *
	 * @return void
	 */
	public function testASecondRunMovesNothing(): void {
		$this->rows = ['signature' => [['id' => 's-1', 'tenant_id' => self::INSTANCE]]];

		$this->step()->run($this->createMock(IOutput::class));
		self::assertCount(1, $this->saves);

		$this->saves = [];
		$this->step()->run($this->createMock(IOutput::class));
		self::assertSame([], $this->saves);
	}//end testASecondRunMovesNothing()

	/**
	 * More than a page of rows, a failing save in between, and a store that
	 * ignores the filter: every instance-id row is still moved once, and
	 * nothing else is written.
	 *
	 * @return void
	 */
	public function testPagingSurvivesFailuresAndAnIgnoredFilter(): void {
		$rows = [];
		for ($i = 0; $i < 450; $i++) {
			$tenant = self::INSTANCE;
			if ($i % 3 === 0) {
				$tenant = 'tenant-school-b';
			}

			$rows[] = ['id' => 'lp-' . $i, 'tenant_id' => $tenant];
		}

		$this->rows         = ['learning-plan' => $rows];
		$this->failing      = ['lp-1'];
		$this->ignoreFilter = true;

		$this->step()->run($this->createMock(IOutput::class));

		$moved = array_column($this->saves, 1);
		self::assertCount(299, $moved, '300 instance-id rows, one of which fails');
		self::assertSame($moved, array_values(array_unique($moved)), 'no row is written twice');
		self::assertNotContains('lp-0', $moved, 'a row in another tenant is never written');
		self::assertNotContains('lp-1', $moved);
	}//end testPagingSurvivesFailuresAndAnIgnoredFilter()

	/**
	 * Without an instance id there is nothing to move and nothing is read.
	 *
	 * @return void
	 */
	public function testNoInstanceIdIsANoOp(): void {
		$this->rows = ['work-group' => [['id' => 'wg-1', 'tenant_id' => '']]];

		$this->step(instanceId: '')->run($this->createMock(IOutput::class));

		self::assertSame([], $this->saves);
	}//end testNoInstanceIdIsANoOp()

	/**
	 * The schema list is exactly the register's schemas whose tenant_id has no
	 * uuid format (the only ones that can hold an instance id), and the step is
	 * registered after the register import.
	 *
	 * @return void
	 */
	public function testTheSchemaListMatchesTheRegisterAndTheStepIsRegistered(): void {
		$root     = dirname(__DIR__, 3);
		$register = json_decode((string)file_get_contents($root . '/lib/Settings/learniq_register.json'), true);
		$expected = [];
		foreach ($register['components']['schemas'] as $schema) {
			$tenant = ($schema['properties']['tenant_id'] ?? null);
			if ($tenant !== null && isset($tenant['format']) === false && isset($tenant['pattern']) === false) {
				$expected[] = $schema['slug'];
			}
		}

		sort($expected);
		self::assertSame($expected, MoveInstanceIdTenantToDefaultTenant::SCHEMAS);

		$info  = (string)file_get_contents($root . '/appinfo/info.xml');
		$init  = strpos($info, '<step>OCA\Learniq\Repair\InitializeSettings</step>');
		$step  = strpos($info, '<step>OCA\Learniq\Repair\MoveInstanceIdTenantToDefaultTenant</step>');
		self::assertNotFalse($init);
		self::assertNotFalse($step);
		self::assertGreaterThan($init, $step, 'runs after the register import');
	}//end testTheSchemaListMatchesTheRegisterAndTheStepIsRegistered()

	/**
	 * The saves, sorted by schema.
	 *
	 * @return array<int, array{0: string, 1: string, 2: mixed, 3: bool, 4: bool}>
	 */
	private function sortedSaves(): array {
		$saves = $this->saves;
		usort($saves, static fn (array $a, array $b): int => strcmp($a[0] . $a[1], $b[0] . $b[1]));
		return $saves;
	}//end sortedSaves()
}//end class
