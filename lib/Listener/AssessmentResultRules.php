<?php

/**
 * Learniq AssessmentResult rules: what one update of an attempt may change.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

/**
 * The write rules of one AssessmentResult update, over the stored version and
 * the version as it would be saved: of an attempt, or of one of its responses.
 * Built per update by AssessmentResultIntegrityListener, which decides who is
 * policed and whether the caller is staff.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
 */
final class AssessmentResultRules {

	/**
	 * Fields a finished attempt never changes. `learnerId` is absent on purpose:
	 * a learner merge re-points it (LearnerMergeService), and only staff get
	 * past the finished-attempt check at all.
	 */
	private const FROZEN_FIELDS = [
		'assessmentId',
		'attemptNumber',
		'drawnItemRefs',
		'startedAt',
		'submittedAt',
		'proctoringSessionId',
		'tenant_id',
	];

	/**
	 * Refusal for a change to a finished attempt's answers.
	 */
	private const FROZEN = [
		'reason' => 'assessment-result-answers-frozen',
		'message' => 'The answers of a submitted attempt cannot be changed.',
	];

	/**
	 * Refusal for any scoring or state change after grading.
	 */
	private const GRADED = [
		'reason' => 'assessment-result-graded',
		'message' => 'A graded attempt is final.',
	];

	/**
	 * Refusal for a write into someone else's attempt.
	 */
	private const NOT_YOURS = [
		'reason' => 'assessment-result-not-yours',
		'message' => 'Only the learner taking this attempt can change it.',
	];

	/**
	 * Refusal for a learner scoring their own answers.
	 */
	private const SELF_SCORED = [
		'reason' => 'assessment-result-self-scored',
		'message' => 'A learner cannot score their own answers.',
	];

	/**
	 * Refusal for a manual score that is not a number of zero or more.
	 */
	private const INVALID_SCORE = [
		'reason' => 'assessment-result-invalid-score',
		'message' => 'A score must be a number of zero or more.',
	];

	/**
	 * Fields a learner never changes on their own attempt.
	 */
	private const OWNER_FIELDS = ['assessmentId', 'learnerId', 'tenant_id'];

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $old The stored version.
	 * @param array<string,mixed> $new The version as it would be saved.
	 */
	public function __construct(
		private readonly array $old,
		private readonly array $new,
	) {
	}//end __construct()

