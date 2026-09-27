<?php

/**
 * Learniq Cohort Group Provisioning Handler
 *
 * Listens for OpenRegister's ObjectTransitionedEvent and:
 *
 * 1. On Cohort `activate` (planned -> active): provisions a real Nextcloud
 *    group backing the Cohort (OCP\IGroupManager::createGroup()), adds every
 *    resolvable id in `teacherIds`/`learnerIds` as a member, and writes the
 *    provisioned group's id back onto `Cohort.ncGroupId` via
 *    ObjectService::saveObject(). Idempotent: a Cohort whose `ncGroupId` is
 *    already set is not provisioned again.
 * 2. On Enrolment `activate`/`withdraw`: resolves the Enrolment's Cohort; if
 *    that Cohort has already been provisioned (`ncGroupId` set), adds or
 *    removes the Enrolment's `learnerId` from the Cohort's Nextcloud group.
 *    No-ops (logged) when the Cohort has not been provisioned yet.
 *
 * `Cohort.ncGroupId` was previously a field nothing populated —
 * `CohortMembershipGuard`'s own class docblock deferred "full NC group
 * synchronisation" to "a separate event listener or manual admin action."
 * This is that listener. Mirrors `CohortTalkMembershipHandler`'s shape
 * exactly (constructor-injected collaborators, fail-soft on every degraded
 * path, never throws, never blocks the triggering transition).
 *
 * ADR-031 legitimate exception: external-API bridge (Nextcloud group
 * provisioning/membership) with a cross-object lookup (Enrolment.cohortId ->
 * Cohort.ncGroupId) not expressible as a schema declaration. Same category as
 * `CohortTalkMembershipHandler`.
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
 * @spec openspec/changes/cohort-group-provisioning/specs/school-structure/spec.md#requirement-cohort-activation-provisions-and-maintains-a-real-nextcloud-group
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Bridges Cohort.activate -> NC group provisioning, and Enrolment
 * activate/withdraw -> that group's membership sync.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/cohort-group-provisioning/specs/school-structure/spec.md#requirement-cohort-activation-provisions-and-maintains-a-real-nextcloud-group
 */
