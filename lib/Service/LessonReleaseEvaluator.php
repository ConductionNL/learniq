<?php

/**
 * Learniq Lesson Release Evaluator
 *
 * Stateless service resolving, per (item, learner) pair, whether a Lesson or
 * Assessment is available to a specific learner right now — the "adaptive
 * release" / "drip scheduling" gate the adaptive-release-and-prerequisites
 * change adds.
 *
 * ADR-031 legitimate exception: "Calculation engine above schema metadata,"
 * the same shape as GradeVisibilityResolver/EnrolmentProgressEvaluator. Every
 * `x-openregister-calculations` expression in this register resolves only
 * `@self` (the object's own fields), `@aggregate.*` (a cross-schema
 * aggregate pre-filtered by `@self.id`), or `@ref.*` (a single foreign-key
 * lookup) — none of these carry a "who is asking" token. `Lesson.
 * availableAfterDays` (a duration) is safely materialisable because it is the
 * same number for every learner; the RESOLVED per-learner instant
 * (`enrolment.created + N days`) is not, because it differs per learner
 * sharing the same Lesson/Assessment row. This evaluator does that PHP-side
 * resolution at request time, the same way XapiCompletionHandler already
 * resolves other per-learner facts by querying directly.
 *
 * Consumed by:
 *   - LessonReleaseController::status() / assessmentStatus()
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
 * @spec openspec/specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions
 * @spec openspec/specs/course-management/spec.md#requirement-lesson-supports-drip-release-relative-to-each-learners-own-enrolment-date
 * @spec openspec/specs/assessment/spec.md#requirement-assessment-declares-per-learner-release-conditions
 * @spec openspec/specs/assessment/spec.md#requirement-assessment-supports-drip-release-relative-to-each-learners-own-enrolment-date
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Evaluates release-gating for a single (Lesson|Assessment, learner) pair.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions
 */
class LessonReleaseEvaluator {

	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * Schema slug this evaluator recognises as "Assessment" — used to decide
	 * whether the materialised absolute `isAvailable` window applies.
	 *
	 * @var string
	 */
	public const ASSESSMENT_SCHEMA = 'exam';

	private const LESSON_SCHEMA = 'lesson';
	private const XAPI_SCHEMA = 'xapi-statement';
	private const ASSESSMENT_RESULT_SCHEMA = 'assessment-result';

	private const CONDITION_LESSON_COMPLETED = 'lesson-completed';
	private const CONDITION_ASSESSMENT_MIN_SCORE = 'assessment-min-score';

	private const GRADED_STATE = 'graded';

	/**
	 * XAPI verb IRIs that indicate successful completion. Deliberately
	 * duplicated from XapiCompletionHandler::COMPLETION_VERBS (a private
	 * constant, not accessible cross-class in PHP), mirroring
	 * LessonProgressHandler's own duplication of the same list.
	 *
	 * @var string[]
	 */
	private const COMPLETION_VERBS = [
		'http://adlnet.gov/expapi/verbs/completed',
		'http://adlnet.gov/expapi/verbs/passed',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param AssessmentAccessPolicy $accessPolicy Live availability-window rules.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly AssessmentAccessPolicy $accessPolicy = new AssessmentAccessPolicy(),
	) {
	}//end __construct()