	/**
	 * The stored lifecycle state, '' when there is none.
	 *
	 * @return string The state.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	public function storedState(): string {
		return (string)($this->old['lifecycle'] ?? '');
	}//end storedState()

	/**
	 * Rules for an attempt the learner is still taking.
	 *
	 * @param string $uid The acting user id.
	 *
	 * @return array{reason: string, message: string}|null The refusal, or null to allow.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	public function inProgressRefusal(string $uid): ?array {
		if ((string)($this->old['learnerId'] ?? '') !== $uid || $this->changed(fields: self::OWNER_FIELDS) === true) {
			return self::NOT_YOURS;
		}

		return $this->selfScoreRefusal();
	}//end inProgressRefusal()

	/**
	 * Refuse a save of an attempt in progress that carries the learner's own score.
	 *
	 * @return array{reason: string, message: string}|null The refusal, or null to allow.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function selfScoreRefusal(): ?array {
		// The submit save is where AssessmentScoringHandler sets autoScores, so
		// only a save that stays in progress must carry no scores at all.
		$submitting = (($this->new['lifecycle'] ?? '') === 'submitted');
		foreach ($this->responses(data: $this->new) as $response) {
			if ($this->isSelfScored(response: $response, submitting: $submitting) === true) {
				return self::SELF_SCORED;
			}
		}

		return null;
	}//end selfScoreRefusal()

	/**
	 * Whether a response of an attempt in progress carries a score.
	 *
	 * @param array<string,mixed> $response   The response as it would be saved.
	 * @param bool                $submitting Whether this save submits the attempt.
	 *
	 * @return bool True when the learner would score their own answer.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function isSelfScored(array $response, bool $submitting): bool {
		return (($response['manualScore'] ?? null) !== null)
			|| ($submitting === false && ($response['autoScore'] ?? null) !== null);
	}//end isSelfScored()

	/**
	 * Rules for a submitted or graded attempt, written by staff.
	 *
	 * @param string $state The stored lifecycle state.
	 *
	 * @return array{reason: string, message: string}|null The refusal, or null to allow.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	public function finishedRefusal(string $state): ?array {
		if ($this->changed(fields: self::FROZEN_FIELDS) === true) {
			return self::FROZEN;
		}

		$block = $this->responsesRefusal(state: $state);
		if ($block !== null) {
			return $block;
		}

		return $this->transitionRefusal(state: $state);
	}//end finishedRefusal()

	/**
	 * Refuse a lifecycle change other than grading a submitted attempt.
	 *
	 * @param string $state The stored lifecycle state.
	 *
	 * @return array{reason: string, message: string}|null The refusal, or null to allow.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function transitionRefusal(string $state): ?array {
		$newState = (string)($this->new['lifecycle'] ?? $state);
		if ($newState !== $state && ($state !== 'submitted' || $newState !== 'graded')) {
			return self::GRADED;
		}

		return null;
	}//end transitionRefusal()

	/**
	 * Refuse a change to the responses of a finished attempt.
	 *
	 * @param string $state The stored lifecycle state.
	 *
	 * @return array{reason: string, message: string}|null The refusal, or null to allow.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function responsesRefusal(string $state): ?array {
		$oldResponses = $this->responses(data: $this->old);
		$newResponses = $this->responses(data: $this->new);
		if (count($oldResponses) !== count($newResponses)) {
			return self::FROZEN;
		}

		foreach ($oldResponses as $index => $oldResponse) {
			$response = new self(old: $oldResponse, new: ($newResponses[$index] ?? []));
			$block    = $response->responseRefusal(state: $state);
			if ($block !== null) {
				return $block;
			}
		}

		return null;
	}//end responsesRefusal()

	/**
	 * Rules for one response of a finished attempt, the two versions being
	 * responses: only manualScore may change, only while submitted, and only
	 * to a number of zero or more.
	 *
	 * @param string $state The stored lifecycle state of the attempt.
	 *
	 * @return array{reason: string, message: string}|null The refusal, or null to allow.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	public function responseRefusal(string $state): ?array {
		$old       = $this->old;
		$new       = $this->new;
		$oldManual = ($old['manualScore'] ?? null);
		$newManual = ($new['manualScore'] ?? null);
		unset($old['manualScore'], $new['manualScore']);

		if ($this->same(left: $old, right: $new) === false) {
			return self::FROZEN;
		}

		if ($this->same(left: $oldManual, right: $newManual) === true) {
			return null;
		}

		return $this->scoreRefusal(state: $state, score: $newManual);
	}//end responseRefusal()

	/**
	 * Refuse a changed manual score after grading, or one that is not a number
	 * of zero or more.
	 *
	 * @param string $state The stored lifecycle state of the attempt.
	 * @param mixed  $score The manual score as it would be saved.
	 *
	 * @return array{reason: string, message: string}|null The refusal, or null to allow.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function scoreRefusal(string $state, mixed $score): ?array {
		if ($state !== 'submitted') {
			return self::GRADED;
		}

		return $this->invalidScoreRefusal(score: $score);
	}//end scoreRefusal()

	/**
	 * Refuse a manual score that is not a number of zero or more.
	 *
	 * @param mixed $score The manual score as it would be saved.
	 *
	 * @return array{reason: string, message: string}|null The refusal, or null to allow.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function invalidScoreRefusal(mixed $score): ?array {
		if ($score !== null && (is_numeric($score) === false || (float)$score < 0)) {
			return self::INVALID_SCORE;
		}

		return null;
	}//end invalidScoreRefusal()

	/**
	 * The responses array of an attempt, re-indexed.
	 *
	 * @param array<string,mixed> $data The attempt.
	 *
	 * @return array<int,array<string,mixed>> The responses.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function responses(array $data): array {
		return array_values(array_filter((array)($data['responses'] ?? []), 'is_array'));
	}//end responses()

	/**
	 * Whether any of the named fields differs between the two versions.
	 *
	 * @param array<int,string> $fields The fields to compare.
	 *
	 * @return bool True when at least one differs.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function changed(array $fields): bool {
		foreach ($fields as $field) {
			if ($this->same(left: ($this->old[$field] ?? null), right: ($this->new[$field] ?? null)) === false) {
				return true;
			}
		}

		return false;
	}//end changed()

	/**
	 * Compare two stored values the way they round-trip through JSON, so an
	 * integer 3 and a float 3.0, or reordered object keys, are the same value.
	 *
	 * @param mixed $left One value.
	 * @param mixed $right The other value.
	 *
	 * @return bool True when equal.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function same(mixed $left, mixed $right): bool {
		return $this->normalise(value: $left) === $this->normalise(value: $right);
	}//end same()

	/**
	 * Normalise a value for comparison: numbers as floats, maps key-sorted.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The normalised value.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	private function normalise(mixed $value): mixed {
		if (in_array(gettype($value), ['integer', 'double'], true) === true) {
			return (float)$value;
		}

		if (is_array($value) === false) {
			return $value;
		}

		// Sorting a list by key keeps its order, so one ksort covers maps and lists.
		$normalised = array_map(fn (mixed $item): mixed => $this->normalise(value: $item), $value);
		ksort($normalised);

		return $normalised;
	}//end normalise()
}//end class