class CohortGroupProvisioningHandler implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const COHORT_SCHEMA = 'cohort';
	private const ENROLMENT_SCHEMA = 'enrolment';
	private const ACTION_ACTIVATE = 'activate';
	private const ACTION_WITHDRAW = 'withdraw';

	/**
	 * Prefix for the deterministic, UUID-derived NC group id — deliberately
	 * distinct from RolloverExecutionService::groupName()'s human-readable
	 * computed name (design.md Decision 3).
	 */
	private const GROUP_ID_PREFIX = 'learniq-cohort-';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param IGroupManager $groupManager NC group manager — creates/resolves the backing group.
	 * @param IUserManager $userManager NC user manager, resolving Cohort/Enrolment
	 *                                  user ids to an `IUser` (mirrors
	 *                                  `CohortTalkMembershipHandler::resolveUser()`).
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cohort-group-provisioning/specs/school-structure/spec.md#scenario-activating-a-cohort-provisions-its-nextcloud-group
	 * @spec openspec/changes/cohort-group-provisioning/specs/school-structure/spec.md#scenario-enrolling-a-learner-into-an-active-cohort-adds-them-to-its-group
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		if ($event->getRegister() !== self::LEARNIQ_REGISTER) {
			return;
		}

		$schema = $event->getSchema();
		$action = $event->getAction();

		if ($schema === self::COHORT_SCHEMA && $action === self::ACTION_ACTIVATE) {
			$this->provisionGroup(cohort: $event->getObject()->jsonSerialize());
			return;
		}

		if ($schema === self::ENROLMENT_SCHEMA
			&& ($action === self::ACTION_ACTIVATE || $action === self::ACTION_WITHDRAW)
		) {
			$this->syncMembership(
				enrolment: $event->getObject()->jsonSerialize(),
				add: $action === self::ACTION_ACTIVATE
			);
		}

	}//end handle()

	/**
	 * Provision the Cohort's Nextcloud group and write its id back. Idempotent:
	 * skips when `ncGroupId` is already set.
	 *
	 * @param array<string,mixed> $cohort The Cohort data after the `activate` transition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cohort-group-provisioning/specs/school-structure/spec.md#scenario-activating-an-already-provisioned-cohort-does-not-provision-a-second-group
	 */
	private function provisionGroup(array $cohort): void {
		$cohortId = (string)($cohort['id'] ?? ($cohort['uuid'] ?? ''));
		if ($cohortId === '') {
			$this->logger->warning('[CohortGroupProvisioningHandler] Cohort has no id; aborting provisioning.');
			return;
		}

		$existingGroupId = $cohort['ncGroupId'] ?? null;
		if (is_string($existingGroupId) === true && $existingGroupId !== '') {
			$this->logger->debug(
				'[CohortGroupProvisioningHandler] Cohort {id} already has ncGroupId {gid}; skipping provisioning.',
				['id' => $cohortId, 'gid' => $existingGroupId]
			);
			return;
		}

		$groupId = self::GROUP_ID_PREFIX . $cohortId;

		try {
			$group = $this->createOrGetGroup(groupId: $groupId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[CohortGroupProvisioningHandler] Failed to create/resolve NC group {gid} for Cohort {id}: {msg}',
				['gid' => $groupId, 'id' => $cohortId, 'msg' => $e->getMessage()]
			);
			return;
		}

		if ($group === null) {
			$this->logger->warning(
				'[CohortGroupProvisioningHandler] NC group {gid} could not be created for Cohort {id}.',
				['gid' => $groupId, 'id' => $cohortId]
			);
			return;
		}

		$memberIds = array_values(array_unique(array_merge(
			$this->stringIds(ids: $cohort['teacherIds'] ?? []),
			$this->stringIds(ids: $cohort['learnerIds'] ?? [])
		)));

		foreach ($memberIds as $userId) {
			$user = $this->resolveUser(userId: $userId);
			if ($user !== null) {
				$group->addUser($user);
			}
		}

		$cohort['ncGroupId'] = $groupId;
		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::COHORT_SCHEMA,
			object: $cohort
		);

		$this->logger->info(
			'[CohortGroupProvisioningHandler] Provisioned NC group {gid} for Cohort {id} with {n} member(s).',
			['gid' => $groupId, 'id' => $cohortId, 'n' => count($memberIds)]
		);

	}//end provisionGroup()

	/**
	 * Add or remove an Enrolment's learner from its Cohort's Nextcloud group.
	 * No-ops when the Cohort has not been provisioned yet.
	 *
	 * @param array<string,mixed> $enrolment The Enrolment data after the transition.
	 * @param bool $add True to add (activate), false to remove (withdraw).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cohort-group-provisioning/specs/school-structure/spec.md#scenario-withdrawing-an-enrolment-removes-the-learner-from-the-group
	 * @spec openspec/changes/cohort-group-provisioning/specs/school-structure/spec.md#scenario-an-enrolment-change-on-a-not-yet-provisioned-cohort-is-a-no-op-not-an-error
	 */
	private function syncMembership(array $enrolment, bool $add): void {
		$cohortId = (string)($enrolment['cohortId'] ?? '');
		$learnerId = (string)($enrolment['learnerId'] ?? '');

		if ($cohortId === '' || $learnerId === '') {
			// No cohort association (individually enrolled learner) or a
			// malformed event payload — nothing to sync.
			return;
		}

		$group = $this->resolveProvisionedGroup(cohortId: $cohortId, learnerId: $learnerId);
		if ($group === null) {
			return;
		}

		$user = $this->resolveUser(userId: $learnerId);
		if ($user === null) {
			$this->logger->debug(
				'[CohortGroupProvisioningHandler] Learner {learner} does not resolve to an NC user — skipping membership sync.',
				['learner' => $learnerId]
			);
			return;
		}

		$this->applyMembership(group: $group, user: $user, add: $add, cohortId: $cohortId, learnerId: $learnerId);

	}//end syncMembership()

	/**
	 * Resolve a Cohort's already-provisioned Nextcloud group, or null when the
	 * Cohort/group cannot be resolved for any reason (not found, not yet
	 * provisioned, or the group has since vanished). Every no-op path logs.
	 *
	 * @param string $cohortId Cohort UUID.
	 * @param string $learnerId Learner id, for log context only.
	 *
	 * @return object|null The `IGroup`, or null.
	 */
	private function resolveProvisionedGroup(string $cohortId, string $learnerId): ?object {
		$cohort = $this->findCohort(cohortId: $cohortId);
		if ($cohort === null) {
			$this->logger->debug(
				'[CohortGroupProvisioningHandler] Cohort {id} not found — skipping membership sync for learner {learner}.',
				['id' => $cohortId, 'learner' => $learnerId]
			);
			return null;
		}

		$groupId = $cohort['ncGroupId'] ?? null;
		if (is_string($groupId) === false || $groupId === '') {
			$this->logger->debug(
				'[CohortGroupProvisioningHandler] Cohort {id} has no ncGroupId yet — skipping membership sync for learner {learner}.',
				['id' => $cohortId, 'learner' => $learnerId]
			);
			return null;
		}

		try {
			$group = $this->groupManager->get($groupId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[CohortGroupProvisioningHandler] Failed to resolve NC group {gid}: {msg}',
				['gid' => $groupId, 'msg' => $e->getMessage()]
			);
			return null;
		}

		if ($group === null) {
			$this->logger->warning(
				'[CohortGroupProvisioningHandler] NC group {gid} (Cohort {id}) no longer exists — skipping membership sync.',
				['gid' => $groupId, 'id' => $cohortId]
			);
		}

		return $group;

	}//end resolveProvisionedGroup()

	/**
	 * Add or remove a resolved user from a resolved group, and log the result.
	 *
	 * @param object $group The `IGroup` to update.
	 * @param object $user The `IUser` to add or remove.
	 * @param bool $add True to add, false to remove.
	 * @param string $cohortId Cohort UUID, for log context.
	 * @param string $learnerId Learner id, for log context.
	 *
	 * @return void
	 */
	private function applyMembership(object $group, object $user, bool $add, string $cohortId, string $learnerId): void {
		$actionLabel = 'Removed';
		$prepositionLabel = 'from';

		if ($add === true) {
			$group->addUser($user);
			$actionLabel = 'Added';
			$prepositionLabel = 'to';
		}

		if ($add === false) {
			$group->removeUser($user);
		}

		$this->logger->info(
			'[CohortGroupProvisioningHandler] {action} learner {learner} {prep} NC group {gid} (Cohort {id}).',
			[
				'action' => $actionLabel,
				'learner' => $learnerId,
				'prep' => $prepositionLabel,
				'gid' => $group->getGID(),
				'id' => $cohortId,
			]
		);

	}//end applyMembership()

	/**
	 * Resolve an existing NC group by id, or create it when it does not exist yet.
	 *
	 * @param string $groupId The deterministic NC group id.
	 *
	 * @return object|null The `IGroup`, or null when creation failed.
	 */
	private function createOrGetGroup(string $groupId): ?object {
		if ($this->groupManager->groupExists($groupId) === true) {
			return $this->groupManager->get($groupId);
		}

		return $this->groupManager->createGroup($groupId);

	}//end createOrGetGroup()

	/**
	 * Look up a Cohort by id.
	 *
	 * @param string $cohortId Cohort UUID.
	 *
	 * @return array<string,mixed>|null The Cohort data, or null when not found.
	 */
	private function findCohort(string $cohortId): ?array {
		$results = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => self::COHORT_SCHEMA,
				'filters' => ['id' => $cohortId],
				'limit' => 1,
			]
		);

		if (empty($results) === true) {
			return null;
		}

		$cohort = $results[0];
		if (is_array($cohort) === false) {
			$cohort = $cohort->jsonSerialize();
		}

		return $cohort;

	}//end findCohort()

	/**
	 * Resolve an `IUser` by Nextcloud user id. Mirrors
	 * `CohortTalkMembershipHandler::resolveUser()` — fails soft, never throws.
	 *
	 * @param string $userId Nextcloud user id.
	 *
	 * @return object|null The `IUser`, or null when not found.
	 */
	private function resolveUser(string $userId): ?object {
		if ($userId === '') {
			return null;
		}

		try {
			return $this->userManager->get($userId);
		} catch (Throwable $e) {
			$this->logger->debug(
				'[CohortGroupProvisioningHandler] Failed to resolve IUser for {userId}: {msg}',
				['userId' => $userId, 'msg' => $e->getMessage()]
			);
			return null;
		}

	}//end resolveUser()

	/**
	 * Filter a mixed array down to its non-empty string values.
	 *
	 * @param mixed $ids Raw property value (expected to be a string array).
	 *
	 * @return array<int,string>
	 */
	private function stringIds(mixed $ids): array {
		if (is_array($ids) === false) {
			return [];
		}

		return array_values(array_filter($ids, static fn ($id) => is_string($id) === true && $id !== ''));

	}//end stringIds()
}//end class
