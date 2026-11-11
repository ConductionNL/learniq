<?php

/**
 * Learniq Portal Assessment Catalogue
 *
 * Decides which tests a pupil may start through the portal. A test is
 * startable when it is published, has no proctoring configuration (the
 * portal has no test-mode hardening), belongs to the pupil's school, belongs
 * to a course or cohort the pupil holds an active or pending Enrolment in,
 * passes LessonReleaseEvaluator (the absolute window, the drip delay and the
 * release conditions) and has attempts left (`maxAttempts`, default 1).
 *
 * These rules run here because the attempt gate exempts a caller without a
 * Nextcloud user; the portal endpoints check them before any write.
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

use DateTimeInterface;
use OCA\Learniq\Service\AssessmentAccessPolicy;
use OCA\Learniq\Service\LessonReleaseEvaluator;

/**
 * Which tests a pupil may start, and why not.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */
class PortalAssessmentCatalogue {

	/**
	 * Reason keys a start can be refused with (PortalMessages words them).
	 */
	public const NOT_AVAILABLE = 'not-available';
	public const NOT_OPEN = 'not-open';
	public const CLOSED = 'closed';
	public const ATTEMPTS_USED = 'attempts-used';
	public const PROCTORED = 'proctored';

	/**
	 * Enrolment states that let a pupil take a course's tests.
	 */
	private const ENROLLED_STATES = ['active', 'pending', ''];

	/**
	 * Constructor.
	 *
	 * @param PortalAttemptReader $reader OpenRegister reads.
	 * @param LessonReleaseEvaluator $release Drip delay and release conditions.
	 * @param AssessmentAccessPolicy $policy The absolute availability window.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalAttemptReader $reader,
		private readonly LessonReleaseEvaluator $release,
		private readonly AssessmentAccessPolicy $policy,
	) {
	}//end __construct()

	/**
	 * The pupil's published tests, each with the enrolment it came through.
	 *
	 * @param PortalLearner $learner The pupil.
	 *
	 * @return array<int, array{exam: array<string, mixed>, enrolment: array<string, mixed>}>
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function examsFor(PortalLearner $learner): array {
		$found = [];
		foreach ($this->enrolments(learner: $learner) as $enrolment) {
			foreach ($this->examsThrough(enrolment: $enrolment) as $exam) {
				$id = (string)($exam['id'] ?? '');
				if ($id !== '' && isset($found[$id]) === false) {
					$found[$id] = ['exam' => $exam, 'enrolment' => $enrolment];
				}
			}
		}

		return array_values($found);
	}//end examsFor()

	/**
	 * The published tests of an enrolment's course, then of its cohort.
	 *
	 * @param array<string, mixed> $enrolment The enrolment.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function examsThrough(array $enrolment): array {
		$exams = [];
		foreach (['courseId', 'cohortId'] as $field) {
			$value = (string)($enrolment[$field] ?? '');
			if ($value === '') {
				continue;
			}

			foreach ($this->reader->publishedExams(field: $field, value: $value) as $exam) {
				$exams[] = $exam;
			}
		}

		return $exams;
	}//end examsThrough()

	/**
	 * The pupil's enrolment a test belongs to, or null.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param array<string, mixed> $exam The test.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function enrolmentFor(PortalLearner $learner, array $exam): ?array {
		foreach ($this->enrolments(learner: $learner) as $enrolment) {
			if ($this->belongsTo(exam: $exam, enrolment: $enrolment) === true) {
				return $enrolment;
			}
		}

		return null;
	}//end enrolmentFor()

	/**
	 * Whether a test belongs to an enrolment's course or cohort.
	 *
	 * @param array<string, mixed> $exam The test.
	 * @param array<string, mixed> $enrolment The enrolment.
	 *
	 * @return bool
	 */
	private function belongsTo(array $exam, array $enrolment): bool {
		$courseId = (string)($exam['courseId'] ?? '');
		$cohortId = (string)($exam['cohortId'] ?? '');

		return ($courseId !== '' && ($enrolment['courseId'] ?? '') === $courseId)
			|| ($cohortId !== '' && ($enrolment['cohortId'] ?? '') === $cohortId);
	}//end belongsTo()

