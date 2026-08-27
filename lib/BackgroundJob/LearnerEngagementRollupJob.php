<?php

/**
 * Deferred LearnerEngagement roll-up and streak-milestone award.
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
 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
 */

declare(strict_types=1);

namespace OCA\Learniq\BackgroundJob;

use DateTimeImmutable;
use OCA\Learniq\Engagement\PointEngagementEvaluator;
use OCA\OpenRegister\BackgroundJob\ActorForwardedJob;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Recomputes `LearnerEngagement` and awards streak milestones out of band.
 *
 * WHY THIS EXISTS. `LearnerEngagementRollupHandler` did all of this INSIDE the
 * PointAward write that triggered it — a read, an evaluation, a
 * `saveObject()`, then an UNBOUNDED `findAll()` over every active
 * streak-milestone PointRule plus a further `saveObject()` per crossed
 * milestone. ADR-078 makes post-`*ed` work async by default; gate-61 named
 * that unbounded read on the write path specifically.
 *
 * The unbounded read has not become bounded — it has stopped being paid per
 * write. Reading every active rule once per queued chunk is a different
 * proposition from reading them on every point a learner earns.
 *
 * @psalm-suppress UnusedClass Enqueued by ListenerDeferralService at request
 *  shutdown, never constructed by name.
 */
class LearnerEngagementRollupJob extends ActorForwardedJob {

	private const LEARNIQ_REGISTER = 'learniq';
	private const LEARNER_ENGAGEMENT_SCHEMA = 'learner-engagement';
	private const POINT_RULE_SCHEMA = 'point-rule';
	private const POINT_AWARD_SCHEMA = 'point-award';
	private const STREAK_MILESTONE_KIND = 'streak-milestone';

