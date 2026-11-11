<?php

/**
 * Learniq tenant-scoped lookup by id.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

/**
 * The findAll() config that looks Learniq objects of one schema up by id,
 * scoped to the tenant of the object under transition when it names one
 * (H1: an Assessment or an Item can not be read across a tenant boundary).
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-7
 */
final class TenantScopedQuery {

	/**
	 * OR register slug for Learniq objects.
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param string $schema   The schema slug.
	 * @param string $tenantId Tenant UUID, or '' when unknown.
	 */
	public function __construct(
		private readonly string $schema,
		private readonly string $tenantId,
	) {
	}//end __construct()

	/**
	 * The config for these ids.
	 *
	 * @param array<int,mixed> $ids   The object ids.
	 * @param int              $limit The page size.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-7
	 */
	public function forIds(array $ids, int $limit): array {
		return [
			'ids' => $ids,
			'filters' => $this->filters(),
			'limit' => $limit,
		];
	}//end forIds()

	/**
	 * The register and schema, plus the tenant when it is known.
	 *
	 * @return array<string,string>
	 */
	private function filters(): array {
		$filters = [
			'register' => self::LEARNIQ_REGISTER,
			'schema' => $this->schema,
		];
		if ($this->tenantId !== '') {
			$filters['tenant_id'] = $this->tenantId;
		}

		return $filters;
	}//end filters()
}//end class
