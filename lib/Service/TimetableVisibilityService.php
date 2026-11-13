<?php

/**
 * Learniq Timetable Visibility Service
 *
 * Whose timetables a caller may open (timetabling-visibility-rules). A school
 * sets one TimetableVisibilityPolicy per tenant: per role (learner,
 * instructor) and per kind (group, teacher, room) `own`, `related` or `all`.
 * Without a policy the defaults apply. Team leads, compliance officers and
 * admins always see everything.
 *
 * "Own" and "related" are computed from the caller's own timetable: the
 * cohorts they learn or teach in, those cohorts' teachers, and the rooms of
 * those cohorts' lessons. The register grammar cannot express a rule that
 * depends on the caller's timetable, so this check is imperative, in the
 * endpoint that serves another timetable.
 *
 * Lessons come from the current timetable source: planninq's school
 * timetable when planninq is installed (decision D10), learniq's Session
 * otherwise.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Kernel\Contracts\TimetableVisibilityServiceInterface;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Decides and serves which group, teacher and room timetables a caller may open.
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
 */
class TimetableVisibilityService implements TimetableVisibilityServiceInterface {

	/**
	 * The policy that applies when a school has not set one.
	 */
	public const DEFAULTS = [
		'learnerSeesGroups' => 'own',
		'learnerSeesTeachers' => 'related',
		'learnerSeesRooms' => 'related',
		'instructorSeesGroups' => 'all',
		'instructorSeesTeachers' => 'all',
		'instructorSeesRooms' => 'all',
	];

	/**
	 * The answer of allowedIds() for scopes that need no lookup: every id, or none.
	 */
	private const FIXED_SCOPE_IDS = ['all' => null, 'none' => []];

	/**
	 * Groups that always see every timetable.
	 */
	private const SEE_ALL_GROUPS = ['team-leads', 'compliance-officers'];

	/**
	 * Constructor.
	 *
	 * @param TimetableDirectory      $directory     Policies, cohorts, rooms, enrolments and other timetables' lessons.
	 * @param IGroupManager           $groupManager  Role checks.
	 * @param IUserManager            $userManager   Teacher display names.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TimetableDirectory $directory,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * The policy of the caller's tenant, with the defaults filled in.
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-school-sets-whose-timetables-each-role-may-see
	 */
	public function policy(): array {
		$rows = $this->directory->policyRows();
		$policy = self::DEFAULTS;
		foreach (array_keys(self::DEFAULTS) as $key) {
			$value = ($rows[0][$key] ?? null);
			if (is_string($value) === true && $value !== '') {
				$policy[$key] = $value;
			}
		}

		return $policy;
	}//end policy()

	/**
	 * The id of the tenant's policy object, or null when the defaults apply.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-school-sets-whose-timetables-each-role-may-see
	 */
	public function policyId(): ?string {
		$rows = $this->directory->policyRows();
		if ($rows === []) {
			return null;
		}

		return $this->directory->idOf(row: $rows[0]);
	}//end policyId()

	/**
	 * The caller's role for the policy: `all` (staff who see everything),
	 * `instructor` or `learner`.
	 *
	 * @param string $uid The caller.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-school-sets-whose-timetables-each-role-may-see
	 */
	public function role(string $uid): string {
		if ($this->groupManager->isAdmin($uid) === true) {
			return 'all';
		}

		foreach (self::SEE_ALL_GROUPS as $group) {
			if ($this->groupManager->isInGroup($uid, $group) === true) {
				return 'all';
			}
		}

		if ($this->groupManager->isInGroup($uid, 'instructors') === true) {
			return 'instructor';
		}

		return 'learner';
	}//end role()

	/**
	 * The policy value for a caller and a kind: `all`, `related`, `own` or `none`.
	 *
	 * @param string $uid  The caller.
	 * @param string $kind `cohort`, `teacher` or `room`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-school-sets-whose-timetables-each-role-may-see
	 */
	public function scope(string $uid, string $kind): string {
		$role = $this->role(uid: $uid);
		if ($role === 'all') {
			return 'all';
		}

		$key = $role . 'Sees' . ['cohort' => 'Groups', 'teacher' => 'Teachers', 'room' => 'Rooms'][$kind];
		return ($this->policy()[$key] ?? 'none');
	}//end scope()

	/**
	 * The ids of a kind the caller may open; null means every one.
	 *
	 * @param string $uid  The caller.
	 * @param string $kind `cohort`, `teacher` or `room`.
	 *
	 * @return array<int,string>|null
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
	 */
	public function allowedIds(string $uid, string $kind): ?array {
		$scope = $this->scope(uid: $uid, kind: $kind);
		if (array_key_exists($scope, self::FIXED_SCOPE_IDS) === true) {
			return self::FIXED_SCOPE_IDS[$scope];
		}

		$own = (new OwnTimetableCohorts(directory: $this->directory))->forUser(uid: $uid);
		// A teacher's "own" is themselves; a learner's "related" are the
		// teachers of their own groups.
		if ($kind === 'teacher' && $scope === 'own') {
			return [$uid];
		}

		return $this->idsOfOwnCohorts(kind: $kind, own: $own);
	}//end allowedIds()