	/**
	 * Why the pupil may not start a new attempt at this test, or null.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param array<string, mixed> $exam The test, raw.
	 * @param array<string, mixed>|null $enrolment The enrolment it belongs to.
	 * @param int $attemptsUsed How many attempts the pupil already has.
	 * @param DateTimeInterface $now The current time.
	 *
	 * @return string|null One of the reason constants.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function startBlock(PortalLearner $learner, array $exam, ?array $enrolment, int $attemptsUsed, DateTimeInterface $now): ?string {
		$run = new AttemptStartRun(learner: $learner, exam: $exam);

		return $this->availabilityBlock(run: $run, enrolment: $enrolment)
			?? $this->windowBlock(run: $run, now: $now)
			?? $this->releaseBlock(run: $run, enrolment: $enrolment)
			?? $this->attemptsBlock(run: $run, attemptsUsed: $attemptsUsed);
	}//end startBlock()

	/**
	 * Why the test is not on offer to this pupil at all, or null.
	 *
	 * @param AttemptStartRun $run The pupil and the test.
	 * @param array<string, mixed>|null $enrolment The enrolment it belongs to.
	 *
	 * @return string|null NOT_AVAILABLE, PROCTORED or null.
	 */
	private function availabilityBlock(AttemptStartRun $run, ?array $enrolment): ?string {
		if ($this->isOffered(run: $run, enrolment: $enrolment) === false) {
			return self::NOT_AVAILABLE;
		}

		if ($this->isProctored(exam: $run->exam) === true) {
			return self::PROCTORED;
		}

		return null;
	}//end availabilityBlock()

	/**
	 * Whether the test is published, reached through an enrolment and of the
	 * pupil's own school.
	 *
	 * @param AttemptStartRun $run The pupil and the test.
	 * @param array<string, mixed>|null $enrolment The enrolment it belongs to.
	 *
	 * @return bool
	 */
	private function isOffered(AttemptStartRun $run, ?array $enrolment): bool {
		return ($run->exam['lifecycle'] ?? '') === 'published'
			&& $enrolment !== null
			&& $this->otherSchool(learner: $run->learner, exam: $run->exam) === false;
	}//end isOffered()

	/**
	 * Why the absolute availability window refuses the start, or null.
	 *
	 * @param AttemptStartRun $run The pupil and the test.
	 * @param DateTimeInterface $now The current time.
	 *
	 * @return string|null NOT_OPEN, CLOSED or null.
	 */
	private function windowBlock(AttemptStartRun $run, DateTimeInterface $now): ?string {
		$window = $this->policy->windowBlock(assessment: $run->exam, now: $now);
		if ($window === null) {
			return null;
		}

		return match ($window['reason']) {
			AssessmentAccessPolicy::REASON_NOT_OPEN => self::NOT_OPEN,
			default => self::CLOSED,
		};
	}//end windowBlock()

	/**
	 * Whether the release rules (drip delay, release conditions) hold the
	 * test back for this pupil.
	 *
	 * @param AttemptStartRun $run The pupil and the test.
	 * @param array<string, mixed>|null $enrolment The enrolment it belongs to.
	 *
	 * @return string|null NOT_AVAILABLE or null.
	 */
	private function releaseBlock(AttemptStartRun $run, ?array $enrolment): ?string {
		$verdict = $this->release->evaluate(
			item: $run->exam,
			itemSchema: 'exam',
			learnerId: $run->learner->ncUserId,
			enrolment: (array)$enrolment
		);
		if (($verdict['available'] ?? false) !== true) {
			return self::NOT_AVAILABLE;
		}

		return null;
	}//end releaseBlock()

	/**
	 * Whether the pupil has used up the test's attempts.
	 *
	 * @param AttemptStartRun $run The pupil and the test.
	 * @param int $attemptsUsed How many attempts the pupil already has.
	 *
	 * @return string|null ATTEMPTS_USED or null.
	 */
	private function attemptsBlock(AttemptStartRun $run, int $attemptsUsed): ?string {
		if ($this->policy->attemptsBlock(assessment: $run->exam, attemptsUsed: $attemptsUsed) !== null) {
			return self::ATTEMPTS_USED;
		}

		return null;
	}//end attemptsBlock()

	/**
	 * Whether a test has a proctoring configuration that asks for anything.
	 *
	 * @param array<string, mixed> $exam The test.
	 *
	 * @return bool
	 */
	private function isProctored(array $exam): bool {
		$proctoring = ($exam['proctoring'] ?? null);
		if (is_array($proctoring) === false) {
			return false;
		}

		return array_filter($proctoring) !== [];
	}//end isProctored()

	/**
	 * Whether the test belongs to another school than the pupil.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param array<string, mixed> $exam The test.
	 *
	 * @return bool
	 */
	private function otherSchool(PortalLearner $learner, array $exam): bool {
		$tenant = (string)($exam['tenant_id'] ?? '');

		return $tenant !== '' && $learner->tenantId !== '' && $tenant !== $learner->tenantId;
	}//end otherSchool()

	/**
	 * The pupil's enrolments that let them take tests.
	 *
	 * @param PortalLearner $learner The pupil.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function enrolments(PortalLearner $learner): array {
		return array_values(
			array_filter(
				$this->reader->enrolments(ncUserId: $learner->ncUserId),
				static fn (array $row): bool => in_array((string)($row['lifecycle'] ?? ''), self::ENROLLED_STATES, true)
			)
		);
	}//end enrolments()
}//end class
