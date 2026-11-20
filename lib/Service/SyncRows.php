<?php

/**
 * Learniq SyncRows
 *
 * Reads and saves rows of the learniq register for a server-side sync, which
 * acts for the school and not for a signed-in user, so RBAC and multitenancy
 * are off. A row comes back as an array with its id under `id`.
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
 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/specs/enrolment/spec.md#requirement-classes-from-the-student-administration-land-as-cohorts
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;

/**
 * The learniq rows a server-side sync reads and writes.
 *
 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/specs/enrolment/spec.md#requirement-classes-from-the-student-administration-land-as-cohorts
 */
class SyncRows {

	/**
	 * The register the rows live in.
	 */
	private const REGISTER = 'learniq';

	/**
	 * The most rows one read returns.
	 */
	private const READ_LIMIT = 5000;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The first row matching the filters, read without RBAC.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/specs/enrolment/spec.md#requirement-classes-from-the-student-administration-land-as-cohorts
	 */
	public function first(string $schema, array $filters): ?array {
		$rows = $this->read(schema: $schema, filters: $filters, limit: 1);

		return ($rows[0] ?? null);
	}//end first()

	/**
	 * Every row matching the filters, read without RBAC.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/specs/enrolment/spec.md#requirement-classes-from-the-student-administration-land-as-cohorts
	 */
	public function all(string $schema, array $filters): array {
		return $this->read(schema: $schema, filters: $filters, limit: self::READ_LIMIT);
	}//end all()

	/**
	 * Write a row as the system and return it as saved.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $row    The row.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/specs/enrolment/spec.md#requirement-classes-from-the-student-administration-land-as-cohorts
	 */
	public function save(string $schema, array $row): array {
		$uuid = $this->idOf(row: $row);
		if ($uuid === '') {
			$uuid = null;
		}

		unset($row['id'], $row['@self']);
		$saved = $this->objectService->saveObject(
			object: $row,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER, schema: $schema), uuid: $uuid),
			_rbac: false,
			_multitenancy: false
		);

		return array_merge($row, $this->toArray(row: $saved));
	}//end save()

	/**
	 * The id of a row: `id`, `@self.id` or `uuid`.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/specs/enrolment/spec.md#requirement-classes-from-the-student-administration-land-as-cohorts
	 */
	public function idOf(array $row): string {
		foreach ([$row['id'] ?? null, $row['@self']['id'] ?? null, $row['uuid'] ?? null] as $candidate) {
			if (is_scalar($candidate) === true && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return '';
	}//end idOf()

	/**
	 * Rows matching the filters as arrays, read without RBAC.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 * @param int                  $limit   The most rows to read.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function read(string $schema, array $filters, int $limit): array {
		$rows = $this->objectService->findAll(
			['filters' => array_merge($filters, ['register' => self::REGISTER, 'schema' => $schema]), 'limit' => $limit],
			false,
			false
		);

		$arrays = [];
		foreach ((array)$rows as $row) {
			$arrays[] = $this->toArray(row: $row);
		}

		return $arrays;
	}//end read()

	/**
	 * A row as an array.
	 *
	 * @param mixed $row An array or an entity.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $row): array {
		$data = [];
		if (is_array($row) === true) {
			$data = $row;
		} else if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$data = (array)$row->jsonSerialize();
		}

		// OpenRegister serves the id under `@self`; the sync keeps it as `id`
		// and never writes the metadata block back.
		$id = $this->idOf(row: $data);
		unset($data['@self']);
		if ($id !== '') {
			$data['id'] = $id;
		}

		return $data;
	}//end toArray()
}//end class
