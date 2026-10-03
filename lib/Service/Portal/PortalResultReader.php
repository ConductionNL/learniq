<?php

/**
 * Learniq Portal Result Reader
 *
 * The `result` step of portaliq's timed task. A pupil sees a result only once
 * the teacher released it: the attempt is `graded` and, when it fed a
 * GradeEntry, that entry is `published` (or `revised`, superseded by a newer
 * published one) and not held back by a `visibleFrom` in the future. A graded
 * attempt without a GradeEntry (no curriculum component) is released by the
 * grading itself, the teacher's final act on it.
 *
 * A released result carries the total, the maximum from the drawn points,
 * `passed` for a pass-mark test, and per item the prompt, the pupil's answer,
 * the score and the maximum. Never a correct answer.
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
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-result-is-shown-only-once-the-teacher-released-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use DateTimeImmutable;
use Exception;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The release rule and the released result.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-result-is-shown-only-once-the-teacher-released-it
 */
class PortalResultReader {

	/**
	 * GradeEntry states that count as released.
	 */
	private const RELEASED_GRADE_STATES = ['published', 'revised'];

	/**
	 * Constructor.
	 *
	 * @param PortalAttemptReader $reader OpenRegister reads.
	 * @param PortalItemPresenter $presenter Item prompts, without answers.
	 * @param ITimeFactory $time The current time (visibleFrom).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalAttemptReader $reader,
		private readonly PortalItemPresenter $presenter,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The pupil's result on one attempt.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param string $attemptId The attempt.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-result-is-shown-only-once-the-teacher-released-it
	 */
	public function result(PortalLearner $learner, string $attemptId): PortalOutcome {
		$attempt = $this->reader->attempt(id: $attemptId);
		if ($attempt === null || ($attempt['learnerId'] ?? null) !== $learner->ncUserId) {
			return new PortalOutcome(status: 404, body: ['error' => 'not_found']);
		}

		if ($this->isReleased(attempt: $attempt) === false) {
			return new PortalOutcome(status: 200, body: ['released' => false]);
		}

		$items = $this->items(attempt: $attempt);
		$score = array_sum(array_map(static fn (array $row): float => (float)($row['score'] ?? 0), $items));
		$maxScore = array_sum(array_map(static fn (array $row): float => (float)$row['maxScore'], $items));

		return new PortalOutcome(
			status: 200,
			body: [
				'released' => true,
				'score' => $score,
				'maxScore' => $maxScore,
				'passed' => $this->passed(assessmentId: (string)($attempt['assessmentId'] ?? ''), score: $score),
				'feedback' => null,
				'items' => $items,
			]
		);
	}//end result()

	/**
	 * Whether the teacher released the attempt's result.
	 *
	 * @param array<string, mixed> $attempt The attempt.
	 *
	 * @return bool
	 */
	private function isReleased(array $attempt): bool {
		if (($attempt['lifecycle'] ?? '') !== 'graded') {
			return false;
		}

		$gradeEntryId = ($attempt['gradeEntryId'] ?? null);
		if (is_string($gradeEntryId) === false || $gradeEntryId === '') {
			return true;
		}

		$entry = $this->reader->gradeEntry(id: $gradeEntryId);
		if ($entry === null || in_array(($entry['lifecycle'] ?? ''), self::RELEASED_GRADE_STATES, true) === false) {
			return false;
		}

		return $this->isVisible(visibleFrom: ($entry['visibleFrom'] ?? null));
	}//end isReleased()

	/**
	 * Whether a `visibleFrom` has passed (or is not set). An unreadable date
	 * holds the result back.
	 *
	 * @param mixed $visibleFrom The GradeEntry's visibleFrom.
	 *
	 * @return bool
	 */
	private function isVisible(mixed $visibleFrom): bool {
		if ($visibleFrom === null || $visibleFrom === '') {
			return true;
		}

		if (is_string($visibleFrom) === false) {
			return false;
		}

		try {
			return new DateTimeImmutable($visibleFrom) <= $this->time->getDateTime();
		} catch (Exception $exception) {
			return false;
		}
	}//end isVisible()

	/**
	 * One line per drawn item: prompt, answer, score, maximum.
	 *
	 * @param array<string, mixed> $attempt The attempt.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function items(array $attempt): array {
		$responses = [];
		foreach ((array)($attempt['responses'] ?? []) as $row) {
			if (is_array($row) === true && isset($row['itemId']) === true) {
				$responses[(string)$row['itemId']] = $row;
			}
		}

		$lines = [];
		foreach ((array)($attempt['drawnItemRefs'] ?? []) as $ref) {
			$itemId = (string)(((array)$ref)['itemId'] ?? '');
			if (is_array($ref) === false || $itemId === '') {
				continue;
			}

			$item = ($this->reader->item(id: $itemId) ?? ['id' => $itemId]);
			$row = ($responses[$itemId] ?? []);
			$lines[] = [
				'itemId' => $itemId,
				'prompt' => $this->presenter->present(item: $item, drawnRef: $ref)['prompt'],
				'response' => ($row['response']['value'] ?? null),
				'score' => ($row['manualScore'] ?? ($row['autoScore'] ?? null)),
				'maxScore' => ($ref['points'] ?? 0),
			];
		}

		return $lines;
	}//end items()

	/**
	 * Whether the score passes a pass-mark test; null for other schemes.
	 *
	 * @param string $assessmentId The test.
	 * @param float $score The total score.
	 *
	 * @return bool|null
	 */
	private function passed(string $assessmentId, float $score): ?bool {
		$exam = $this->reader->exam(id: $assessmentId);
		if ($exam === null || ($exam['scoringScheme'] ?? '') !== 'passMark' || is_numeric($exam['passMark'] ?? null) === false) {
			return null;
		}

		return $score >= (float)$exam['passMark'];
	}//end passed()
}//end class