	/**
	 * Evaluate release-gating for one Lesson or Assessment, for one learner.
	 *
	 * Checks, in order: (1) the item's own absolute availability window
	 * (Assessment's materialised `isAvailable` only — Lesson has none); (2)
	 * `availableAfterDays` drip delay, relative to the learner's OWN
	 * `Enrolment.created`; (3) each `releaseConditions` entry, AND-combined.
	 * Returns the first unmet reason found, or `available: true`.
	 *
	 * @param array<string, mixed> $item The Lesson or Assessment row (as returned by ObjectService).
	 * @param string $itemSchema 'lesson' or 'exam'.
	 * @param string $learnerId NC user ID of the requesting learner.
	 * @param array<string, mixed> $enrolment The learner's own Enrolment row for the item's course, or `[]`
	 *                                        when none is resolvable (e.g. an admin/teacher previewing
	 *                                        without holding a personal Enrolment) — the drip gate is
	 *                                        then skipped (no per-learner reference date exists to gate
	 *                                        against).
	 *
	 * @return array{available: bool, reason: string|null, availableAt: string|null}
	 *
	 * @spec openspec/specs/course-management/spec.md#scenario-a-lesson-is-unavailable-until-its-prerequisite-lesson-is-completed
	 * @spec openspec/specs/course-management/spec.md#scenario-a-lesson-is-locked-until-n-days-after-the-learners-own-enrolment-date
	 */
	public function evaluate(array $item, string $itemSchema, string $learnerId, array $enrolment): array {
		$tenantId = (string)($item['tenant_id'] ?? '');
		$now = new DateTimeImmutable();

		if ($itemSchema === self::ASSESSMENT_SCHEMA) {
			$windowReason = $this->evaluateAbsoluteWindow(item: $item, now: $now);
			if ($windowReason !== null) {
				return [
					'available' => false,
					'reason' => $windowReason,
					'availableAt' => null,
				];
			}
		}

		$drip = $this->evaluateDrip(item: $item, enrolment: $enrolment, now: $now);
		if ($drip['blocked'] === true) {
			return [
				'available' => false,
				'reason' => $drip['reason'],
				'availableAt' => $drip['availableAt'],
			];
		}

		$releaseConditions = $item['releaseConditions'] ?? [];
		if (is_array($releaseConditions) === true) {
			foreach ($releaseConditions as $condition) {
				if (is_array($condition) === false) {
					continue;
				}

				$result = $this->evaluateCondition(condition: $condition, learnerId: $learnerId, tenantId: $tenantId);
				if ($result['blocked'] === true) {
					return [
						'available' => false,
						'reason' => $result['reason'],
						'availableAt' => null,
					];
				}
			}
		}

		return [
			'available' => true,
			'reason' => null,
			'availableAt' => null,
		];

	}//end evaluate()

	/**
	 * Check the item's absolute availability window, evaluated live from
	 * availableFrom/availableUntil by AssessmentAccessPolicy (the stored
	 * `isAvailable` goes stale the moment the window opens or closes, so it is
	 * only a fallback for a row with neither date).
	 *
	 * @param array<string, mixed> $item The Assessment row.
	 * @param DateTimeInterface $now Evaluation instant.
	 *
	 * @return string|null A block reason, or null when the window is open/absent.
	 */
	private function evaluateAbsoluteWindow(array $item, DateTimeInterface $now): ?string {
		$block = $this->accessPolicy->windowBlock(assessment: $item, now: $now);
		if ($block === null) {
			return null;
		}

		return $block['message'];
	}//end evaluateAbsoluteWindow()

	/**
	 * Check the `availableAfterDays` drip delay against the learner's own
	 * Enrolment.created.
	 *
	 * @param array<string, mixed> $item The Lesson or Assessment row.
	 * @param array<string, mixed> $enrolment The learner's own Enrolment row, or `[]`.
	 * @param DateTimeInterface $now Evaluation instant.
	 *
	 * @return array{blocked: bool, reason: string|null, availableAt: string|null}
	 */
	private function evaluateDrip(array $item, array $enrolment, DateTimeInterface $now): array {
		$availableAfterDays = ($item['availableAfterDays'] ?? null);
		if (is_numeric($availableAfterDays) === false) {
			return $this->dripNotBlocked();
		}

		$days = (int)$availableAfterDays;
		if ($days < 0) {
			return $this->dripNotBlocked();
		}

		$createdRaw = $enrolment['@self']['created'] ?? null;
		if (is_string($createdRaw) === false || $createdRaw === '') {
			// No per-learner reference date resolvable — the drip gate
			// cannot apply (see class docblock / evaluate()'s $enrolment doc).
			return $this->dripNotBlocked();
		}

		try {
			$createdAt = new DateTimeImmutable($createdRaw);
		} catch (Exception $exception) {
			return $this->dripNotBlocked();
		}

		$availableAt = $createdAt->add(new DateInterval('P' . $days . 'D'));
		if ($now < $availableAt) {
			return [
				'blocked' => true,
				'reason' => sprintf('Available from %s.', $availableAt->format(DateTimeInterface::ATOM)),
				'availableAt' => $availableAt->format(DateTimeInterface::ATOM),
			];
		}

		return $this->dripNotBlocked();
	}//end evaluateDrip()

