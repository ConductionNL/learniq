<?php

/**
 * Learniq Engagement Score Evaluator
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Stateless calculation engine computing a learner's per-Course engagement
 * signals from their XapiStatement activity: timeOnTaskMinutes (sum of every
 * matching statement's result.duration), lastActivityAt (max statement
 * timestamp), and a bounded 0-100 score combining completion-ratio-of-course
 * time-on-task with a recency-gap decay factor.
 *
 * ADR-031 legitimate exception: "Calculation engine above schema metadata."
 * Summing nested XapiStatement.result duration data and resolving the
 * Course's total Lesson.durationMinutes is a cross-schema, iterative
 * computation the pure JSON-logic DSL cannot express — the same rationale
 * already accepted for GradeFormulaEvaluator/EnrolmentProgressEvaluator.
 * Single responsibility: evaluate -> return; no state, no audit writes.
 *
 * Score formula (plain weighted arithmetic, NOT a model / no AI-Act surface):
 *   completionRatio = min(1, timeOnTaskMinutes / totalLessonMinutes)
 *                      (0 when the course declares no Lesson.durationMinutes at all)
 *   recencyFactor    = 1 - min(1, gapDays / RECENCY_DECAY_WINDOW_DAYS)
 *                      where gapDays is the number of days between the
 *                      learner's PREVIOUS lastActivityAt (if any) and the
 *                      newly observed max statement timestamp — a large gap
 *                      between sessions lowers the recency component even
 *                      though the statement itself is fresh; a first-ever
 *                      statement has no gap to measure and defaults to 1.
 *   score            = round(completionRatio * 70 + recencyFactor * 30),
 *                      clamped to [0, 100]
 *
 * Consumed by:
 *   - EngagementSignalHandler (via ObjectCreatedEvent<XapiStatement>)
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/student-analytics/spec.md#requirement-persist-engagementscore-domain-objects-in-openregister
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateInterval;
use DateTimeImmutable;
use OCA\Learniq\Kernel\Contracts\EngagementScoreEvaluatorInterface;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Computes timeOnTaskMinutes, lastActivityAt, and a bounded 0-100 score for
 * a learner + course from their XapiStatement activity.
 */
class EngagementScoreEvaluator implements EngagementScoreEvaluatorInterface {

	private const LEARNIQ_REGISTER = 'learniq';
	private const XAPI_SCHEMA = 'xapi-statement';
	private const LESSON_SCHEMA = 'lesson';

	/**
	 * Recency-gap decay window, in days. A gap of 0 days yields a full 1.0
	 * recency factor; a gap at or beyond this window yields 0.
	 */
	private const RECENCY_DECAY_WINDOW_DAYS = 14;

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
	 * Evaluate a learner's engagement signals for a course.
	 *
	 * @param string $learnerId NC user ID of the learner.
	 * @param string $courseId UUID of the Course.
	 * @param string|null $previousActivityAt The EngagementScore's prior
	 *                                        lastActivityAt (ISO-8601),
	 *                                        if any — used only for
	 *                                        the recency-gap decay
	 *                                        factor.
	 *
	 * @return array{timeOnTaskMinutes: float, lastActivityAt: string|null, score: int}
	 *
	 * @spec openspec/specs/student-analytics/spec.md#scenario-time-on-task-accumulates-across-statements
	 */
	public function evaluate(string $learnerId, string $courseId, ?string $previousActivityAt = null): array {
		$statements        = $this->fetchStatements(learnerId: $learnerId, courseId: $courseId);
		$timeOnTaskMinutes = $this->timeOnTask(statements: $statements);
		$lastActivityAt    = $this->lastActivity(statements: $statements);
		$completionRatio   = $this->completionRatio(timeOnTaskMinutes: $timeOnTaskMinutes, courseId: $courseId);
		$recencyFactor     = $this->recencyFactor(
			previousActivityAt: $previousActivityAt,
			newLastActivityAt: $lastActivityAt
		);

		return [
			'timeOnTaskMinutes' => round($timeOnTaskMinutes, 2),
			'lastActivityAt' => $lastActivityAt,
			'score' => $this->scoreOf(completionRatio: $completionRatio, recencyFactor: $recencyFactor),
		];
	}//end evaluate()

	/**
	 * The minutes the statements' durations add up to, in statement order.
	 *
	 * @param array<int, array<string, mixed>> $statements The learner's statements.
	 *
	 * @return float
	 */
	private function timeOnTask(array $statements): float {
		$timeOnTaskMinutes = 0.0;
		foreach ($statements as $statement) {
			$timeOnTaskMinutes += $this->parseDurationMinutes(iso8601Duration: $statement['result']['duration'] ?? null);
		}

		return $timeOnTaskMinutes;
	}//end timeOnTask()

	/**
	 * The latest statement timestamp, or null when no statement carries one.
	 *
	 * @param array<int, array<string, mixed>> $statements The learner's statements.
	 *
	 * @return string|null ISO-8601.
	 */
	private function lastActivity(array $statements): ?string {
		$lastActivityAt = null;
		foreach ($statements as $statement) {
			$timestamp = $statement['timestamp'] ?? null;
			if ($this->isLater(timestamp: $timestamp, latest: $lastActivityAt) === true) {
				$lastActivityAt = $timestamp;
			}
		}

		return $lastActivityAt;
	}//end lastActivity()

	/**
	 * Whether a statement timestamp is set and later than the latest so far.
	 *
	 * @param mixed       $timestamp The statement's timestamp.
	 * @param string|null $latest    The latest timestamp so far, or null.
	 *
	 * @return bool
	 */
	private function isLater(mixed $timestamp, ?string $latest): bool {
		return is_string($timestamp) === true && $timestamp !== ''
			&& ($latest === null || $timestamp > $latest);
	}//end isLater()

