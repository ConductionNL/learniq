<?php

/**
 * Learniq Work Group Reader
 *
 * Reads work groups and their cohorts as the system
 * (enrolment-self-join-work-group), for the membership rules and for the
 * learner's own overview: the sets of every cohort whose `learnerIds` hold
 * the learner, each group with its free places and its members' names (what
 * a class sees on the board; the open point in design.md). User ids are
 * returned only for the learner's own group, for the group hand-in. Learners cannot
 * read WorkGroup through the object API, so this is their only view.
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

use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;

/**
 * Work group reads.
 *
 * @spec openspec/changes/enrolment-self-join-work-group/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
 */
class WorkGroupReader {

	private const REGISTER = 'learniq';
	private const SCHEMA = 'work-group';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objects OpenRegister object access.
	 * @param IUserManager  $users   Display names of members.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly IUserManager $users,
	) {
	}//end __construct()

	/**
	 * One group, or null.
	 *
	 * @param string $id The WorkGroup uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/enrolment-self-join-work-group/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
	 */
	public function group(string $id): ?array {
		return $this->read(schema: self::SCHEMA, id: $id);
	}//end group()

	/**
	 * The learner ids of a cohort.
	 *
	 * @param string $cohortId The cohort uuid.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/enrolment-self-join-work-group/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
	 */
	public function cohortLearners(string $cohortId): array {
		$cohort = $this->read(schema: 'cohort', id: $cohortId);

		return array_values(array_filter((array)($cohort['learnerIds'] ?? []), 'is_string'));
	}//end cohortLearners()

	/**
	 * Every group of one set of a cohort.
	 *
	 * @param string $cohortId The cohort uuid.
	 * @param string $setName  The set.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/enrolment-self-join-work-group/specs/enrolment/spec.md#requirement-a-learner-is-in-one-work-group-per-set
	 */
	public function set(string $cohortId, string $setName): array {
		return $this->groups(filters: ['cohortId' => $cohortId, 'setName' => $setName]);
	}//end set()

	/**
	 * The learner's overview: per cohort and set, the groups with free
	 * places, members and whether the learner is in it.
	 *
	 * @param string   $userId The learner.
	 * @param callable $isOpen fn(array $group): bool, whether learners may still change it.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/enrolment-self-join-work-group/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
	 */
	public function mine(string $userId, callable $isOpen): array {
		$sets = [];
		$cohorts = [];
		foreach ($this->groups(filters: []) as $group) {
			$cohortId = (string)($group['cohortId'] ?? '');
			$cohorts[$cohortId] = ($cohorts[$cohortId] ?? $this->read(schema: 'cohort', id: $cohortId));
			if (in_array($userId, (array)($cohorts[$cohortId]['learnerIds'] ?? []), true) === false) {
				continue;
			}

			$key = $cohortId . '|' . (string)($group['setName'] ?? '');
			$sets[$key] = ($sets[$key] ?? [
				'cohortId' => $cohortId,
				'cohortName' => (string)($cohorts[$cohortId]['name'] ?? ''),
				'setName' => (string)($group['setName'] ?? ''),
				'selfJoinUntil' => $group['selfJoinUntil'] ?? null,
				'open' => $isOpen($group),
				'groups' => [],
			]);
			$sets[$key]['groups'][] = $this->card(group: $group, userId: $userId);
		}

		return array_values($sets);
	}//end mine()

	/**
	 * A group as the learner sees it.
	 *
	 * @param array<string, mixed> $group  The group.
	 * @param string               $userId The learner.
	 *
	 * @return array<string, mixed>
	 */
	private function card(array $group, string $userId): array {
		$members = array_values(array_filter((array)($group['memberIds'] ?? []), 'is_string'));
		$max = (int)($group['maxMembers'] ?? 0);

		$mine = in_array($userId, $members, true);
		$memberIds = [];
		if ($mine === true) {
			$memberIds = $members;
		}

		return [
			'id' => (string)$group['id'],
			'name' => (string)($group['name'] ?? ''),
			'maxMembers' => $max,
			'free' => max(0, $max - count($members)),
			'members' => array_map(fn (string $id): string => (string)($this->users->getDisplayName($id) ?? $id), $members),
			'mine' => $mine,
			'memberIds' => $memberIds,
		];
	}//end card()

	/**
	 * Groups by filters, read as the system.
	 *
	 * @param array<string, string> $filters Property filters.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function groups(array $filters): array {
		$rows = $this->objects->findAll(
			config: ['filters' => array_merge(['register' => self::REGISTER, 'schema' => self::SCHEMA], $filters), 'limit' => 2000],
			_rbac: false
		);

		$groups = [];
		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$row['id'] = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
				$groups[] = $row;
			}
		}

		usort($groups, static fn (array $a, array $b): int => strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));

		return $groups;
	}//end groups()

	/**
	 * One learniq object by uuid as an array, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objects->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false, _multitenancy: false, _render: false);
		} catch (DoesNotExistException) {
			return null;
		}

		$row = $object?->jsonSerialize();
		if (is_array($row) === false) {
			return null;
		}

		$row['id'] = (string)($row['id'] ?? ($row['@self']['id'] ?? $id));

		return $row;
	}//end read()
}//end class