	/**
	 * The "not blocked" drip result shape, factored out to avoid repetition.
	 *
	 * @return array{blocked: bool, reason: string|null, availableAt: string|null}
	 */
	private function dripNotBlocked(): array {
		return [
			'blocked' => false,
			'reason' => null,
			'availableAt' => null,
		];

	}//end dripNotBlocked()

	/**
	 * Dispatch a single `releaseConditions` entry to its kind-specific check.
	 *
	 * @param array<string, mixed> $condition One `releaseConditions` entry.
	 * @param string $learnerId NC user ID of the requesting learner.
	 * @param string $tenantId Tenant scope for the lookup ('' when unknown).
	 *
	 * @return array{blocked: bool, reason: string|null}
	 */
	private function evaluateCondition(array $condition, string $learnerId, string $tenantId): array {
		$kind = (string)($condition['kind'] ?? '');

		if ($kind === self::CONDITION_LESSON_COMPLETED) {
			return $this->evaluateLessonCompletedCondition(condition: $condition, learnerId: $learnerId, tenantId: $tenantId);
		}

		if ($kind === self::CONDITION_ASSESSMENT_MIN_SCORE) {
			return $this->evaluateAssessmentMinScoreCondition(condition: $condition, learnerId: $learnerId, tenantId: $tenantId);
		}

		// Unrecognised/malformed condition kind — a schema-authoring error,
		// not a real gate. Skip rather than block.
		return ['blocked' => false, 'reason' => null];
	}//end evaluateCondition()

	/**
	 * `lesson-completed`: satisfied by an XapiStatement for the referenced
	 * Lesson whose `verified_actor_id` matches the learner and whose verb
	 * indicates completion or passing.
	 *
	 * @param array<string, mixed> $condition The `releaseConditions` entry.
	 * @param string $learnerId NC user ID of the requesting learner.
	 * @param string $tenantId Tenant scope for the lookup ('' when unknown).
	 *
	 * @return array{blocked: bool, reason: string|null}
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions
	 */
	private function evaluateLessonCompletedCondition(array $condition, string $learnerId, string $tenantId): array {
		$lessonId = (string)($condition['lessonId'] ?? '');
		if ($lessonId === '') {
			return ['blocked' => false, 'reason' => null];
		}

		if ($this->hasCompleted(lessonId: $lessonId, learnerId: $learnerId, tenantId: $tenantId) === true) {
			return ['blocked' => false, 'reason' => null];
		}

		$lessonName = ($this->resolveName(id: $lessonId, schema: self::LESSON_SCHEMA) ?? $lessonId);

		return [
			'blocked' => true,
			'reason' => sprintf('Complete "%s" first.', $lessonName),
		];

	}//end evaluateLessonCompletedCondition()

	/**
	 * `assessment-min-score`: satisfied by a `graded` AssessmentResult for
	 * the referenced Assessment and the evaluating learner whose summed item
	 * scores meet or exceed `minScore`. Computed directly from
	 * `responses[].autoScore ?? responses[].manualScore` — deliberately NOT
	 * via `GradeEntry.value` (see design.md "Rejected Alternatives"). When
	 * multiple graded attempts exist, the BEST (highest) summed score is
	 * used — "has the learner in fact scored enough on any attempt."
	 *
	 * @param array<string, mixed> $condition The `releaseConditions` entry.
	 * @param string $learnerId NC user ID of the requesting learner.
	 * @param string $tenantId Tenant scope for the lookup ('' when unknown).
	 *
	 * @return array{blocked: bool, reason: string|null}
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-assessment-declares-per-learner-release-conditions
	 */
	private function evaluateAssessmentMinScoreCondition(array $condition, string $learnerId, string $tenantId): array {
		$assessmentId = (string)($condition['assessmentId'] ?? '');
		$minScore = ($condition['minScore'] ?? null);
		if ($assessmentId === '' || is_numeric($minScore) === false) {
			return ['blocked' => false, 'reason' => null];
		}

		$bestScore = $this->bestScore(assessmentId: $assessmentId, learnerId: $learnerId, tenantId: $tenantId);
		if ($bestScore !== null && $bestScore >= (float)$minScore) {
			return ['blocked' => false, 'reason' => null];
		}

		$assessmentName = ($this->resolveName(id: $assessmentId, schema: self::ASSESSMENT_SCHEMA) ?? $assessmentId);

		return [
			'blocked' => true,
			'reason' => sprintf('Score at least %s on "%s" first.', $minScore, $assessmentName),
		];

	}//end evaluateAssessmentMinScoreCondition()

