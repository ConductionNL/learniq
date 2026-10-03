<?php

/**
 * Learniq Portal Attempt Closer
 *
 * Time on a portal attempt. There is no timer on the server: every request
 * that finds the pupil's attempt past its deadline plus the grace hands it in
 * first, then answers as for a handed-in attempt. Hand-in records
 * `submittedAt` and fires `submit` as the pupil (two writes, as the app does:
 * the transition declares no inputs), which auto-scores the closed items.
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

use DateTimeImmutable;
use DateTimeInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Finds the open attempt, closes the ones out of time, hands attempts in.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */
class PortalAttemptCloser {

	public const IN_PROGRESS = 'in-progress';

	/**
	 * Constructor.
	 *
	 * @param PortalAttemptReader $reader OpenRegister reads.
	 * @param PortalAttemptWriter $writer Pupil-scoped writes.
	 * @param PortalAttemptClock $clock Deadlines and extra time.
	 * @param ITimeFactory $time The current time.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalAttemptReader $reader,
		private readonly PortalAttemptWriter $writer,
		private readonly PortalAttemptClock $clock,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The current time, second precision, with its offset.
	 *
	 * @return DateTimeImmutable
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function now(): DateTimeImmutable {
		return new DateTimeImmutable($this->time->getDateTime()->format(DateTimeInterface::ATOM));
	}//end now()

	/**
	 * The pupil's extra-time percentage on one test.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param string $examId The test.
	 *
	 * @return float
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function extraFor(PortalLearner $learner, string $examId): float {
		return $this->clock->extraTimePercentage(
			accommodations: $this->reader->accommodations(ncUserId: $learner->ncUserId),
			assessmentId: $examId
		);
	}//end extraFor()

	/**
	 * The pupil's attempt in progress, handing in any that ran out of time;
	 * null when none is left open.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param array<string, mixed> $exam The test.
	 * @param array<int, array<string, mixed>> $attempts The pupil's attempts at it.
	 * @param float $extra The pupil's extra time.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function openAttempt(PortalLearner $learner, array $exam, array $attempts, float $extra): ?array {
		foreach ($attempts as $attempt) {
			if (($attempt['lifecycle'] ?? '') !== self::IN_PROGRESS) {
				continue;
			}

			$deadline = $this->clock->deadline(attempt: $attempt, exam: $exam, extraPercentage: $extra);
			if ($this->clock->isClosed(deadline: $deadline, now: $this->now()) === true) {
				$this->handIn(learner: $learner, attempt: $attempt);
				continue;
			}

			return $attempt;
		}

		return null;
	}//end openAttempt()

	/**
	 * Whether an attempt no longer takes answers: handed in, or past its
	 * deadline plus grace (it is then handed in first). A test that can no
	 * longer be read closes the attempt, so no answer lands unchecked.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param array<string, mixed> $attempt The attempt.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function closeIfDue(PortalLearner $learner, array $attempt): bool {
		if (($attempt['lifecycle'] ?? '') !== self::IN_PROGRESS) {
			return true;
		}

		$exam = $this->reader->exam(id: (string)($attempt['assessmentId'] ?? ''));
		if ($exam === null) {
			return true;
		}

		$deadline = $this->clock->deadline(
			attempt: $attempt,
			exam: $exam,
			extraPercentage: $this->extraFor(learner: $learner, examId: (string)$exam['id'])
		);
		if ($this->clock->isClosed(deadline: $deadline, now: $this->now()) === false) {
			return false;
		}

		$this->handIn(learner: $learner, attempt: $attempt);

		return true;
	}//end closeIfDue()

	/**
	 * Record the hand-in time, then fire `submit` as the pupil.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param array<string, mixed> $attempt The attempt in progress.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function handIn(PortalLearner $learner, array $attempt): void {
		$id = (string)$attempt['id'];
		$submittedAt = ($attempt['submittedAt'] ?? null);
		if (is_string($submittedAt) === false || $submittedAt === '') {
			$attempt['submittedAt'] = $this->now()->format(DateTimeInterface::ATOM);
			$this->writer->saveAttempt(learner: $learner, id: $id, row: $attempt);
		}

		$this->writer->fireSubmit(learner: $learner, id: $id);
		$this->logger->info('[PortalAttemptCloser] Handed in attempt {id} through the portal.', ['id' => $id]);
	}//end handIn()
}//end class
