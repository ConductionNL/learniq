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
			foreach (['courseId', 'cohortId'] as $field) {
				$value = (string)($enrolment[$field] ?? '');
				if ($value === '') {
					continue;
				}

				foreach ($this->reader->publishedExams(field: $field, value: $value) as $exam) {
					$id = (string)($exam['id'] ?? '');
					if ($id !== '' && isset($found[$id]) === false) {
						$found[$id] = ['exam' => $exam, 'enrolment' => $enrolment];
					}
				}
			}
		}

		return array_values($found);
	}//end examsFor()

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
		$courseId = (string)($exam['courseId'] ?? '');
		$cohortId = (string)($exam['cohortId'] ?? '');
		foreach ($this->enrolments(learner: $learner) as $enrolment) {
			if (($courseId !== '' && ($enrolment['courseId'] ?? '') === $courseId)
				|| ($cohortId !== '' && ($enrolment['cohortId'] ?? '') === $cohortId)
			) {
				return $enrolment;
			}
		}

		return null;
	}//end enrolmentFor()

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
		if (($exam['lifecycle'] ?? '') !== 'published' || $enrolment === null || $this->otherSchool(learner: $learner, exam: $exam) === true) {
			return self::NOT_AVAILABLE;
		}

		if ($this->isProctored(exam: $exam) === true) {
			return self::PROCTORED;
		}

		$window = $this->policy->windowBlock(assessment: $exam, now: $now);
		if ($window !== null) {
			return match ($window['reason']) {
				AssessmentAccessPolicy::REASON_NOT_OPEN => self::NOT_OPEN,
				default => self::CLOSED,
			};
		}

		$verdict = $this->release->evaluate(item: $exam, itemSchema: 'exam', learnerId: $learner->ncUserId, enrolment: $enrolment);
		if (($verdict['available'] ?? false) !== true) {
			return self::NOT_AVAILABLE;
		}

		if ($this->policy->attemptsBlock(assessment: $exam, attemptsUsed: $attemptsUsed) !== null) {
			return self::ATTEMPTS_USED;
		}

		return null;
	}//end startBlock()

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
