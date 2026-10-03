<?php

/**
 * Repair step that moves rows stamped with the Nextcloud instance id as their
 * tenant into the default tenant.
 *
 * @category Repair
 * @package  OCA\Learniq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-29-fix-cross-tenant-idor-planid-lookups/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCA\Learniq\Service\CallerTenantResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rewrites `tenant_id` from the instance id to CallerTenantResolver::DEFAULT_TENANT.
 *
 * Until 2026-09-29 an unbound user's tenant fell back to the instance id. It
 * is not a UUID, so only the schemas whose `tenant_id` has no `format: uuid`
 * could store it; those are the ones listed here. Every such row is moved to
 * the default tenant, where the seeded and example rows and every new write
 * now live, so tenant-scoped lookups find them again.
 *
 * Idempotent and narrow: only a row whose `tenant_id` equals the instance id
 * is written, so a second run finds nothing, and a row in any other tenant is
 * never touched. Runs without a session, so every read and write passes
 * `_rbac: false` and `_multitenancy: false`.
 *
 * @spec openspec/changes/archive/2026-09-29-fix-cross-tenant-idor-planid-lookups/tasks.md#task-1
 */
class MoveInstanceIdTenantToDefaultTenant implements IRepairStep {

	/**
	 * The learniq register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'learniq';

	/**
	 * The schemas whose `tenant_id` accepts a non-UUID value, so the only ones
	 * that can hold an instance-id tenant. Pinned against the register by
	 * MoveInstanceIdTenantToDefaultTenantTest.
	 *
	 * @var array<int, string>
	 */
	public const SCHEMAS = [
		'check-in-window',
		'exemption-case',
		'external-training-record',
		'final-grade',
		'fraud-case',
		'grade-entry',
		'grade-scale',
		'group-plan',
		'group-plan-evaluation',
		'group-plan-subgroup',
		'learning-plan',
		'learning-plan-evaluation',
		'learning-plan-template',
		'portfolio-template',
		'rollover-plan',
		'signature',
		'submission-mark',
		'work-group',
		'xapi-document',
	];

	/**
	 * Page size.
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 200;

	/**
	 * Hard stop per schema so a paging defect can never loop forever.
	 *
	 * @var int
	 */
	private const MAX_PAGES = 5000;

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister object access.
	 * @param IConfig         $config        Reads the instance id.
	 * @param LoggerInterface $logger        PSR logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-09-29-fix-cross-tenant-idor-planid-lookups/tasks.md#task-1
	 */
	public function getName(): string {
		return 'Move rows stamped with the instance id as tenant into the default tenant';
	}//end getName()

	/**
	 * Rewrite every instance-id row on the listed schemas.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-29-fix-cross-tenant-idor-planid-lookups/tasks.md#task-1
	 */
	public function run(IOutput $output): void {
		$instanceId = trim((string)$this->config->getSystemValue('instanceid', ''));
		if ($instanceId === '' || $instanceId === CallerTenantResolver::DEFAULT_TENANT) {
			$output->info('MoveInstanceIdTenantToDefaultTenant: no instance id, nothing to move.');
			return;
		}

		$moved  = 0;
		$failed = 0;
		foreach (self::SCHEMAS as $schema) {
			try {
				[$schemaMoved, $schemaFailed] = $this->moveSchema(schema: $schema, instanceId: $instanceId);
			} catch (Throwable $exception) {
				// OpenRegister absent or the schema not imported yet: the next upgrade retries.
				$this->logger->warning(
					'[MoveInstanceIdTenantToDefaultTenant] Skipped {schema}: {msg}',
					['schema' => $schema, 'msg' => $exception->getMessage()]
				);
				continue;
			}

			$moved  += $schemaMoved;
			$failed += $schemaFailed;
		}

		$output->info('MoveInstanceIdTenantToDefaultTenant: ' . $moved . ' moved, ' . $failed . ' failed.');
	}//end run()

	/**
	 * Move one schema's instance-id rows.
	 *
	 * Rows that are moved stop matching the filter, so the offset only grows
	 * by the rows this run leaves where they are (a failed save, or a row the
	 * filter returned that is not an instance-id row). That keeps paging
	 * correct whether or not OpenRegister applies the filter.
	 *
	 * @param string $schema     The schema slug.
	 * @param string $instanceId The instance id.
	 *
	 * @return array{0: int, 1: int} Moved and failed counts.
	 */
	private function moveSchema(string $schema, string $instanceId): array {
		$moved = 0;
		$failed = 0;
		$left = 0;
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$rows = $this->page(schema: $schema, instanceId: $instanceId, offset: $left);
			foreach ($rows as $row) {
				$outcome = $this->moveRow(schema: $schema, row: $row, instanceId: $instanceId);
				if ($outcome === 'moved') {
					$moved++;
					continue;
				}

				$left++;
				if ($outcome === 'failed') {
					$failed++;
				}
			}

			if (count($rows) < self::PAGE_SIZE) {
				break;
			}
		}

		return [$moved, $failed];
	}//end moveSchema()

	/**
	 * Move one row when it carries the instance id.
	 *
	 * @param string               $schema     The schema slug.
	 * @param array<string, mixed> $row        The row.
	 * @param string               $instanceId The instance id.
	 *
	 * @return string `moved`, `failed` or `skipped`.
	 */
	private function moveRow(string $schema, array $row, string $instanceId): string {
		$uuid = (string)($row['id'] ?? ($row['uuid'] ?? ''));
		if (($row['tenant_id'] ?? null) !== $instanceId || $uuid === '') {
			return 'skipped';
		}

		try {
			$this->objectService->saveObject(
				object: array_merge($row, ['tenant_id' => CallerTenantResolver::DEFAULT_TENANT]),
				register: self::REGISTER,
				schema: $schema,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[MoveInstanceIdTenantToDefaultTenant] Could not move {schema} {id}: {msg}',
				['schema' => $schema, 'id' => $uuid, 'msg' => $exception->getMessage()]
			);
			return 'failed';
		}

		return 'moved';
	}//end moveRow()

	/**
	 * One page of instance-id rows as arrays.
	 *
	 * @param string $schema     The schema slug.
	 * @param string $instanceId The instance id.
	 * @param int    $offset     Row offset.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function page(string $schema, string $instanceId, int $offset): array {
		$objects = $this->objectService->findAll(
			config: [
				'filters' => [
					'register'  => self::REGISTER,
					'schema'    => $schema,
					'tenant_id' => $instanceId,
				],
				'limit'   => self::PAGE_SIZE,
				'offset'  => $offset,
			],
			_rbac: false,
			_multitenancy: false
		);

		$rows = [];
		foreach ($objects as $object) {
			if (is_array($object) === true) {
				$rows[] = $object;
				continue;
			}

			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$rows[] = (array)$object->jsonSerialize();
			}
		}

		return $rows;
	}//end page()
}//end class
