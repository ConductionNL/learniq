<?php

/**
 * Learniq Credential Migration Lookup
 *
 * Finds the course or regulation a migrated certificate is for, and an
 * earlier migration of the same certificate.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/credentials-migrate-with-validity/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Support\ResultArrays;
use OCA\Learniq\Support\RowId;
use OCA\OpenRegister\Service\ObjectService;

/**
 * The register lookups of a credential migration.
 *
 * @spec openspec/changes/credentials-migrate-with-validity/tasks.md
 */
final class CredentialMigrationLookup {

	/**
	 * The register.
	 */
	private const REGISTER = 'learniq';

	/**
	 * The credential schema.
	 */
	private const CREDENTIAL_SCHEMA = 'credential';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads courses, regulations and credentials.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The course or regulation the certificate is for, or null when neither is known.
	 *
	 * @param array<string, mixed> $fields   The row's fields.
	 * @param string               $tenantId The organisation.
	 *
	 * @return array{courseId: string|null, regulationSlug: string|null}|null
	 *
	 * @spec openspec/changes/credentials-migrate-with-validity/tasks.md
	 */
	public function target(array $fields, string $tenantId): ?array {
		if ($fields['course'] !== '') {
			return $this->courseTarget(code: $fields['course'], tenantId: $tenantId);
		}

		if ($this->first(schema: 'regulation', filters: ['slug' => $fields['regulation'], 'tenant_id' => $tenantId]) === null) {
			return null;
		}

		return ['courseId' => null, 'regulationSlug' => $fields['regulation']];
	}//end target()

	/**
	 * The course with this code and its regulation, or null when the organisation has no such course.
	 *
	 * @param string $code     The course code.
	 * @param string $tenantId The organisation.
	 *
	 * @return array{courseId: string, regulationSlug: string|null}|null
	 */
	private function courseTarget(string $code, string $tenantId): ?array {
		$course = $this->first(schema: 'course', filters: ['code' => $code, 'tenant_id' => $tenantId]);
		if ($course === null) {
			return null;
		}

		$regulation = ($course['regulationSlug'] ?? null);
		if (is_string($regulation) === false || $regulation === '') {
			$regulation = null;
		}

		return ['courseId' => (new RowId())->idOf(row: $course), 'regulationSlug' => $regulation];
	}//end courseTarget()

	/**
	 * Whether a migrated certificate with this learner, course or regulation and old number exists.
	 *
	 * @param array<string, mixed> $record The record to write.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/credentials-migrate-with-validity/tasks.md
	 */
	public function alreadyMigrated(array $record): bool {
		$filters = [
			'learnerId' => $record['learnerId'],
			'legacyNumber' => $record['legacyNumber'],
			'source' => 'migrated',
			'tenant_id' => $record['tenant_id'],
		];
		if ($record['courseId'] !== null) {
			$filters['courseId'] = $record['courseId'];
		} else {
			$filters['regulationSlug'] = $record['regulationSlug'];
		}

		return $this->first(schema: self::CREDENTIAL_SCHEMA, filters: $filters) !== null;
	}//end alreadyMigrated()

	/**
	 * The first object of a schema matching the filters, as an array, or null.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<string, mixed>|null
	 */
	private function first(string $schema, array $filters): ?array {
		$found = $this->objectService->findAll(
			config: ['filters' => array_merge($filters, ['register' => self::REGISTER, 'schema' => $schema]), 'limit' => 1],
			_rbac: false,
			_multitenancy: false
		);

		return ((new ResultArrays())->arraysOf(objects: ($found['results'] ?? $found))[0] ?? null);
	}//end first()
}//end class
