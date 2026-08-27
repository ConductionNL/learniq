<?php

/**
 * Learniq Learner Engagement Rollup Handler
 *
 * Listens for OpenRegister's ObjectCreatedEvent on PointAward objects,
 * finds-or-creates the learner's LearnerEngagement row, and recomputes it via
 * PointEngagementEvaluator. When the triggering award's sourceKind is not
 * itself streak-milestone (the recursion guard -- a milestone bonus award
 * must not re-trigger its own milestone check), compares the freshly
 * computed currentStreakDays against every active
 * PointRule(kind: streak-milestone)'s milestoneDays; for any threshold newly
 * crossed (previousStreak < milestoneDays <= newStreak, where previousStreak
 * is the LearnerEngagement row's currentStreakDays before this recompute),
 * creates a bonus PointAward(sourceKind: streak-milestone,
 * sourceObjectId: null). That second award re-enters this handler once more
 * to fold the bonus into totalPoints/levelId, but the recursion guard means
 * it terminates after exactly one extra pass -- no infinite loop.
 *
 * ADR-031 legitimate exception: event-to-object-write bridge that cannot be
 * expressed as a schema declaration, mirroring GradeRollupHandler /
 * BsaProgressFlagHandler exactly. Recomputation is triggered by a real
 * PointAward ObjectCreatedEvent -- NOT a TimedJob (ADR-022).
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
 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#requirement-learner-totals-level-and-streak-are-computed-by-a-php-evaluator-not-a-sum-aggregation
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\BackgroundJob\LearnerEngagementRollupJob;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Recomputes LearnerEngagement and awards streak-milestone bonuses when a
 * PointAward is created.
 *
 * @implements IEventListener<Event>
 * @spec       openspec/changes/engagement-gamification/specs/engagement/spec.md#requirement-learner-totals-level-and-streak-are-computed-by-a-php-evaluator-not-a-sum-aggregation
 */
class LearnerEngagementRollupHandler implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const POINT_AWARD_SCHEMA = 'point-award';

	/**
	 * Constructor.
	 *
	 * @param ListenerDeferralService $deferral Buffers the roll-up for after the request.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's register/schema ids to slugs.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerDeferralService $deferral,
		private readonly ListenerSchemaResolver $schemaResolver,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectCreatedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-new-pointaward-recomputes-totals-and-level
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$objectEntity = $event->getObject();

		if ($this->schemaResolver->registerSlug(entity: $objectEntity) !== self::LEARNIQ_REGISTER
			|| $this->schemaResolver->schemaSlug(entity: $objectEntity) !== self::POINT_AWARD_SCHEMA
		) {
			return;
		}

		$award = $objectEntity->jsonSerialize();
		$learnerId = $award['learnerId'] ?? '';
		$tenantId = $award['tenant_id'] ?? '';
		$sourceKind = $award['sourceKind'] ?? '';

		if ($learnerId === '') {
			return;
		}

		// DEFERRED, not done here. This used to read the engagement row,
		// evaluate points/level/streak, write the row, then run an UNBOUNDED
		// findAll() over every active streak-milestone rule and write an award
		// per crossed milestone — all inside the PointAward write that
		// triggered it. ADR-078 makes post-`*ed` work async by default.
		//
		// The unbounded rule read has not become bounded; it has stopped being
		// paid on every point a learner earns.
		//
		// `sourceKind` travels in the entry because the recursion guard needs
		// it, and the dedupe key carries it too: a milestone award must NOT
		// coalesce with an ordinary one, or the ordinary award's milestone
		// check would be dropped along with it.
		$this->deferral->defer(
			jobClass: LearnerEngagementRollupJob::class,
			entry: [
				'learnerId' => $learnerId,
				'tenantId' => $tenantId,
				'sourceKind' => $sourceKind,
			],
			dedupeKey: $learnerId . '|' . $tenantId . '|' . $sourceKind
		);

	}//end handle()


}//end class