	/**
	 * The ids of a kind reached through the caller's own cohorts.
	 *
	 * @param string                            $kind `cohort`, `teacher` or `room`.
	 * @param array<string,array<string,mixed>> $own  The caller's cohorts, keyed by id.
	 *
	 * @return array<int,string>
	 */
	private function idsOfOwnCohorts(string $kind, array $own): array {
		if ($kind === 'cohort') {
			return array_keys($own);
		}

		if ($kind === 'teacher') {
			return $this->teachersOf(cohorts: $own);
		}

		return $this->directory->roomsOfCohorts(cohortIds: array_keys($own));
	}//end idsOfOwnCohorts()

	/**
	 * The distinct teacher ids of the given cohorts, in first-seen order.
	 *
	 * @param array<string,array<string,mixed>> $cohorts The cohorts.
	 *
	 * @return array<int,string>
	 */
	private function teachersOf(array $cohorts): array {
		$ids = [];
		foreach ($cohorts as $cohort) {
			foreach ((array)($cohort['teacherIds'] ?? []) as $teacher) {
				$ids[(string)$teacher] = true;
			}
		}

		return array_keys($ids);
	}//end teachersOf()

	/**
	 * Whether the caller may open one timetable.
	 *
	 * @param string $uid  The caller.
	 * @param string $kind `cohort`, `teacher` or `room`.
	 * @param string $id   The cohort, teacher or room id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
	 */
	public function mayOpen(string $uid, string $kind, string $id): bool {
		if (in_array($kind, self::KINDS, true) === false || $id === '') {
			return false;
		}

		$ids = $this->allowedIds(uid: $uid, kind: $kind);
		return $ids === null || in_array($id, $ids, true) === true;
	}//end mayOpen()

	/**
	 * What the caller may pick for a kind, as `{id, label}`.
	 *
	 * @param string $uid  The caller.
	 * @param string $kind `cohort`, `teacher` or `room`.
	 *
	 * @return array<int,array{id:string,label:string}> Sorted by label.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
	 */
	public function options(string $uid, string $kind): array {
		$ids = $this->allowedIds(uid: $uid, kind: $kind);
		$all = $this->everything(kind: $kind);
		$out = $this->listedOptions(labels: $all, ids: $ids);
		// A related teacher may teach no cohort row the reader can list
		// (a planninq teacher); keep them with their display name.
		if ($kind === 'teacher') {
			$out = array_merge($out, $this->unlistedTeachers(labels: $all, ids: ($ids ?? [])));
		}

		usort($out, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));
		return $out;
	}//end options()

	/**
	 * The labelled ids the caller may pick, as `{id, label}`, in label-list order.
	 *
	 * @param array<string,string>   $labels Label by id.
	 * @param array<int,string>|null $ids    The allowed ids; null means every one.
	 *
	 * @return array<int,array{id:string,label:string}>
	 */
	private function listedOptions(array $labels, ?array $ids): array {
		$out = [];
		foreach ($labels as $id => $label) {
			if ($ids === null || in_array($id, $ids, true) === true) {
				$out[] = ['id' => $id, 'label' => $label];
			}
		}

		return $out;
	}//end listedOptions()

	/**
	 * Allowed teachers missing from the label list, as `{id, label}` with their display name.
	 *
	 * @param array<string,string> $labels Label by id.
	 * @param array<int,string>    $ids    The allowed teacher ids.
	 *
	 * @return array<int,array{id:string,label:string}>
	 */
	private function unlistedTeachers(array $labels, array $ids): array {
		$out = [];
		foreach ($ids as $id) {
			if (isset($labels[$id]) === false) {
				$out[] = ['id' => $id, 'label' => $this->displayName(uid: $id)];
			}
		}

		return $out;
	}//end unlistedTeachers()

	/**
	 * Every cohort, teacher or room with its label.
	 *
	 * @param string $kind `cohort`, `teacher` or `room`.
	 *
	 * @return array<string,string> Label by id.
	 */
	private function everything(string $kind): array {
		$labels = $this->directory->labels(kind: $kind);
		if ($kind === 'teacher') {
			foreach (array_keys($labels) as $uid) {
				$labels[$uid] = $this->displayName(uid: (string)$uid);
			}
		}

		return $labels;
	}//end everything()

	/**
	 * A user's display name, or their id.
	 *
	 * @param string $uid The user.
	 *
	 * @return string
	 */
	private function displayName(string $uid): string {
		$user = $this->userManager->get($uid);
		if ($user === null) {
			return $uid;
		}

		return $user->getDisplayName();
	}//end displayName()

}//end class
