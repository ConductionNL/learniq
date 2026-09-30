<?php

/**
 * Learniq Next Step Resolver
 *
 * Decides which lesson a learner goes to after a lesson, from the lesson's
 * ordered `nextStepRules` and its `defaultNextLessonId`. The first rule whose
 * `when` holds for the learner wins; with no match the default applies. A
 * rule's `when` speaks the release condition language (design D1):
 * `lesson-completed` and `assessment-min-score`, plus `score-below`, the
 * negation of the latter.
 *
 * Results are only ever read for the learner passed in (the caller), through
 * LessonReleaseEvaluator's own lookups, so a rule cannot branch on another
 * learner's result. In a preview (design D2) a simulated score stands in for
 * every score and a prerequisite lesson counts as completed, so nothing of
 * the author's own records is read or needed.
 *
 * Consumed by:
 *   - LessonNextStepController::nextStep()
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
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Resolves a lesson's next lesson for one learner.
 *
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
 */
class NextStepResolver {

	private const KIND_LESSON_COMPLETED = 'lesson-completed';
	private const KIND_MIN_SCORE = 'assessment-min-score';
	private const KIND_SCORE_BELOW = 'score-below';

	/**
	 * Constructor.
	 *
	 * @param LessonReleaseEvaluator $evaluator The release condition lookups (scores, completions).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LessonReleaseEvaluator $evaluator,
	) {
	}//end __construct()

	/**
	 * The next lesson for this learner after the given lesson.
	 *
	 * @param array<string, mixed> $lesson         The Lesson row.
	 * @param string               $learnerId      The learner whose results count (the caller).
	 * @param float|null           $simulatedScore In a preview, the score the author chose; null outside a preview.
	 * @param bool                 $preview        Whether this is a preview.
	 *
	 * @return array{nextLessonId: string|null, rule: int|null}
	 *
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#scenario-a-learner-who-fails-is-sent-to-a-refresher
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#scenario-a-learner-who-passes-continues
	 */
	public function resolve(array $lesson, string $learnerId, ?float $simulatedScore = null, bool $preview = false): array {
		$tenantId = (string)($lesson['tenant_id'] ?? '');
		$rules = ($lesson['nextStepRules'] ?? []);
		if (is_array($rules) === false) {
			$rules = [];
		}

		foreach (array_values($rules) as $index => $rule) {
			$target = '';
			$when = null;
			if (is_array($rule) === true) {
				$target = (string)($rule['goToLessonId'] ?? '');
				$when = ($rule['when'] ?? null);
			}

			if ($target === '' || is_array($when) === false) {
				continue;
			}

			$context = ['learnerId' => $learnerId, 'tenantId' => $tenantId, 'score' => $simulatedScore, 'preview' => $preview];
			if ($this->holds(when: $when, context: $context) === true) {
				return ['nextLessonId' => $target, 'rule' => $index];
			}
		}

		$default = (string)($lesson['defaultNextLessonId'] ?? '');
		if ($default === '') {
			return ['nextLessonId' => null, 'rule' => null];
		}

		return ['nextLessonId' => $default, 'rule' => null];
	}//end resolve()

	/**
	 * Whether one rule's condition holds.
	 *
	 * @param array<string, mixed>                                                        $when    The rule's condition.
	 * @param array{learnerId: string, tenantId: string, score: float|null, preview: bool} $context Who asks, and the preview facts.
	 *
	 * @return bool
	 */
	private function holds(array $when, array $context): bool {
		$kind = (string)($when['kind'] ?? '');

		if ($kind === self::KIND_LESSON_COMPLETED) {
			$lessonId = (string)($when['lessonId'] ?? '');
			if ($lessonId === '') {
				return false;
			}

			if ($context['preview'] === true) {
				return true;
			}

			return $this->evaluator->hasCompleted(lessonId: $lessonId, learnerId: $context['learnerId'], tenantId: $context['tenantId']);
		}

		if ($kind !== self::KIND_MIN_SCORE && $kind !== self::KIND_SCORE_BELOW) {
			// An unknown kind is an authoring error, never a match.
			return false;
		}

		$threshold = ($when['minScore'] ?? null);
		if ($kind === self::KIND_SCORE_BELOW) {
			$threshold = ($when['belowScore'] ?? null);
		}

		$score = $this->score(assessmentId: (string)($when['assessmentId'] ?? ''), context: $context);
		if ($score === null || is_numeric($threshold) === false) {
			// No graded attempt yet: neither above nor below anything.
			return false;
		}

		if ($kind === self::KIND_MIN_SCORE) {
			return $score >= (float)$threshold;
		}

		return $score < (float)$threshold;
	}//end holds()

	/**
	 * The score a rule compares: the simulated one in a preview, else the
	 * learner's best graded attempt on the assessment.
	 *
	 * @param string                                                                      $assessmentId The assessment the rule names.
	 * @param array{learnerId: string, tenantId: string, score: float|null, preview: bool} $context      Who asks, and the preview facts.
	 *
	 * @return float|null
	 */
	private function score(string $assessmentId, array $context): ?float {
		if ($context['preview'] === true) {
			return $context['score'];
		}

		if ($assessmentId === '') {
			return null;
		}

		return $this->evaluator->bestScore(assessmentId: $assessmentId, learnerId: $context['learnerId'], tenantId: $context['tenantId']);
	}//end score()
}//end class
