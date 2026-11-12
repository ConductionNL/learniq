<?php

/**
 * Learniq reads of assessment objects, optionally scoped to one tenant.
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
 * @spec openspec/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Reads one learniq object by uuid, or the graded results of an assessment,
 * through the caller's session; scoped to a tenant when one is given.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
 */
final class AssessmentObjectReads {

	private const LEARNIQ_REGISTER = 'learniq';
	private const ASSESSMENT_RESULT_SCHEMA = 'assessment-result';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param string $tenantId Tenant ID, or '' to skip tenant scoping.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly string $tenantId = '',
	) {
	}//end __construct()

	/**
	 * The same reads, scoped to a tenant.
	 *
	 * @param string $tenantId Tenant ID, or '' to skip tenant scoping.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
	 */
	public function inTenant(string $tenantId): self {
		return new self(objectService: $this->objectService, tenantId: $tenantId);
	}//end inTenant()

	/**
	 * A single object by uuid.
	 *
	 * @param string $schema OR schema slug.
	 * @param string $uuid Object UUID.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
	 */
	public function one(string $schema, string $uuid): ?array {
		$matches = $this->objectService->findAll(
			[
				'ids' => [$uuid],
				'filters' => $this->filtersFor(filters: [], schema: $schema),
				'limit' => 1,
			]
		);

		if (empty($matches) === true) {
			return null;
		}

		return self::rowOf(object: $matches[0]);
	}//end one()

	/**
	 * Every `graded` AssessmentResult for an Assessment.
	 *
	 * @param string $assessmentId UUID of the Assessment.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
	 */
	public function gradedResults(string $assessmentId): array {
		$results = $this->objectService->findAll(
			[
				'filters' => $this->filtersFor(
					filters: ['assessmentId' => $assessmentId, 'lifecycle' => 'graded'],
					schema: self::ASSESSMENT_RESULT_SCHEMA
				),
			]
		);

		return array_map(
			static fn ($result): array => self::rowOf(object: $result),
			$results
		);
	}//end gradedResults()

	/**
	 * The filters, the tenant when one is set, then register and schema.
	 *
	 * @param array<string,mixed> $filters The read's own filters.
	 * @param string $schema OR schema slug.
	 *
	 * @return array<string,mixed>
	 */
	private function filtersFor(array $filters, string $schema): array {
		if ($this->tenantId !== '') {
			$filters['tenant_id'] = $this->tenantId;
		}

		return array_merge(
			$filters,
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => $schema,
			]
		);
	}//end filtersFor()

	/**
	 * An OpenRegister row as an array.
	 *
	 * @param mixed $object The row, an array or a serialisable object.
	 *
	 * @return array<string,mixed>
	 */
	private static function rowOf(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		return $object->jsonSerialize();
	}//end rowOf()
}//end class
