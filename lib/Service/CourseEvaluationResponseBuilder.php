<?php

/**
 * Learniq Course Evaluation Response Builder
 *
 * Turns a learner's answers into a CourseEvaluationResponse: each answer is
 * checked against the campaign question it answers, and the stored object
 * carries the invitation's scope and the answers, never the learner.
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
 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#requirement-learner-answers-an-evaluation
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Checks answers and builds the response object.
 *
 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#requirement-learner-answers-an-evaluation
 */
class CourseEvaluationResponseBuilder {

	/**
	 * Check the given answers against the campaign questions.
	 *
	 * A likert-5 answer is a whole number from 1 to 5, a free-text answer a
	 * non-empty string. An entry carries only the value its kind has, like the
	 * shipped example responses: the answer item schema types both values as
	 * non-null, so a null there would not validate. Answers to questions the campaign does not ask are
	 * dropped; a required question without a valid answer is missing.
	 *
	 * @param array $questions The campaign questions.
	 * @param array $given     Map of questionId to rating or text.
	 *
	 * @return array{answers: array<int, array<string, mixed>>, missing: array<int, string>}
	 *
	 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-a-learner-answers-an-invitation
	 */
	public function checkAnswers(array $questions, array $given): array {
		$answers = [];
		$missing = [];
		foreach ($questions as $question) {
			if (is_array($question) === false || (string)($question['questionId'] ?? '') === '') {
				continue;
			}

			$questionId = (string)$question['questionId'];
			$answer = $this->answerFor(kind: (string)($question['kind'] ?? ''), value: ($given[$questionId] ?? null));
			if ($answer === null) {
				if (($question['required'] ?? false) === true) {
					$missing[] = $questionId;
				}

				continue;
			}

			$answers[] = array_merge(['questionId' => $questionId], $answer);
		}

		return ['answers' => $answers, 'missing' => $missing];
	}//end checkAnswers()

	/**
	 * One stored answer entry for a question kind, or null when the value
	 * does not answer it.
	 *
	 * @param string $kind  The question kind.
	 * @param mixed  $value The given value.
	 *
	 * @return array<string, mixed>|null
	 */
	private function answerFor(string $kind, mixed $value): ?array {
		if ($kind === 'likert-5') {
			if (is_numeric($value) === false || (float)$value !== (float)(int)$value || (int)$value < 1 || (int)$value > 5) {
				return null;
			}

			return ['ratingValue' => (int)$value];
		}

		if ($kind === 'free-text' && is_string($value) === true && trim($value) !== '') {
			return ['textValue' => trim($value)];
		}

		return null;
	}//end answerFor()

	/**
	 * The response object: the invitation's scope and the answers, and no
	 * field that names the learner (the schema has none, and nothing here
	 * adds one). The overall score is the last rating question's answer,
	 * the campaign convention where the overall rating closes the ratings.
	 *
	 * @param array $invitation The invitation being answered.
	 * @param array $answers    The checked answers.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-anonymous-answers-cannot-be-linked
	 */
	public function responsePayload(array $invitation, array $answers): array {
		$overall = null;
		foreach ($answers as $answer) {
			if (($answer['ratingValue'] ?? null) !== null) {
				$overall = (float)$answer['ratingValue'];
			}
		}

		return [
			'campaignId' => (string)($invitation['campaignId'] ?? ''),
			'courseId' => (string)($invitation['courseId'] ?? ''),
			'cohortId' => ($invitation['cohortId'] ?? null),
			'academicYear' => (string)($invitation['academicYear'] ?? ''),
			'period' => (string)($invitation['period'] ?? ''),
			'overallScore' => $overall,
			'answers' => $answers,
			'lifecycle' => 'draft',
			'tenant_id' => (string)($invitation['tenant_id'] ?? ''),
		];
	}//end responsePayload()
}//end class
