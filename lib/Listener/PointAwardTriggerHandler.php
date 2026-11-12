<?php

/**
 * Learniq Point Award Trigger Handler
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Listens for OpenRegister's ObjectTransitionedEvent on exactly three real,
 * already-firing transitions and creates an idempotency-keyed PointAward for
 * each active, matching PointRule:
 *
 * 1. Enrolment -> completed -- the transition XapiCompletionHandler already
 *    dispatches when a mandatory-training final lesson's xAPI verb is
 *    completed/passed. No new xAPI parsing.
 * 2. Submission -> submitted, where the materialised isLate calculation is
 *    false -- read directly off the transitioned object's payload (isLate is
 *    materialise: true, so it is present at transition time). A group
 *    Submission's learnerIds[] each receive their own award.
 * 3. GradeEntry -> published (both the publish and republish transitions
 *    target lifecycle "published", mirroring GradeRollupHandler's single
 *    getTo() === 'published' check) -- calls
 *    GradeFormulaEvaluator::evaluate() directly for the pass check rather
 *    than reading FinalGrade.passed off a possibly stale/not-yet-recomputed
 *    object, avoiding an event-ordering dependency on GradeRollupHandler
 *    having already run in the same dispatch cycle (the same reasoning
 *    BsaProgressFlagHandler uses for injecting BsaProgressEvaluator
 *    directly).
 *
 * ADR-031 legitimate exception: event-to-object-write bridge that cannot be
 * expressed as a schema declaration, mirroring GradeRollupHandler /
 * BsaProgressFlagHandler exactly.
 *
 * Idempotency: before creating a PointAward, an existing award with the same
 * (learnerId, pointRuleId, sourceObjectId) triple is queried for and skipped
 * if found -- mirrors BsaProgressFlagHandler's OPEN_FLAG_STATES existing-row
 * check. This matters because GradeEntry can republish after a revise, and
 * Enrolment.complete should only ever award once per enrolment.
 *
 * Known limitation: PointRule.scope (cohortId/courseId restriction) is
 * matched against the transitioned object's own cohortId/courseId field when
 * present (Enrolment, GradeEntry). Submission carries neither field directly
 * (only assignmentId) -- resolving Assignment.cohortId would require an
 * extra lookup this change does not add, so a scoped submission-on-time
 * PointRule only matches when its scope is null (tenant-wide). No spec
 * scenario in this change exercises a scoped submission-on-time rule.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/engagement/spec.md#requirement-points-are-awarded-only-for-real-already-firing-events
 * @spec openspec/specs/engagement/spec.md#requirement-pointaward-creation-is-idempotent-and-immutable
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Grading\GradeFormulaEvaluator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Bridges Enrolment.completed / Submission.submitted / GradeEntry.published
 * transitions into idempotency-keyed PointAward rows.
 *
 * @implements IEventListener<Event>
 * @spec       openspec/specs/engagement/spec.md#requirement-points-are-awarded-only-for-real-already-firing-events
 */