	/**
	 * @param ITimeFactory            $time          Clock, for the base job.
	 * @param IUserSession            $userSession   Actor forwarding, for the base job.
	 * @param IUserManager            $userManager   Actor forwarding, for the base job.
	 * @param OrganisationService     $organisation  Tenant context, for the base job.
	 * @param LoggerInterface         $logger        Logger; the base declares it protected.
	 * @param ObjectService           $objectService OR object access.
	 * @param PointEngagementEvaluator $evaluator    Points/level/streak calculation engine.
	 * @param ITimeFactory            $timeFactory   Clock for the written timestamps.
	 */
	public function __construct(
		ITimeFactory $time,
		IUserSession $userSession,
		IUserManager $userManager,
		OrganisationService $organisation,
		LoggerInterface $logger,
		private readonly ObjectService $objectService,
		private readonly PointEngagementEvaluator $evaluator,
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
	 * Roll up each deferred learner, and award any newly crossed milestone.
	 *
	 * @param DeferredListenerContext $context The buffered entries.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		foreach ($context->getEntries() as $entry) {
			$learnerId = (string)($entry['learnerId'] ?? '');
			if ($learnerId === '') {
				continue;
			}

			$tenantId = (string)($entry['tenantId'] ?? '');
			$sourceKind = (string)($entry['sourceKind'] ?? '');

			try {
				$this->rollUp(learnerId: $learnerId, tenantId: $tenantId, sourceKind: $sourceKind);
			} catch (\Throwable $e) {
				$this->logger->warning(
					message: '[LearnerEngagementRollupJob] Roll-up failed for entry',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'learnerId' => $learnerId,
						'error' => $e->getMessage(),
					]
				);
			}
		}//end foreach

	}//end runDeferred()

	/**
	 * Recompute one learner's engagement row, then check milestones.
	 *
	 * @param string $learnerId  The learner.
	 * @param string $tenantId   The tenant.
	 * @param string $sourceKind The award kind that triggered this.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
	 */
	private function rollUp(string $learnerId, string $tenantId, string $sourceKind): void {
		$existing = $this->findExistingEngagement(learnerId: $learnerId, tenantId: $tenantId);
		$previousStreak = (int)($existing['currentStreakDays'] ?? 0);

		$result = $this->evaluator->evaluate(learnerId: $learnerId, tenantId: $tenantId);

		$data = array_merge(
			$existing ?? [],
			[
				'learnerId' => $learnerId,
				'totalPoints' => $result['totalPoints'],
				'levelId' => $result['levelId'],
				'currentStreakDays' => $result['currentStreakDays'],
				'longestStreakDays' => $result['longestStreakDays'],
				'lastActivityDate' => $result['lastActivityDate'],
				'lastRecomputedAt' => DateTimeImmutable::createFromMutable($this->timeFactory->getDateTime())->format(\DATE_ATOM),
				'tenant_id' => $tenantId,
			]
		);

		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::LEARNER_ENGAGEMENT_SCHEMA,
			object: $data
		);

		if ($sourceKind === self::STREAK_MILESTONE_KIND) {
			// Recursion guard, unchanged: a milestone bonus award's own roll-up
			// must not re-check streak milestones. Deferring preserves it
			// because `sourceKind` travels in the entry — and the dedupe key
			// keeps a milestone entry distinct from an ordinary one, so
			// coalescing can never drop the check the ordinary award was owed.
			return;
		}

		$this->checkStreakMilestones(
			learnerId: $learnerId,
			tenantId: $tenantId,
			previousStreak: $previousStreak,
			newStreak: $result['currentStreakDays']
		);

	}//end rollUp()

	/**
	 * The learner's existing engagement row, or null.
	 *
	 * @param string $learnerId The learner.
	 * @param string $tenantId  The tenant.
	 *
	 * @return array<string, mixed>|null The row, or null when the learner has none yet.
	 *
	 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
	 */
	private function findExistingEngagement(string $learnerId, string $tenantId): ?array {
		$existing = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => self::LEARNER_ENGAGEMENT_SCHEMA,
				'filters' => [
					'learnerId' => $learnerId,
					'tenant_id' => $tenantId,
				],
				'limit' => 1,
			]
		);

		if (empty($existing) === true) {
			return null;
		}

		$row = $existing[0];
		if (is_array($row) === false) {
			$row = $row->jsonSerialize();
		}

		return $row;

	}//end findExistingEngagement()

	/**
	 * Award any streak milestone the learner has newly crossed.
	 *
	 * @param string $learnerId      The learner.
	 * @param string $tenantId       The tenant.
	 * @param int    $previousStreak The streak before this roll-up.
	 * @param int    $newStreak      The streak after it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
	 */
	private function checkStreakMilestones(string $learnerId, string $tenantId, int $previousStreak, int $newStreak): void {
		if ($newStreak <= $previousStreak) {
			// No forward progress -- nothing can have been newly crossed.
			return;
		}

		$rules = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => self::POINT_RULE_SCHEMA,
				'filters' => [
					'kind' => self::STREAK_MILESTONE_KIND,
					'lifecycle' => 'active',
					'tenant_id' => $tenantId,
				],
			]
		);

		foreach ($rules as $rule) {
			if (is_array($rule) === false) {
				$rule = $rule->jsonSerialize();
			}

			$milestoneDays = $rule['milestoneDays'] ?? null;
			if ($milestoneDays === null) {
				continue;
			}

			$milestoneDays = (int)$milestoneDays;
			if ($previousStreak >= $milestoneDays || $newStreak < $milestoneDays) {
				continue;
			}

			$ruleId = $rule['id'] ?? ($rule['uuid'] ?? null);
			if ($ruleId === null) {
				continue;
			}

			$this->objectService->saveObject(
				register: self::LEARNIQ_REGISTER,
				schema: self::POINT_AWARD_SCHEMA,
				object: [
					'learnerId' => $learnerId,
					'pointRuleId' => $ruleId,
					'points' => (float)($rule['points'] ?? 0),
					'sourceKind' => self::STREAK_MILESTONE_KIND,
					'sourceObjectId' => null,
					'awardedAt' => DateTimeImmutable::createFromMutable($this->timeFactory->getDateTime())->format(\DATE_ATOM),
					'tenant_id' => $tenantId,
				]
			);
		}//end foreach

	}//end checkStreakMilestones()
}//end class
