<?php

/**
 * Learniq Assignment Grade Plan
 *
 * Resolves the curriculum plan an assignment is graded under. An Assignment
 * carries no plan of its own: the schema declares no `curriculumPlanId` or
 * `gradeScaleId`, and OpenRegister drops undeclared properties on save. The
 * plan is the course's `curriculumPlanId`, and the grade scale is the plan's,
 * the way the portfolio and werkproces grade emitters resolve it.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-an-agent-grade-is-a-concept-a-teacher-publishes-req-009
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Finds an assignment's curriculum plan through its course, with the caller's rights.
 */
class AssignmentGradePlan {

	/**
	 * The learniq register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access, in the caller's session.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The plan an assignment is graded under, or null when its course has none.
	 *
	 * @param array<string, mixed> $assignment The assignment.
	 *
	 * @return array{id: string, gradeScaleId: string}|null The plan id and its grade scale id ('' for the default scale).
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-an-agent-grade-is-a-concept-a-teacher-publishes-req-009
	 */
	public function planOf(array $assignment): ?array {
		$course = $this->read(schema: 'course', id: (string)($assignment['courseId'] ?? ''));
		$planId = (string)($course['curriculumPlanId'] ?? '');
		$plan   = $this->read(schema: 'curriculum-plan', id: $planId);
		if ($plan === null) {
			return null;
		}

		return ['id' => $planId, 'gradeScaleId' => (string)($plan['gradeScaleId'] ?? '')];
	}//end planOf()

	/**
	 * Read one object with the caller's rights, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The UUID.
	 *
	 * @return array<string, mixed>|null The object.
	 */
	private function read(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema);
		} catch (Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $object->jsonSerialize();
	}//end read()
}//end class
