<?php

/**
 * Deferred EngagementScore recompute and risk-threshold evaluation.
 *
 * @category BackgroundJob
 * @package  OCA\Learniq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-time-on-task-accumulates-across-statements
 */

declare(strict_types=1);

namespace OCA\Learniq\BackgroundJob;

use DateTimeImmutable;
use OCA\Learniq\Service\EngagementScoreEvaluator;
use OCA\OpenRegister\BackgroundJob\ActorForwardedJob;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Recomputes a learner's EngagementScore and evaluates risk thresholds.
 *
 * WHY THIS EXISTS. `EngagementSignalHandler` did all of this INSIDE the
 * XapiStatement write that triggered it — a read, an evaluation, a
 * `saveObject()`, then an UNBOUNDED `findAll()` over every active
 * EngagementRiskThreshold and, per crossed threshold, further reads and a flag
 * write. ADR-078 makes post-`*ed` work async by default, and gate-61 named
 * that unbounded read on the write path.
 *
 * xAPI statements arrive in volume. Paying a full threshold scan on each one
 * is the shape the gate exists to catch.
 *
 * DEDUPED per learner+course: a burst of statements for one learner on one
 * course owes ONE recompute, because the score is recomputed from scratch.
 *
 * @psalm-suppress UnusedClass Enqueued by ListenerDeferralService at request
 *  shutdown, never constructed by name.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Carries the threshold
 *  evaluation moved verbatim out of the listener; splitting it further is a
 *  separate change from moving it off the write path.
 */
class EngagementSignalJob extends ActorForwardedJob {

	private const LEARNIQ_REGISTER = 'learniq';
	private const ENGAGEMENT_SCORE_SCHEMA = 'engagement-score';
	private const ENGAGEMENT_RISK_THRESHOLD_SCHEMA = 'engagement-risk-threshold';
	private const ENGAGEMENT_RISK_FLAG_SCHEMA = 'engagement-risk-flag';
	private const COHORT_SCHEMA = 'cohort';

	/**
	 * EngagementRiskFlag lifecycle states that count as "still open" for
	 * idempotency purposes — a resolved flag does not block a fresh one.
	 *
	 * @var string[]
	 */
	private const OPEN_FLAG_STATES = ['open', 'in-handling'];


	/**
	 * @param ITimeFactory             $time          Clock, for the base job.
	 * @param IUserSession             $userSession   Actor forwarding, for the base job.
	 * @param IUserManager             $userManager   Actor forwarding, for the base job.
	 * @param OrganisationService      $organisation  Tenant context, for the base job.
	 * @param LoggerInterface          $logger        Logger; the base declares it protected.
	 * @param ObjectService            $objectService OR object access.
	 * @param EngagementScoreEvaluator $evaluator     Score calculation engine.
	 * @param ITimeFactory             $timeFactory   Clock for the written timestamps.
	 */
	public function __construct(
		ITimeFactory $time,
		IUserSession $userSession,
		IUserManager $userManager,
		OrganisationService $organisation,
		LoggerInterface $logger,
		private readonly ObjectService $objectService,
		private readonly EngagementScoreEvaluator $evaluator,
		private readonly ITimeFactory $timeFactory,
	) {
		parent::__construct(
			time: $time,
			userSession: $userSession,
			userManager: $userManager,
			organisation: $organisation,
			logger: $logger
		);
	}//end __construct()

	/**
	 * Recompute and evaluate thresholds for each deferred learner+course.
	 *
	 * @param DeferredListenerContext $context The buffered entries.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-time-on-task-accumulates-across-statements
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		foreach ($context->getEntries() as $entry) {
			$learnerId = (string)($entry['learnerId'] ?? '');
			$courseId = (string)($entry['courseId'] ?? '');

			if ($learnerId === '' || $courseId === '') {
				continue;
			}

			$tenantId = (string)($entry['tenantId'] ?? '');

			try {
				$engagementScore = $this->recomputeEngagementScore(
					learnerId: $learnerId,
					courseId: $courseId,
					tenantId: $tenantId
				);

				$this->checkThresholds(
					learnerId: $learnerId,
					courseId: $courseId,
					tenantId: $tenantId,
					engagementScore: $engagementScore
				);
			} catch (\Throwable $e) {
				$this->logger->warning(
					message: '[EngagementSignalJob] Recompute failed for entry',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'learnerId' => $learnerId,
						'courseId' => $courseId,
						'error' => $e->getMessage(),
					]
				);
			}
		}//end foreach

	}//end runDeferred()

	/**
	 * Recompute and persist the learner's EngagementScore for a course.
	 *
	 * @param string $learnerId NC user ID of the learner.
	 * @param string $courseId UUID of the Course.
	 * @param string $tenantId Tenant identifier.
	 *
	 * @return array<string, mixed> The saved EngagementScore data.
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-time-on-task-accumulates-across-statements
	 */
	private function recomputeEngagementScore(string $learnerId, string $courseId, string $tenantId): array {
		$existing = $this->findExistingEngagementScore(learnerId: $learnerId, courseId: $courseId);

		$result = $this->evaluator->evaluate(
			learnerId: $learnerId,
			courseId: $courseId,
			previousActivityAt: $existing['lastActivityAt'] ?? null
		);

		$data = array_merge(
			$existing ?? [],
			[
				'learnerId' => $learnerId,
				'courseId' => $courseId,
				'timeOnTaskMinutes' => $result['timeOnTaskMinutes'],
				'lastActivityAt' => $result['lastActivityAt'],
				'score' => $result['score'],
				'tenant_id' => $tenantId,
			]
		);

		$saved = $this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::ENGAGEMENT_SCORE_SCHEMA,
			object: $data
		);

