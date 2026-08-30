<?php

/**
 * Learniq Engagement Signal Handler
 *
 * Listens for OR's ObjectCreatedEvent on XapiStatement objects — the SAME
 * event LessonProgressHandler independently reacts to — recomputes the
 * learner's per-Course EngagementScore via EngagementScoreEvaluator, saves
 * it, then checks every active EngagementRiskThreshold in scope (per-learner
 * tenant-wide, or scoped to one Cohort the learner belongs to) and creates
 * an idempotency-keyed EngagementRiskFlag when a threshold is crossed and no
 * open/in-handling flag already exists for that learner+threshold.
 *
 * Mirrors BsaProgressFlagHandler's combined evaluate-then-flag shape (the
 * most recently established precedent in this codebase for this exact
 * "recompute a derived signal, then threshold-check it" combination) rather
 * than the older AttendanceThreshold/AttendanceFlagCreationHandler split
 * across a synthetic calculatedChange marker event and a second handler.
 *
 * Detection is a plain arithmetic/threshold comparison — NOT a TimedJob
 * (ADR-022), and NOT an AI/ML inference call of any kind. Any future
 * predictive/AI-assisted at-risk extension is routed through Hermiq's
 * agentaifeature register behind the ADR-005 gate, in a separate change.
 *
 * NOTE (honestly documented, not papered over): the `recency-days-above`
 * metric compares a recency gap computed at THIS event's instant — since
 * lastActivityAt is (re)set to the statement that just fired this handler,
 * that gap is necessarily ~0 immediately after any activity. The metric is
 * evaluated correctly per its literal definition, but a learner only
 * crosses a recency-days-above threshold on the NEXT statement they send
 * after a long gap, not while they remain silent — there is no scheduled/
 * TimedJob recheck (ADR-022 forbids one here). `engagement-score-below`
 * does not share this limitation.
 *
 * ADR-031 legitimate exception: new-object creation in response to a
 * real-event-driven recompute cannot be expressed as schema metadata
 * declarations — mirrors BsaProgressFlagHandler's role for BsaTrajectory.
 *
 * IMPORTANT: This handler ONLY creates the flag. It NEVER auto-acts against
 * the learner — human-in-the-loop throughout, mirroring AttendanceFlag/
 * BsaProgressFlag.
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
 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#requirement-at-risk-detection-beyond-bsa-is-a-deterministic-rule-based-threshold--not-aiml
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\BackgroundJob\EngagementSignalJob;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Recomputes EngagementScore and raises EngagementRiskFlags on threshold crossings.
 *
 * @implements IEventListener<Event>
 */
class EngagementSignalHandler implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const XAPI_SCHEMA = 'xapi-statement';


	/**
	 * Constructor.
	 *
	 * @param ListenerDeferralService $deferral Buffers the recompute for after the request.
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
	 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-falling-below-the-engagement-threshold-raises-a-flag-generalised-beyond-bsa
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false) {
			return;
		}

		$objectEntity = $event->getObject();

		if ($this->schemaResolver->registerSlug(entity: $objectEntity) !== self::LEARNIQ_REGISTER
			|| $this->schemaResolver->schemaSlug(entity: $objectEntity) !== self::XAPI_SCHEMA
		) {
			return;
		}

		$payload = $objectEntity->jsonSerialize();
		$tenantId = $payload['tenant_id'] ?? '';
		$learnerId = $payload['verified_actor_id'] ?? null;
		$courseId = $payload['courseId'] ?? null;

		if ($learnerId === null || $learnerId === '' || $courseId === null) {
			// No verified learner or no course scope — nothing to score.
			return;
		}

		// DEFERRED, not done here. This used to recompute the EngagementScore
		// and evaluate every active risk threshold inside the XapiStatement
		// write that triggered it — including an UNBOUNDED findAll() over the
		// thresholds. xAPI statements arrive in volume; paying a full
		// threshold scan on each one is the shape ADR-078 and gate-61 exist to
		// catch.
		//
		// Deduped per learner+course: a burst of statements for one learner on
		// one course owes ONE recompute, because the score is recomputed from
		// scratch.
		$this->deferral->defer(
			jobClass: EngagementSignalJob::class,
			entry: [
				'learnerId' => $learnerId,
				'courseId' => $courseId,
				'tenantId' => $tenantId,
			],
			dedupeKey: $learnerId . '|' . $courseId
		);

	}//end handle()

}//end class