	/**
	 * The time on task as a share of the course's published lesson time, at most 1.
	 *
	 * @param float  $timeOnTaskMinutes The learner's minutes.
	 * @param string $courseId          UUID of the Course.
	 *
	 * @return float Between 0.0 and 1.0; 0.0 when the course has no lesson time.
	 */
	private function completionRatio(float $timeOnTaskMinutes, string $courseId): float {
		$totalLessonMinutes = $this->sumPublishedLessonDuration(courseId: $courseId);
		if ($totalLessonMinutes > 0.0) {
			return min(1.0, $timeOnTaskMinutes / $totalLessonMinutes);
		}

		return 0.0;
	}//end completionRatio()

	/**
	 * The bounded score: 70 parts completion and 30 parts recency.
	 *
	 * @param float $completionRatio The completion share.
	 * @param float $recencyFactor   The recency factor.
	 *
	 * @return int Between 0 and 100.
	 */
	private function scoreOf(float $completionRatio, float $recencyFactor): int {
		$score = (int)round(($completionRatio * 70) + ($recencyFactor * 30));

		return max(0, min(100, $score));
	}//end scoreOf()

	/**
	 * Fetch every XapiStatement for this learner + course.
	 *
	 * @param string $learnerId NC user ID of the learner.
	 * @param string $courseId UUID of the Course.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchStatements(string $learnerId, string $courseId): array {
		$results = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::XAPI_SCHEMA,
					'verified_actor_id' => $learnerId,
					'courseId' => $courseId,
				],
			]
		);

		return array_map(
			static function ($statement) {
				if (is_array($statement) === true) {
					return $statement;
				}

				return $statement->jsonSerialize();
			},
			$results
		);

	}//end fetchStatements()

	/**
	 * Parse an xAPI ISO-8601 duration string (result.duration) into minutes.
	 * A missing or unparsable duration contributes 0, never an error.
	 *
	 * @param string|null $iso8601Duration e.g. "PT1H30M".
	 *
	 * @return float
	 */
	private function parseDurationMinutes(?string $iso8601Duration): float {
		if (is_string($iso8601Duration) === false || $iso8601Duration === '') {
			return 0.0;
		}

		try {
			$interval = new DateInterval($iso8601Duration);
		} catch (\Exception) {
			return 0.0;
		}

		$days = ((float)$interval->y * 365) + ((float)$interval->m * 30) + (float)$interval->d;
		$minutes = ($days * 24 * 60) + ((float)$interval->h * 60) + (float)$interval->i + ((float)$interval->s / 60);

		return $minutes;
	}//end parseDurationMinutes()

	/**
	 * Sum durationMinutes across a course's published Lessons. A Lesson with
	 * no durationMinutes contributes 0.
	 *
	 * @param string $courseId UUID of the Course.
	 *
	 * @return float
	 */
	private function sumPublishedLessonDuration(string $courseId): float {
		$results = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::LESSON_SCHEMA,
					'courseId' => $courseId,
					'lifecycle' => 'published',
				],
			]
		);

		$total = 0.0;
		foreach ($results as $lesson) {
			if (is_array($lesson) === false) {
				$lesson = $lesson->jsonSerialize();
			}

			$total += (float)($lesson['durationMinutes'] ?? 0);
		}

		return $total;
	}//end sumPublishedLessonDuration()

	/**
	 * Compute the recency-gap decay factor between the prior lastActivityAt
	 * and the newly observed one. No activity at all yields 0 (nothing to
	 * be "recent" about). No PRIOR activity but a new statement just arrived
	 * (first-ever statement for this learner+course) yields a full 1.0
	 * factor — there is no gap to measure yet, and the learner is engaging
	 * right now.
	 *
	 * @param string|null $previousActivityAt Prior lastActivityAt, ISO-8601.
	 * @param string|null $newLastActivityAt Newly observed max timestamp, ISO-8601.
	 *
	 * @return float Between 0.0 and 1.0.
	 */
	private function recencyFactor(?string $previousActivityAt, ?string $newLastActivityAt): float {
		if ($newLastActivityAt === null) {
			// No activity has ever been observed — nothing to be recent about.
			return 0.0;
		}

		if ($previousActivityAt === null) {
			// First-ever statement for this learner+course — no gap to measure.
			return 1.0;
		}

		return $this->decay(previousActivityAt: $previousActivityAt, newLastActivityAt: $newLastActivityAt);
	}//end recencyFactor()

	/**
	 * The factor for the gap between two activity times: full for no gap or an
	 * unreadable time, falling linearly to 0 over the decay window.
	 *
	 * @param string $previousActivityAt Prior lastActivityAt, ISO-8601.
	 * @param string $newLastActivityAt  Newly observed max timestamp, ISO-8601.
	 *
	 * @return float Between 0.0 and 1.0.
	 */
	private function decay(string $previousActivityAt, string $newLastActivityAt): float {
		try {
			$previous = new DateTimeImmutable($previousActivityAt);
			$new = new DateTimeImmutable($newLastActivityAt);
		} catch (\Exception) {
			return 1.0;
		}

		$gapDays = ($new->getTimestamp() - $previous->getTimestamp()) / 86400;
		if ($gapDays <= 0) {
			return 1.0;
		}

		return max(0.0, 1.0 - min(1.0, $gapDays / self::RECENCY_DECAY_WINDOW_DAYS));
	}//end decay()
}//end class