	/**
	 * Whether the learner has an xAPI statement completing or passing the
	 * lesson: read for that learner only (`verified_actor_id`).
	 *
	 * @param string $lessonId  UUID of the lesson.
	 * @param string $learnerId NC user ID of the learner.
	 * @param string $tenantId  Tenant scope for the lookup ('' when unknown).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
	 */
	public function hasCompleted(string $lessonId, string $learnerId, string $tenantId): bool {
		$filters = [
			'lessonId' => $lessonId,
			'verified_actor_id' => $learnerId,
		];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$statements = $this->objectService->findAll(
			[
				'filters' => array_merge(
					$filters,
					[
						'register' => self::LEARNIQ_REGISTER,
						'schema' => self::XAPI_SCHEMA,
					]
				),
			]
		);

		foreach ($statements as $statement) {
			$data = $this->toArray(object: $statement);
			$verbId = ($data['verb']['id'] ?? '');
			if (in_array($verbId, self::COMPLETION_VERBS, true) === true) {
				return true;
			}
		}

		return false;
	}//end hasCompleted()

	/**
	 * The learner's best summed score over their graded attempts on the
	 * assessment, or null without a graded attempt: read for that learner
	 * only (`learnerId`).
	 *
	 * @param string $assessmentId UUID of the assessment.
	 * @param string $learnerId    NC user ID of the learner.
	 * @param string $tenantId     Tenant scope for the lookup ('' when unknown).
	 *
	 * @return float|null
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-assessment-declares-per-learner-release-conditions
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
	 */
	public function bestScore(string $assessmentId, string $learnerId, string $tenantId): ?float {
		$filters = [
			'assessmentId' => $assessmentId,
			'learnerId' => $learnerId,
			'lifecycle' => self::GRADED_STATE,
		];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$results = $this->objectService->findAll(
			[
				'filters' => array_merge(
					$filters,
					[
						'register' => self::LEARNIQ_REGISTER,
						'schema' => self::ASSESSMENT_RESULT_SCHEMA,
					]
				),
			]
		);

		$bestScore = null;
		foreach ($results as $result) {
			$data = $this->toArray(object: $result);
			$sumScore = $this->sumResponses(responses: ($data['responses'] ?? []));
			$bestScore = max(($bestScore ?? $sumScore), $sumScore);
		}

		return $bestScore;
	}//end bestScore()

	/**
	 * Sum an AssessmentResult's per-item scores, preferring `autoScore` and
	 * falling back to `manualScore`; a response with neither contributes 0.
	 *
	 * @param mixed $responses The AssessmentResult's `responses` array.
	 *
	 * @return float
	 */
	private function sumResponses($responses): float {
		if (is_array($responses) === false) {
			return 0.0;
		}

		$sum = 0.0;
		foreach ($responses as $response) {
			if (is_array($response) === false) {
				continue;
			}

			$auto = ($response['autoScore'] ?? null);
			$manual = ($response['manualScore'] ?? null);
			if (is_numeric($auto) === true) {
				$sum += (float)$auto;
			} elseif (is_numeric($manual) === true) {
				$sum += (float)$manual;
			}
		}

		return $sum;
	}//end sumResponses()

	/**
	 * Resolve a Lesson/Assessment's display name (`name` or `title`) by id.
	 *
	 * @param string $id UUID of the object.
	 * @param string $schema Schema slug.
	 *
	 * @return string|null
	 */
	private function resolveName(string $id, string $schema): ?string {
		$object = $this->objectService->find(id: $id, register: self::LEARNIQ_REGISTER, schema: $schema);
		if ($object === null) {
			return null;
		}

		$data = $this->toArray(object: $object);
		$name = ($data['name'] ?? ($data['title'] ?? null));
		if (is_string($name) === true && $name !== '') {
			return $name;
		}

		return null;
	}//end resolveName()

	/**
	 * Normalise an ObjectService result (array or ObjectEntity) to a plain array.
	 *
	 * @param mixed $object The result row.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray($object): array {
		if (is_array($object) === true) {
			return $object;
		}

		return $object->jsonSerialize();
	}//end toArray()
}//end class
