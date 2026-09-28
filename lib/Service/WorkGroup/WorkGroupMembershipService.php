<?php

/**
 * Learniq Work Group Membership Service
 *
 * Learners join, move between and leave work groups themselves
 * (enrolment-self-join-work-group). Every rule is checked here before a write:
 * the group is `open` and its sign-up date has not passed; the learner is in
 * the cohort's `learnerIds`; a join finds a free place after a fresh read,
 * under a lock per group so two learners cannot both take the last place; a
 * learner is in at most one group of a set, so joining another group of the
 * same set moves them in one call.
 *
 * Learners may not write a WorkGroup through the object API (or they could
 * add a friend, or overfill a group), so the writes skip that check after
 * these rules and run inside `ObjectService::runAs()` for the learner, the
 * receiver pattern of learniq #1096 and #1142, for the app and the portal.
 *
 * @category Service
 * @package  OCA\Learniq\Service\WorkGroup
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
 * @spec openspec/changes/enrolment-self-join-work-group/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\WorkGroup;

use DateTimeImmutable;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use Throwable;

/**
 * Join, move and leave for one learner.
 *
 * @spec openspec/changes/enrolment-self-join-work-group/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
 */
class WorkGroupMembershipService {

	private const REGISTER = 'learniq';
	private const SCHEMA = 'work-group';

	/**
	 * Constructor.
	 *
	 * @param ObjectService    $objects OpenRegister object access.
	 * @param WorkGroupReader  $reader  Groups and cohorts, read as the system.
	 * @param ILockingProvider $locks   Serialises joins per group.
	 * @param ITimeFactory     $time    The clock.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly WorkGroupReader $reader,
		private readonly ILockingProvider $locks,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The learner's overview of their classes' work groups.
	 *
	 * @param PortalLearner $learner The learner.
	 *
	 * @return PortalOutcome 200 `{sets}`.
	 *
	 * @spec openspec/changes/enrolment-self-join-work-group/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
	 */
	public function mine(PortalLearner $learner): PortalOutcome {
		return new PortalOutcome(status: Http::STATUS_OK, body: ['sets' => $this->reader->mine(userId: $learner->ncUserId, isOpen: $this->isOpen(...))]);
	}//end mine()

	/**
	 * Join a group, leaving the learner's other group of the same set.
	 *
	 * @param PortalLearner $learner The learner.
	 * @param string        $groupId The WorkGroup uuid.
	 *
	 * @return PortalOutcome 200 `{groupId, left}`, or 403 / 404 / 409 / 422 with a reason.
	 *
	 * @spec openspec/changes/enrolment-self-join-work-group/specs/enrolment/spec.md#scenario-a-full-group-takes-nobody-more
	 * @spec openspec/changes/enrolment-self-join-work-group/specs/enrolment/spec.md#requirement-a-learner-is-in-one-work-group-per-set
	 */
	public function join(PortalLearner $learner, string $groupId): PortalOutcome {
		$key = 'learniq-work-group-' . $groupId;
		$this->locks->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			return $this->joinLocked(learner: $learner, groupId: $groupId);
		} finally {
			$this->locks->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}//end join()

	/**
	 * Leave a group.
	 *
	 * @param PortalLearner $learner The learner.
	 * @param string        $groupId The WorkGroup uuid.
	 *
	 * @return PortalOutcome 200 `{groupId}`, or a refusal.
	 *
	 * @spec openspec/changes/enrolment-self-join-work-group/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
	 */
	public function leave(PortalLearner $learner, string $groupId): PortalOutcome {
		$group = $this->openGroupFor(learner: $learner, groupId: $groupId);
		if ($group instanceof PortalOutcome) {
			return $group;
		}

		if (in_array($learner->ncUserId, $this->members(group: $group), true) === false) {
			return new PortalOutcome(status: Http::STATUS_CONFLICT, body: ['error' => 'not_a_member'], reason: 'not-a-member');
		}

		$this->save(learner: $learner, group: $group, members: array_values(array_diff($this->members(group: $group), [$learner->ncUserId])));

		return new PortalOutcome(status: Http::STATUS_OK, body: ['groupId' => $groupId]);
	}//end leave()