		return $saved->jsonSerialize();
	}//end recomputeEngagementScore()

	/**
	 * Find the learner's existing EngagementScore for a course, if any.
	 *
	 * @param string $learnerId NC user ID of the learner.
	 * @param string $courseId UUID of the Course.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findExistingEngagementScore(string $learnerId, string $courseId): ?array {
		$results = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => self::ENGAGEMENT_SCORE_SCHEMA,
				'filters' => [
					'learnerId' => $learnerId,
					'courseId' => $courseId,
				],
				'limit' => 1,
			]
		);

		if (empty($results) === true) {
			return null;
		}

		$score = $results[0];
		if (is_array($score) === false) {
			$score = $score->jsonSerialize();
		}

		return $score;
	}//end findExistingEngagementScore()

	/**
	 * Check every active EngagementRiskThreshold in scope for this learner
	 * and raise an idempotency-keyed EngagementRiskFlag on a crossing.
	 *
	 * @param string $learnerId NC user ID of the learner.
	 * @param string $courseId UUID of the Course.
	 * @param string $tenantId Tenant identifier.
	 * @param array<string, mixed> $engagementScore The just-recomputed EngagementScore data.
	 *
	 * @return void
	 */
	private function checkThresholds(
		string $learnerId,
		string $courseId,
		string $tenantId,
		array $engagementScore,
	): void {
		$thresholds = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => self::ENGAGEMENT_RISK_THRESHOLD_SCHEMA,
				'filters' => [
					'lifecycle' => 'active',
				],
			]
		);

		foreach ($thresholds as $threshold) {
			if (is_array($threshold) === false) {
				$threshold = $threshold->jsonSerialize();
			}

			$this->checkThreshold(
				threshold: $threshold,
				learnerId: $learnerId,
				courseId: $courseId,
				tenantId: $tenantId,
				engagementScore: $engagementScore
			);
		}

	}//end checkThresholds()

	/**
	 * Evaluate a single EngagementRiskThreshold for a learner and create a
	 * flag if the metric is crossed and no open/in-handling flag already
	 * exists for this learner+threshold.
	 *
	 * @param array<string, mixed> $threshold EngagementRiskThreshold data.
	 * @param string $learnerId NC user ID of the learner.
	 * @param string $courseId UUID of the Course in context.
	 * @param string $tenantId Tenant identifier.
	 * @param array<string, mixed> $engagementScore The just-recomputed EngagementScore data.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-a-resolved-flag-does-not-block-re-flagging-on-a-later-relapse
	 */
	private function checkThreshold(
		array $threshold,
		string $learnerId,
		string $courseId,
		string $tenantId,
		array $engagementScore,
	): void {
		$cohortId = $threshold['cohortId'] ?? null;
		if ($cohortId !== null && $this->learnerInCohort(learnerId: $learnerId, cohortId: $cohortId) === false) {
			// Threshold is scoped to a Cohort the learner does not belong to.
			return;
		}

		$metric = $threshold['metric'] ?? '';
		$limit = $threshold['limit'] ?? null;
		if ($limit === null) {
			return;
		}

		$crossed = $this->isCrossed(metric: $metric, limit: (float)$limit, engagementScore: $engagementScore);
		if ($crossed === false) {
			return;
		}

		$thresholdId = $threshold['id'] ?? ($threshold['uuid'] ?? '');
		if ($thresholdId === '') {
			return;
		}

		if ($this->hasOpenFlag(learnerId: $learnerId, thresholdId: $thresholdId) === true) {
			// Idempotency: do not duplicate an already-open flag for this
			// learner + threshold.
			return;
		}

		$metricValue = $this->resolveMetricValue(metric: $metric, engagementScore: $engagementScore);
		$now = $this->timeFactory->now();

		$engagementScoreId = $engagementScore['id'] ?? ($engagementScore['uuid'] ?? null);

		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::ENGAGEMENT_RISK_FLAG_SCHEMA,
			object: [
				'learnerId' => $learnerId,
				'courseId' => $courseId,
				'engagementRiskThresholdId' => $thresholdId,
				'engagementScoreId' => $engagementScoreId,
				'metricValueAtFlag' => $metricValue,
				'flaggedAt' => $now->format(\DATE_ATOM),
				'lifecycle' => 'open',
				'tenant_id' => $tenantId,
			]
		);

	}//end checkThreshold()

	/**
	 * Determine whether a threshold's metric is crossed by the current
	 * EngagementScore.
	 *
	 * @param string $metric One of engagement-score-below|recency-days-above.
	 * @param float $limit Threshold limit value.
	 * @param array<string, mixed> $engagementScore The just-recomputed EngagementScore data.
	 *
	 * @return bool
	 */
	private function isCrossed(string $metric, float $limit, array $engagementScore): bool {
		if ($metric === 'engagement-score-below') {
			$score = $engagementScore['score'] ?? null;
			if ($score === null) {
				return false;
			}

			return (float)$score < $limit;
		}

		if ($metric === 'recency-days-above') {
			$recencyDays = $this->recencyDaysNow(lastActivityAt: $engagementScore['lastActivityAt'] ?? null);
			if ($recencyDays === null) {
				return false;
			}

			return $recencyDays > $limit;
		}

		return false;
	}//end isCrossed()

	/**
	 * Resolve the metric value to stamp onto a newly-created flag.
	 *
	 * @param string $metric One of engagement-score-below|recency-days-above.
	 * @param array<string, mixed> $engagementScore The just-recomputed EngagementScore data.
	 *
	 * @return float
	 */
	private function resolveMetricValue(string $metric, array $engagementScore): float {
		if ($metric === 'recency-days-above') {
			return (float)($this->recencyDaysNow(lastActivityAt: $engagementScore['lastActivityAt'] ?? null) ?? 0);
		}

		return (float)($engagementScore['score'] ?? 0);
	}//end resolveMetricValue()

	/**
	 * Compute the days between lastActivityAt and "now" (the injected time
	 * source), mirroring the declarative recencyDays calculation on
	 * EngagementScore for use inside this handler's threshold check.
	 *
	 * @param string|null $lastActivityAt ISO-8601 timestamp, or null.
	 *
	 * @return int|null
	 */
	private function recencyDaysNow(?string $lastActivityAt): ?int {
		if ($lastActivityAt === null || $lastActivityAt === '') {
			return null;
		}

		try {
			$last = new DateTimeImmutable($lastActivityAt);
		} catch (\Exception) {
			return null;
		}

		$now = $this->timeFactory->now();

		return (int)floor(($now->getTimestamp() - $last->getTimestamp()) / 86400);
	}//end recencyDaysNow()

	/**
	 * Check whether a learner is a member of a Cohort (Cohort.learnerIds).
	 *
	 * @param string $learnerId NC user ID of the learner.
	 * @param string $cohortId UUID of the Cohort.
	 *
	 * @return bool
	 */
	private function learnerInCohort(string $learnerId, string $cohortId): bool {
		$cohort = $this->objectService->find(
			id: $cohortId,
			register: self::LEARNIQ_REGISTER,
			schema: self::COHORT_SCHEMA
		);

		if ($cohort === null) {
			return false;
		}

		$cohortData = $cohort->jsonSerialize();

		$learnerIds = $cohortData['learnerIds'] ?? [];
		if (is_array($learnerIds) === false) {
			return false;
		}

		return in_array($learnerId, $learnerIds, true);
	}//end learnerInCohort()

	/**
	 * Check whether a still-open EngagementRiskFlag already exists for this
	 * learner + threshold.
	 *
	 * @param string $learnerId NC user ID of the learner.
	 * @param string $thresholdId UUID of the EngagementRiskThreshold.
	 *
	 * @return bool
	 */
	private function hasOpenFlag(string $learnerId, string $thresholdId): bool {
		foreach (self::OPEN_FLAG_STATES as $state) {
			$existing = $this->objectService->findAll(
				[
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::ENGAGEMENT_RISK_FLAG_SCHEMA,
					'filters' => [
						'learnerId' => $learnerId,
						'engagementRiskThresholdId' => $thresholdId,
						'lifecycle' => $state,
					],
					'limit' => 1,
				]
			);

			if (empty($existing) === false) {
				return true;
			}
		}

		return false;
	}//end hasOpenFlag()
}//end class
