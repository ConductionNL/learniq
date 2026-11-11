<?php

/**
 * Learniq Session Change Notice Handler
 *
 * IEventListener for Session lifecycle -> `cancel` / `substitute-teacher` /
 * `substitute-teacher-in-progress` (the OR ObjectTransitionedEvent with
 * register=learniq, schema=session, action in that set). Materialises
 * `affectedLearnerIds` / `affectedParentIds` / `changedAt` onto the Session so
 * the verified `x-openregister-notifications` dialect's `kind:field`
 * recipients can resolve them without a runtime join — mirrors
 * `ConferenceRound.invitedLearnerIds`'s documented shape exactly, the same
 * two-hop cross-schema-join rationale already accepted for
 * `BsaProgressEvaluator`/`GradeFormulaEvaluator`:
 *
 * 1. Resolve the Session's `Cohort.learnerIds` (single hop) into
 *    `affectedLearnerIds`.
 * 2. Resolve each of those learners' `LearnerProfile.parentIds` (a second
 *    hop) into `affectedParentIds` (deduplicated).
 * 3. Persist both arrays plus a server-stamped `changedAt` onto the Session
 *    via `ObjectService::saveObject` — a genuine cross-object write bridge,
 *    the same ADR-031 exception class as `ConferenceScheduleGenerator`. This
 *    is a plain field-update save, never another lifecycle transition, and it
 *    NEVER touches `lifecycle`, `roomId`, `startsAt`, or `endsAt`.
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
 * @spec openspec/specs/timetabling/spec.md#requirement-cancellation-or-substitution-notifies-affected-learners-and-parents
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Materialises affectedLearnerIds/affectedParentIds/changedAt on a Session
 * cancelled or substituted, ahead of the declared transition notification.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-cancellation-or-substitution-notifies-affected-learners-and-parents
 */