	/**
	 * The join after the lock is held: every rule on a fresh read.
	 *
	 * @param PortalLearner $learner The learner.
	 * @param string        $groupId The WorkGroup uuid.
	 *
	 * @return PortalOutcome
	 */
	private function joinLocked(PortalLearner $learner, string $groupId): PortalOutcome {
		$group = $this->openGroupFor(learner: $learner, groupId: $groupId);
		if ($group instanceof PortalOutcome) {
			return $group;
		}

		$members = $this->members(group: $group);
		if (in_array($learner->ncUserId, $members, true) === true) {
			return new PortalOutcome(status: Http::STATUS_OK, body: ['groupId' => $groupId, 'left' => null]);
		}

		if (count($members) >= (int)($group['maxMembers'] ?? 0)) {
			return new PortalOutcome(status: Http::STATUS_CONFLICT, body: ['error' => 'full'], reason: 'full');
		}

		$left = null;
		foreach ($this->reader->set(cohortId: (string)$group['cohortId'], setName: (string)($group['setName'] ?? '')) as $other) {
			if ($other['id'] !== $groupId && in_array($learner->ncUserId, $this->members(group: $other), true) === true) {
				$this->save(learner: $learner, group: $other, members: array_values(array_diff($this->members(group: $other), [$learner->ncUserId])));
				$left = (string)$other['id'];
			}
		}

		$members[] = $learner->ncUserId;
		$this->save(learner: $learner, group: $group, members: $members);

		return new PortalOutcome(status: Http::STATUS_OK, body: ['groupId' => $groupId, 'left' => $left]);
	}//end joinLocked()

	/**
	 * The group when it is open for the learner's own changes, or the refusal.
	 *
	 * @param PortalLearner $learner The learner.
	 * @param string        $groupId The WorkGroup uuid.
	 *
	 * @return array<string, mixed>|PortalOutcome
	 */
	private function openGroupFor(PortalLearner $learner, string $groupId): array|PortalOutcome {
		$group = $this->reader->group(id: $groupId);
		if ($group === null) {
			return new PortalOutcome(status: Http::STATUS_NOT_FOUND, body: ['error' => 'not_found'], reason: 'not-found');
		}

		if (in_array($learner->ncUserId, $this->reader->cohortLearners(cohortId: (string)($group['cohortId'] ?? '')), true) === false) {
			return new PortalOutcome(status: Http::STATUS_FORBIDDEN, body: ['error' => 'not_in_cohort'], reason: 'not-in-cohort');
		}

		if ($this->isOpen(group: $group) === false) {
			return new PortalOutcome(status: Http::STATUS_UNPROCESSABLE_ENTITY, body: ['error' => 'sign_up_closed'], reason: 'sign-up-closed');
		}

		return $group;
	}//end openGroupFor()

	/**
	 * Whether learners may still change their membership: lifecycle open, a
	 * sign-up date set and not passed.
	 *
	 * @param array<string, mixed> $group The group.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/enrolment-self-join-work-group/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
	 */
	public function isOpen(array $group): bool {
		if (($group['lifecycle'] ?? 'open') !== 'open') {
			return false;
		}

		$until = $group['selfJoinUntil'] ?? null;
		if (is_string($until) === false || $until === '') {
			return false;
		}

		try {
			return $this->time->getTime() <= (new DateTimeImmutable($until))->getTimestamp();
		} catch (Throwable) {
			return false;
		}
	}//end isOpen()

	/**
	 * The members of a group.
	 *
	 * @param array<string, mixed> $group The group.
	 *
	 * @return list<string>
	 */
	private function members(array $group): array {
		return array_values(array_filter((array)($group['memberIds'] ?? []), 'is_string'));
	}//end members()

	/**
	 * Write a group's members as the learner.
	 *
	 * @param PortalLearner        $learner The learner.
	 * @param array<string, mixed> $group   The group.
	 * @param list<string>         $members The new members.
	 *
	 * @return void
	 */
	private function save(PortalLearner $learner, array $group, array $members): void {
		$row = $group;
		unset($row['@self']);
		$row['memberIds'] = $members;
		$this->objects->runAs(
			user: $learner->user,
			operation: fn () => $this->objects->saveObject(object: $row, register: self::REGISTER, schema: self::SCHEMA, uuid: (string)$group['id'], _rbac: false)
		);
	}//end save()
}//end class
