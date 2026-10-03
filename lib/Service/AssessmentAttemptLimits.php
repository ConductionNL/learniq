<?php

/**
 * Learniq Assessment Attempt Limits
 *
 * The attempt rules a learner is held to on the in-app test screen, on the
 * server, with the same parts the portal endpoints use (assessment-portal-
 * endpoints): the window, the access code and the attempts left from
 * AssessmentAccessPolicy; the attempt count, the test and the learner's extra
 * time from PortalAttemptReader; the deadline and its grace from
 * PortalAttemptClock.
 *
 * - A new attempt starts inside the window, with the access code, and while
 *   attempts are left; the server then sets when it started and its number,
 *   so the time limit runs on the server's clock.
 * - After the deadline plus the grace an attempt's answers stop changing.
 *
 * Consumed by AssessmentAttemptGateListener (start) and
 * AssessmentResultIntegrityListener (late answers).
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
 * @spec openspec/specs/assessment/spec.md#requirement-the-in-app-test-screen-enforces-attempts-and-time-on-the-server
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeInterface;
use OCA\Learniq\Service\Portal\PortalAttemptClock;
use OCA\Learniq\Service\Portal\PortalAttemptReader;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Start rules and the time limit of a learner's attempt.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-the-in-app-test-screen-enforces-attempts-and-time-on-the-server
 */
class AssessmentAttemptLimits {

	/**
	 * Lifecycle states of an attempt the learner is still taking.
	 */
	private const IN_PROGRESS_STATES = ['in-progress', ''];

	/**
	 * Constructor.
	 *
	 * @param PortalAttemptReader $attempts The learner's attempts, the test and extra time.
	 * @param PortalAttemptClock $clock The deadline and its grace.
	 * @param AssessmentAccessPolicy $policy Window, access code and attempts left.
	 * @param ITimeFactory $timeFactory The current time.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalAttemptReader $attempts,
		private readonly PortalAttemptClock $clock,
		private readonly AssessmentAccessPolicy $policy,
		private readonly ITimeFactory $timeFactory,
	) {
	}//end __construct()

	/**
	 * Whether a new attempt may start, and what the server sets on it.
	 *
	 * @param array<string, mixed> $assessment The raw Assessment row.
	 * @param array<string, mixed> $payload The AssessmentResult being created.
	 *
	 * @return array{block: array{reason: string, message: string}|null, stamp: array<string, mixed>}
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-the-in-app-test-screen-enforces-attempts-and-time-on-the-server
	 */
	public function start(array $assessment, array $payload): array {
		$now = $this->timeFactory->getDateTime();

		$block = $this->policy->windowBlock(assessment: $assessment, now: $now);
		if ($block === null) {
			$block = $this->policy->accessCodeBlock(assessment: $assessment, given: ($payload['accessCode'] ?? null));
		}

		if ($block !== null) {
			return ['block' => $block, 'stamp' => []];
		}

		$used = count(
			$this->attempts->attemptsFor(
				examId: (string)($payload['assessmentId'] ?? ''),
				ncUserId: (string)($payload['learnerId'] ?? '')
			)
		);
		$block = $this->policy->attemptsBlock(assessment: $assessment, attemptsUsed: $used);
		if ($block !== null) {
			return ['block' => $block, 'stamp' => []];
		}

		$stamp = ['startedAt' => $now->format(DateTimeInterface::ATOM), 'attemptNumber' => ($used + 1)];
		$stamp['deadlineAt'] = $this->deadlineAt(assessment: $assessment, payload: $payload, startedAt: $stamp['startedAt']);
		if (array_key_exists('accessCode', $payload) === true) {
			// The typed code proved access; it is not kept on the attempt.
			$stamp['accessCode'] = null;
		}

		return ['block' => null, 'stamp' => $stamp];
	}//end start()

	/**
	 * The attempt's deadline, extra time included, for the screen's timer:
	 * the same moment the late-answer rule measures against (without the
	 * grace). Null for a test without a time limit.
	 *
	 * @param array<string, mixed> $assessment The raw Assessment row.
	 * @param array<string, mixed> $payload The AssessmentResult being created.
	 * @param string $startedAt The server's start stamp.
	 *
	 * @return string|null ISO-8601 deadline, or null.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-the-in-app-test-screen-shows-the-servers-deadline-and-saves-answers-as-the-learner-works
	 */
	private function deadlineAt(array $assessment, array $payload, string $startedAt): ?string {
		$examId = (string)($payload['assessmentId'] ?? '');
		$extra = $this->clock->extraTimePercentage(
			accommodations: $this->attempts->accommodations(ncUserId: (string)($payload['learnerId'] ?? '')),
			assessmentId: $examId
		);
		$deadline = $this->clock->deadline(attempt: ['startedAt' => $startedAt], exam: $assessment, extraPercentage: $extra);

		return $deadline?->format(DateTimeInterface::ATOM);
	}//end deadlineAt()

	/**
	 * Whether an update changes the learner's answers on their own attempt in
	 * progress after its deadline plus the grace. Scores the server adds to
	 * unchanged answers (the submit save) are not a change. A test that cannot
	 * be read counts as past its deadline, as on the portal.
	 *
	 * @param array<string, mixed> $old The stored attempt.
	 * @param array<string, mixed> $new The attempt as it would be saved.
	 * @param string $uid The acting user.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/assessment/spec.md#scenario-answers-after-the-deadline-are-not-saved
	 */
	public function answersLate(array $old, array $new, string $uid): bool {
		if (in_array((string)($old['lifecycle'] ?? ''), self::IN_PROGRESS_STATES, true) === false
			|| (string)($old['learnerId'] ?? '') !== $uid
			|| $this->answers(attempt: $old) === $this->answers(attempt: $new)
		) {
			return false;
		}

		return $this->pastDeadline(attempt: $old, uid: $uid);
	}//end answersLate()

	/**
	 * Whether an attempt is past its deadline plus the grace, with the
	 * learner's extra time; true when the test cannot be read.
	 *
	 * @param array<string, mixed> $attempt The stored attempt.
	 * @param string $uid The learner.
	 *
	 * @return bool
	 */
	private function pastDeadline(array $attempt, string $uid): bool {
		$examId = (string)($attempt['assessmentId'] ?? '');
		$exam = $this->attempts->exam(id: $examId);
		if ($exam === null) {
			return true;
		}

		$extra = $this->clock->extraTimePercentage(accommodations: $this->attempts->accommodations(ncUserId: $uid), assessmentId: $examId);
		$deadline = $this->clock->deadline(attempt: $attempt, exam: $exam, extraPercentage: $extra);

		return $this->clock->isClosed(deadline: $deadline, now: $this->timeFactory->getDateTime());
	}//end pastDeadline()

	/**
	 * The learner's answers: item and response per row, JSON-encoded so key
	 * order and number types compare the way they are stored.
	 *
	 * @param array<string, mixed> $attempt The attempt.
	 *
	 * @return array<int, string>
	 */
	private function answers(array $attempt): array {
		$answers = [];
		foreach ((array)($attempt['responses'] ?? []) as $row) {
			if (is_array($row) === true) {
				$answers[] = (string)json_encode([($row['itemId'] ?? null), ($row['response'] ?? null)]);
			}
		}

		return $answers;
	}//end answers()
}//end class
