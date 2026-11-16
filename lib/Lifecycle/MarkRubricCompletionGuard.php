<?php

/**
 * Learniq Mark Rubric Completion Guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lifecycle guard for Submission's `return` transition (submitted/late ->
 * returned), the step that releases a teacher's mark to the learner. When the
 * Submission's Assignment has a Rubric, the transition is refused while any
 * criterion of that Rubric has no entry in the Submission's `rubricScores`,
 * and the refusal names the unscored criteria by their label.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema declaration";
 * JSON Schema cannot express "every criterionId of a cross-object Rubric is
 * present in this array".
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/assignments/spec.md#requirement-a-teachers-mark-is-not-released-while-a-rubric-criterion-is-unscored
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Guards Submission.return: every rubric criterion is scored before the mark is released.
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/assignments/spec.md#requirement-a-teachers-mark-is-not-released-while-a-rubric-criterion-is-unscored
 */
class MarkRubricCompletionGuard implements LifecycleGuardInterface {

	private const LEARNIQ_REGISTER = 'learniq';
	private const ASSIGNMENT_SCHEMA = 'assignment';
	private const RUBRIC_SCHEMA = 'rubric';

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OR object access service.
	 * @param LoggerInterface $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * OpenRegister lifecycle guard entry-point for `return`.
	 *
	 * @param array<string,mixed> $object The Submission as it would be saved.
	 * @param string              $action The transition, `return`.
	 * @param string              $userId The caller, or '' without a session.
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/assignments/spec.md#scenario-a-teacher-forgets-one-criterion
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/assignments/spec.md#scenario-every-criterion-is-scored
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$criteria = $this->rubricCriteria(object: $object);
		if ($criteria === null) {
			return GuardResult::deny('The rubric of this assignment cannot be read, so the mark cannot be released.');
		}

		$unscored = $this->unscoredLabels(criteria: $criteria, rubricScores: ($object['rubricScores'] ?? []));
		if ($unscored !== []) {
			$this->logger->info(
				'[MarkRubricCompletionGuard] Submission {id} has unscored rubric criteria; {action} refused.',
				['id' => ($object['id'] ?? ($object['uuid'] ?? '')), 'action' => $action]
			);
			return GuardResult::deny('Score every rubric criterion before releasing the mark. Unscored: '.implode(', ', $unscored).'.');
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * The criteria of the Submission's Assignment's Rubric: an empty list when
	 * the Assignment has no Rubric, null (fail closed) when the Assignment or the
	 * Rubric it names cannot be read.
	 *
	 * @param array<string,mixed> $object The Submission.
	 *
	 * @return array<int,mixed>|null
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/assignments/spec.md#requirement-a-teachers-mark-is-not-released-while-a-rubric-criterion-is-unscored
	 */
	private function rubricCriteria(array $object): ?array {
		$assignmentId = $object['assignmentId'] ?? null;
		if ($this->isId(value: $assignmentId) === false) {
			return [];
		}

		$assignment = $this->fetchObject(id: $assignmentId, schema: self::ASSIGNMENT_SCHEMA);
		if ($assignment === null) {
			return null;
		}

		$rubricId = $assignment['rubricId'] ?? null;
		if ($this->isId(value: $rubricId) === false) {
			return [];
		}

		$rubric = $this->fetchObject(id: $rubricId, schema: self::RUBRIC_SCHEMA);
		if ($rubric === null) {
			return null;
		}

		$criteria = $rubric['criteria'] ?? [];
		if (is_array($criteria) === false) {
			return [];
		}

		return array_values($criteria);
	}//end rubricCriteria()

	/**
	 * The labels (else ids) of the criteria with no entry in rubricScores, in rubric order.
	 *
	 * @param array<int,mixed> $criteria     The Rubric's criteria.
	 * @param mixed            $rubricScores The Submission's rubricScores.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/assignments/spec.md#scenario-a-teacher-forgets-one-criterion
	 */
	private function unscoredLabels(array $criteria, $rubricScores): array {
		$scored = [];
		if (is_array($rubricScores) === true) {
			foreach ($rubricScores as $score) {
				if (is_array($score) === true && isset($score['criterionId']) === true) {
					$scored[] = (string)$score['criterionId'];
				}
			}
		}

		$unscored = [];
		foreach ($criteria as $criterion) {
			if (is_array($criterion) === false || isset($criterion['criterionId']) === false) {
				continue;
			}

			$criterionId = (string)$criterion['criterionId'];
			if (in_array($criterionId, $scored, true) === true) {
				continue;
			}

			$label = $criterion['label'] ?? null;
			if (is_string($label) === false || trim($label) === '') {
				$label = $criterionId;
			}

			$unscored[] = $label;
		}

		return $unscored;
	}//end unscoredLabels()

	/**
	 * Whether a value is a usable id: a non-empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isId($value): bool {
		return is_string($value) === true && $value !== '';
	}//end isId()

	/**
	 * Fetch an object by id/schema as an array, or null when it cannot be read.
	 *
	 * @param string $id     UUID of the object.
	 * @param string $schema Schema slug.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchObject(string $id, string $schema): ?array {
		try {
			$obj = $this->objectService->find(
				id: $id,
				options: new ObjectFindOptions(register: self::LEARNIQ_REGISTER, schema: $schema)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[MarkRubricCompletionGuard] Could not read {schema} {id}: {msg}',
				['schema' => $schema, 'id' => $id, 'msg' => $e->getMessage()]
			);
			return null;
		}

		if ($obj === null) {
			return null;
		}

		return $obj->jsonSerialize();
	}//end fetchObject()
}//end class