class SessionChangeNoticeHandler implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const SESSION_SCHEMA = 'session';
	private const COHORT_SCHEMA = 'cohort';
	private const LEARNER_PROFILE_SCHEMA = 'learner-profile';

	/**
	 * Transition action names this handler reacts to.
	 *
	 * @var string[]
	 */
	private const WATCHED_ACTIONS = ['cancel', 'substitute-teacher', 'substitute-teacher-in-progress'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param LoggerInterface $logger PSR logger.
	 * @param ListenerSchemaResolver $schemas Resolves the transition event's register and schema ids to slugs.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
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
	 * @spec openspec/specs/timetabling/spec.md#scenario-cancelling-a-session-notifies-every-affected-learner-and-parent
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false || $this->isWatchedTransition(event: $event) === false) {
			return;
		}

		$session = $event->getObject()->jsonSerialize();

		// A lesson changed in a batch sends no message of its own: its
		// affected lists stay empty and the batch sends one message for all
		// its lessons (timetabling-bulk-change-weeks).
		if ($this->isInBatch(session: $session) === true) {
			return;
		}

		$this->materialiseAffected(session: $session);

	}//end handle()

	/**
	 * Whether a transition is a watched action on one of Learniq's sessions.
	 *
	 * @param ObjectTransitionedEvent $event The transition.
	 *
	 * @return bool
	 */
	private function isWatchedTransition(ObjectTransitionedEvent $event): bool {
		return $this->schemas->eventRegister(event: $event) === self::LEARNIQ_REGISTER
			&& $this->schemas->eventSchema(event: $event) === self::SESSION_SCHEMA
			&& in_array($event->getAction(), self::WATCHED_ACTIONS, true) === true;
	}//end isWatchedTransition()

	/**
	 * Whether a lesson was changed as part of a batch.
	 *
	 * @param array<string,mixed> $session The Session data.
	 *
	 * @return bool
	 */
	private function isInBatch(array $session): bool {
		return is_string($session['changeBatchId'] ?? null) === true && $session['changeBatchId'] !== '';
	}//end isInBatch()

	/**
	 * The learners and parents a change to this lesson affects, without
	 * writing anything.
	 *
	 * @param array<string,mixed> $session The Session data.
	 *
	 * @return array{learnerIds: array<int,string>, parentIds: array<int,string>}
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-affected-people-get-one-message-per-batch
	 */
	public function affectedPeople(array $session): array {
		$tenantId = (string)($session['tenant_id'] ?? '');
		$learnerIds = $this->resolveCohortLearnerIds(cohortId: (string)($session['cohortId'] ?? ''), tenantId: $tenantId);

		return [
			'learnerIds' => $learnerIds,
			'parentIds' => $this->resolveParentIds(learnerIds: $learnerIds, tenantId: $tenantId),
		];
	}//end affectedPeople()

	/**
	 * Resolve and persist affectedLearnerIds/affectedParentIds/changedAt onto the Session.
	 *
	 * @param array<string,mixed> $session The Session data after the transition.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/timetabling/spec.md#scenario-cancelling-a-session-notifies-every-affected-learner-and-parent
	 */
	private function materialiseAffected(array $session): void {
		$sessionId = (string)($session['id'] ?? ($session['uuid'] ?? ''));
		if ($sessionId === '') {
			$this->logger->warning('[SessionChangeNoticeHandler] Session has no id; aborting.');
			return;
		}

		$cohortId = (string)($session['cohortId'] ?? '');
		$tenantId = (string)($session['tenant_id'] ?? '');

		$learnerIds = $this->resolveCohortLearnerIds(cohortId: $cohortId, tenantId: $tenantId);
		$parentIds = $this->resolveParentIds(learnerIds: $learnerIds, tenantId: $tenantId);

		$session['affectedLearnerIds'] = $learnerIds;
		$session['affectedParentIds'] = $parentIds;
		$session['changedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::SESSION_SCHEMA,
			object: $session
		);

		$this->logger->info(
			'[SessionChangeNoticeHandler] Session {id}: {l} affected learner(s), {p} affected parent(s).',
			['id' => $sessionId, 'l' => count($learnerIds), 'p' => count($parentIds)]
		);

	}//end materialiseAffected()

	/**
	 * Resolve a Cohort's learnerIds.
	 *
	 * @param string $cohortId Cohort UUID.
	 * @param string $tenantId Tenant ID to enforce as a mandatory filter.
	 *
	 * @return array<int,string> The cohort's learner Nextcloud user ids.
	 */
	private function resolveCohortLearnerIds(string $cohortId, string $tenantId): array {
		if ($cohortId === '') {
			return [];
		}

		$filters = [];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$cohort = $this->firstObject(schema: self::COHORT_SCHEMA, query: ['ids' => [$cohortId], 'filters' => $filters]);
		if ($cohort === null) {
			return [];
		}

		return $this->distinctIds(ids: ($cohort['learnerIds'] ?? []));
	}//end resolveCohortLearnerIds()

	/**
	 * Resolve the deduplicated union of parentIds across every learner's LearnerProfile.
	 *
	 * @param array<int,string> $learnerIds Affected learner Nextcloud user ids.
	 * @param string $tenantId Tenant ID to enforce as a mandatory filter.
	 *
	 * @return array<int,string> The deduplicated parent/guardian Nextcloud user ids.
	 */
	private function resolveParentIds(array $learnerIds, string $tenantId): array {
		$parentIds = [];

		foreach ($learnerIds as $learnerId) {
			foreach ($this->profileParentIds(learnerId: $learnerId, tenantId: $tenantId) as $parentId) {
				$parentIds[$parentId] = true;
			}
		}

		return array_keys($parentIds);
	}//end resolveParentIds()

	/**
	 * The parent ids on one learner's LearnerProfile in the tenant.
	 *
	 * @param string $learnerId The learner's Nextcloud user id.
	 * @param string $tenantId  Tenant ID to enforce as a mandatory filter.
	 *
	 * @return array<int,string> The profile's distinct non-empty parent ids.
	 */
	private function profileParentIds(string $learnerId, string $tenantId): array {
		$filters = ['ncUserId' => $learnerId];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$profile = $this->firstObject(schema: self::LEARNER_PROFILE_SCHEMA, query: ['filters' => $filters]);
		if ($profile === null) {
			return [];
		}

		return $this->distinctIds(ids: ($profile['parentIds'] ?? []));
	}//end profileParentIds()

	/**
	 * The distinct non-empty string ids of a stored list, or none when it is not a list.
	 *
	 * @param mixed $ids The stored value.
	 *
	 * @return array<int,string>
	 */
	private function distinctIds(mixed $ids): array {
		if (is_array($ids) === false) {
			return [];
		}

		return array_values(array_unique(array_filter($ids, static fn ($id) => is_string($id) === true && $id !== '')));
	}//end distinctIds()

	/**
	 * The first object of one of Learniq's schemas a query finds, as a row,
	 * or null when it finds none. The query's filters gain the register and
	 * schema, and it reads one object.
	 *
	 * @param string              $schema The schema.
	 * @param array<string,mixed> $query  The query, with its `filters`.
	 *
	 * @return array<string,mixed>|null
	 */
	private function firstObject(string $schema, array $query): ?array {
		$scoped = ['register' => self::LEARNIQ_REGISTER, 'schema' => $schema];
		$results = $this->objectService->findAll(
			array_merge($query, ['filters' => array_merge($query['filters'], $scoped), 'limit' => 1])
		);
		if (empty($results) === true) {
			return null;
		}

		$row = $results[0];
		if (is_array($row) === false) {
			$row = $row->jsonSerialize();
		}

		return $row;
	}//end firstObject()
}//end class
