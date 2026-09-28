<?php

/**
 * Learniq Portal Attempt Reader
 *
 * Every OpenRegister read the portal assessment endpoints make.
 *
 * Reads run without RBAC and multitenancy, because a portal request has no
 * Nextcloud session, and filter by the pupil explicitly. The test is read raw
 * because its access code is write-only; items are read raw so nothing but
 * the presenter decides what of them leaves. `register` and `schema` sit
 * under `filters`, the only place ObjectService::prepareFindAllConfig() reads
 * them. Writes live in PortalAttemptWriter.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
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
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Reads for portal attempts, filtered by the pupil.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */
class PortalAttemptReader {

	private const REGISTER = 'learniq';
	private const RESULT_SCHEMA = 'assessment-result';
	private const ASSESSMENT_SCHEMA = 'exam';

	/**
	 * How many rows one list read returns at most.
	 */
	private const LIST_LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * A test, raw (with its write-only access code), or null.
	 *
	 * @param string $id Assessment uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function exam(string $id): ?array {
		return $this->one(id: $id, schema: self::ASSESSMENT_SCHEMA);
	}//end exam()

	/**
	 * An attempt, or null.
	 *
	 * @param string $id AssessmentResult uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function attempt(string $id): ?array {
		return $this->one(id: $id, schema: self::RESULT_SCHEMA);
	}//end attempt()

	/**
	 * An item, raw, or null.
	 *
	 * @param string $id Item uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function item(string $id): ?array {
		return $this->one(id: $id, schema: 'item');
	}//end item()

	/**
	 * A GradeEntry, or null.
	 *
	 * @param string $id GradeEntry uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-result-is-shown-only-once-the-teacher-released-it
	 */
	public function gradeEntry(string $id): ?array {
		return $this->one(id: $id, schema: 'grade-entry');
	}//end gradeEntry()

	/**
	 * The pupil's attempts at one test.
	 *
	 * @param string $examId Assessment uuid.
	 * @param string $ncUserId The pupil's Nextcloud user id.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function attemptsFor(string $examId, string $ncUserId): array {
		return $this->many(schema: self::RESULT_SCHEMA, filters: ['assessmentId' => $examId, 'learnerId' => $ncUserId]);
	}//end attemptsFor()

	/**
	 * The pupil's enrolments.
	 *
	 * @param string $ncUserId The pupil's Nextcloud user id.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function enrolments(string $ncUserId): array {
		return $this->many(schema: 'enrolment', filters: ['learnerId' => $ncUserId]);
	}//end enrolments()

	/**
	 * The published tests of one course or cohort.
	 *
	 * @param string $field `courseId` or `cohortId`.
	 * @param string $value Its uuid.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function publishedExams(string $field, string $value): array {
		return $this->many(schema: self::ASSESSMENT_SCHEMA, filters: [$field => $value, 'lifecycle' => 'published']);
	}//end publishedExams()

	/**
	 * The pupil's extra-time accommodations (the clock picks the granted ones).
	 *
	 * @param string $ncUserId The pupil's Nextcloud user id.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function accommodations(string $ncUserId): array {
		return $this->many(
			schema: 'exam-accommodation',
			filters: ['learnerId' => $ncUserId, 'accommodationKind' => 'extra-time-percentage']
		);
	}//end accommodations()

	/**
	 * One raw row by uuid, or null when it does not exist.
	 *
	 * @param string $id Object uuid.
	 * @param string $schema Schema slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function one(string $id, string $schema): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objectService->find(
				id: $id,
				register: self::REGISTER,
				schema: $schema,
				_rbac: false,
				_multitenancy: false,
				_render: false
			);
		} catch (DoesNotExistException $exception) {
			return null;
		}

		return $object?->jsonSerialize();
	}//end one()

	/**
	 * Rows matching explicit filters.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string, mixed> $filters Property filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function many(string $schema, array $filters): array {
		$rows = $this->objectService->findAll(
			config: [
				'filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters),
				'limit' => self::LIST_LIMIT,
			],
			_rbac: false,
			_multitenancy: false
		);

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
				continue;
			}

			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$out[] = $row->jsonSerialize();
			}
		}

		return $out;
	}//end many()
}//end class