class PointAwardTriggerHandler implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const ENROLMENT_SCHEMA = 'enrolment';
	private const SUBMISSION_SCHEMA = 'submission';
	private const GRADE_ENTRY_SCHEMA = 'grade-entry';
	private const POINT_RULE_SCHEMA = 'point-rule';
	private const POINT_AWARD_SCHEMA = 'point-award';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param GradeFormulaEvaluator $evaluator Pass/fail evaluation engine.
	 * @param ITimeFactory $timeFactory NC time source (injectable "now" for tests).
	 * @param ListenerSchemaResolver $schemas Resolves the transition event's register and schema ids to slugs.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly GradeFormulaEvaluator $evaluator,
		private readonly ITimeFactory $timeFactory,
		private readonly ListenerSchemaResolver $schemas,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/engagement/spec.md#requirement-points-are-awarded-only-for-real-already-firing-events
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		if ($this->schemas->eventRegister(event: $event) !== self::LEARNIQ_REGISTER) {
			return;
		}

		$this->dispatch(event: $event);
	}//end handle()

	/**
	 * Hand the transition to the handler of its schema and target state, if any.
	 *
	 * @param ObjectTransitionedEvent $event The learniq transition.
	 *
	 * @return void
	 */
	private function dispatch(ObjectTransitionedEvent $event): void {
		match ($this->schemas->eventSchema(event: $event) . ' -> ' . $event->getTo()) {
			self::ENROLMENT_SCHEMA . ' -> completed' => $this->handleEnrolmentCompleted(event: $event),
			self::SUBMISSION_SCHEMA . ' -> submitted' => $this->handleSubmissionSubmitted(event: $event),
			self::GRADE_ENTRY_SCHEMA . ' -> published' => $this->handleGradeEntryPublished(event: $event),
			default => null,
		};
	}//end dispatch()

	/**
	 * Award enrolment-completed points.
	 *
	 * @param ObjectTransitionedEvent $event The transition event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/engagement/spec.md#scenario-completing-mandatory-training-awards-enrolment-completed-points
	 */
	private function handleEnrolmentCompleted(ObjectTransitionedEvent $event): void {
		$entry = $event->getObject()->jsonSerialize();
		$learnerId = $entry['learnerId'] ?? '';
		$tenantId = $entry['tenant_id'] ?? '';
		$sourceId = $entry['id'] ?? ($entry['uuid'] ?? null);

		if ($learnerId === '' || $sourceId === null) {
			return;
		}

		$this->tryAward(
			source: new PointAwardSource(
				kind: 'enrolment-completed',
				sourceKind: 'enrolment',
				sourceObjectId: $sourceId,
				scope: new PointAwardScope(tenantId: $tenantId, cohortId: $entry['cohortId'] ?? null, courseId: $entry['courseId'] ?? null)
			),
			learnerId: $learnerId
		);

	}//end handleEnrolmentCompleted()

	/**
	 * Award submission-on-time points to every learner on an on-time Submission.
	 *
	 * @param ObjectTransitionedEvent $event The transition event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/engagement/spec.md#scenario-an-on-time-submission-awards-submission-on-time-points-a-late-one-does-not
	 */
	private function handleSubmissionSubmitted(ObjectTransitionedEvent $event): void {
		$entry = $event->getObject()->jsonSerialize();

		if (($entry['isLate'] ?? false) !== false) {
			// Late submissions never award submission-on-time points.
			return;
		}

		$sourceId   = $entry['id'] ?? ($entry['uuid'] ?? null);
		$learnerIds = $entry['learnerIds'] ?? [];
		if ($sourceId === null || is_array($learnerIds) === false) {
			return;
		}

		$this->awardEveryLearner(entry: $entry, learnerIds: $learnerIds);
	}//end handleSubmissionSubmitted()

	/**
	 * Award submission-on-time points to every learner id that is a non-empty string.
	 *
	 * @param array<string, mixed> $entry      The on-time Submission.
	 * @param array<mixed>         $learnerIds Its learner ids.
	 *
	 * @return void
	 */
	private function awardEveryLearner(array $entry, array $learnerIds): void {
		foreach ($learnerIds as $learnerId) {
			if (is_string($learnerId) === false || $learnerId === '') {
				continue;
			}

			$this->tryAward(source: self::onTimeSource(entry: $entry), learnerId: $learnerId);
		}
	}//end awardEveryLearner()

	/**
	 * The award source of an on-time Submission: tenant-wide, since a Submission
	 * carries no cohort or course of its own.
	 *
	 * @param array<string, mixed> $entry The on-time Submission.
	 *
	 * @return PointAwardSource
	 */
	private static function onTimeSource(array $entry): PointAwardSource {
		return new PointAwardSource(
			kind: 'submission-on-time',
			sourceKind: 'submission',
			sourceObjectId: $entry['id'] ?? ($entry['uuid'] ?? null),
			scope: new PointAwardScope(tenantId: $entry['tenant_id'] ?? '', cohortId: null, courseId: null)
		);
	}//end onTimeSource()

	/**
	 * Award finalgrade-passed points when GradeFormulaEvaluator confirms a pass.
	 *
	 * @param ObjectTransitionedEvent $event The transition event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/engagement/spec.md#scenario-a-passing-gradeentry-awards-finalgrade-passed-points
	 */
	private function handleGradeEntryPublished(ObjectTransitionedEvent $event): void {
		$entry = $event->getObject()->jsonSerialize();
		$learnerId = $entry['learnerId'] ?? '';
		$curriculumPlanId = $entry['curriculumPlanId'] ?? '';
		$tenantId = $entry['tenant_id'] ?? '';

		if ($learnerId === '' || $curriculumPlanId === '') {
			return;
		}

		$result = $this->evaluator->evaluate(curriculumPlanId: $curriculumPlanId, learnerId: $learnerId);

		if (($result['passed'] ?? null) !== true) {
			return;
		}

		$this->tryAward(
			source: new PointAwardSource(
				kind: 'finalgrade-passed',
				sourceKind: 'grade-entry',
				sourceObjectId: $curriculumPlanId,
				scope: new PointAwardScope(tenantId: $tenantId, cohortId: $entry['cohortId'] ?? null, courseId: $entry['courseId'] ?? null)
			),
			learnerId: $learnerId
		);

	}//end handleGradeEntryPublished()

	/**
	 * Look up active, scope-matching PointRules of the given kind and create
	 * an idempotency-keyed PointAward for each one not already awarded.
	 *
	 * @param PointAwardSource $source    The event the award is for, with its scope.
	 * @param string           $learnerId NC user ID of the learner to award.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/engagement/spec.md#requirement-pointaward-creation-is-idempotent-and-immutable
	 */
	private function tryAward(PointAwardSource $source, string $learnerId): void {
		$rules = $this->fetchActiveRules(kind: $source->kind, tenantId: $source->scope->tenantId);

		foreach ($rules as $rule) {
			if ($this->ruleScopeMatches(rule: $rule, scope: $source->scope) === false) {
				continue;
			}

			$ruleId = $rule['id'] ?? ($rule['uuid'] ?? null);
			if ($ruleId === null) {
				continue;
			}

			if ($this->hasExistingAward(learnerId: $learnerId, pointRuleId: $ruleId, sourceObjectId: $source->sourceObjectId) === true) {
				continue;
			}

			$this->objectService->saveObject(
				register: self::LEARNIQ_REGISTER,
				schema: self::POINT_AWARD_SCHEMA,
				object: [
					'learnerId' => $learnerId,
					'pointRuleId' => $ruleId,
					'points' => (float)($rule['points'] ?? 0),
					'sourceKind' => $source->sourceKind,
					'sourceObjectId' => $source->sourceObjectId,
					'awardedAt' => DateTimeImmutable::createFromMutable($this->timeFactory->getDateTime())->format(\DATE_ATOM),
					'tenant_id' => $source->scope->tenantId,
				]
			);
		}//end foreach

	}//end tryAward()

	/**
	 * Fetch every active PointRule of the given kind.
	 *
	 * @param string $kind PointRule.kind to match.
	 * @param string $tenantId Tenant identifier.
	 *
	 * @return array<int, array>
	 */
	private function fetchActiveRules(string $kind, string $tenantId): array {
		$results = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::POINT_RULE_SCHEMA,
					'kind' => $kind,
					'lifecycle' => 'active',
					'tenant_id' => $tenantId,
				],
			]
		);

		return array_map(
			static function ($rule) {
				if (is_array($rule) === true) {
					return $rule;
				}

				return $rule->jsonSerialize();
			},
			$results
		);

	}//end fetchActiveRules()

	/**
	 * True when the rule's scope (if any) matches the transitioned object's
	 * own cohortId/courseId. A null scope always matches (tenant-wide).
	 *
	 * @param array<string,mixed> $rule  PointRule data.
	 * @param PointAwardScope     $scope The transitioned object's own tenant, cohort and course.
	 *
	 * @return bool
	 */
	private function ruleScopeMatches(array $rule, PointAwardScope $scope): bool {
		$ruleScope = $rule['scope'] ?? null;
		if (is_array($ruleScope) === false || empty($ruleScope) === true) {
			return true;
		}

		return self::restrictionAllows(restriction: $ruleScope['cohortId'] ?? null, actual: $scope->cohortId)
			&& self::restrictionAllows(restriction: $ruleScope['courseId'] ?? null, actual: $scope->courseId);
	}//end ruleScopeMatches()

	/**
	 * Whether one scope restriction lets the object through: an absent or empty
	 * restriction always does, a set one only for the same id.
	 *
	 * @param mixed       $restriction The rule's cohortId or courseId.
	 * @param string|null $actual      The object's own cohortId or courseId.
	 *
	 * @return bool
	 */
	private static function restrictionAllows(mixed $restriction, ?string $actual): bool {
		if (is_string($restriction) === false || $restriction === '') {
			return true;
		}

		return $restriction === $actual;
	}//end restrictionAllows()

	/**
	 * Check whether a PointAward already exists for this
	 * (learnerId, pointRuleId, sourceObjectId) triple.
	 *
	 * @param string $learnerId NC user ID.
	 * @param string $pointRuleId UUID of the PointRule.
	 * @param string $sourceObjectId The idempotency-key source object id.
	 *
	 * @return bool
	 */
	private function hasExistingAward(string $learnerId, string $pointRuleId, string $sourceObjectId): bool {
		$existing = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::POINT_AWARD_SCHEMA,
					'learnerId' => $learnerId,
					'pointRuleId' => $pointRuleId,
					'sourceObjectId' => $sourceObjectId,
				],
				'limit' => 1,
			]
		);

		return empty($existing) === false;
	}//end hasExistingAward()
}//end class
