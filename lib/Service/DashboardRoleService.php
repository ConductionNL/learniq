<?php

/**
 * Scholiq Dashboard Role Service
 *
 * Resolves the current user's Scholiq role and the set of role-aware dashboard
 * views they may see, from Nextcloud group membership (the security-backed
 * signal) plus the admin-group short-circuit. Provided to the frontend as
 * initial state so the manifest shell can populate `runtime.user.primaryRole`
 * (menu visibleIf) and the role-aware Dashboards component can pick the
 * default view + switcher set.
 *
 * Role membership is checked against the canonical, product-neutral,
 * UNPREFIXED Nextcloud group ids declared by `rbac-declare-groups` (never a
 * `scholiq-`-prefixed convention that no declaration provisions) — see
 * {@see GROUP_BACKED_ROLES} for the full role => group-id map.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCP\IGroupManager;
use OCP\IUser;

/**
 * Resolves Scholiq roles and dashboard views from Nextcloud group membership.
 *
 * @spec openspec/changes/fix-dead-role-gates/specs/dashboard/spec.md#requirement-every-manifest-role-visibility-literal-must-resolve-to-a-value-the-role-resolver-can-emit
 */
class DashboardRoleService {
	/**
	 * Scholiq roles that MUST be backed by a Nextcloud group, mapped to the
	 * unprefixed group id `rbac-declare-groups` provisions, highest priority
	 * first. `instructor` keeps its pre-fix name (only its backing group id
	 * moved, from `scholiq-instructor` to `instructors`); `manager` was
	 * renamed to `administration-manager`; `team-lead`, `coordinator`, and
	 * `guardian` are new. `learner` is deliberately absent — it is the
	 * unconditional fallback, not gated on the `learners` group (see
	 * `resolvePrimaryRole()`).
	 *
	 * @var array<string, string>
	 */
	public const GROUP_BACKED_ROLES = [
		'compliance-officer'     => 'compliance-officers',
		'hr'                     => 'hr',
		'administration-manager' => 'administration-managers',
		'team-lead'              => 'team-leads',
		'coordinator'            => 'coordinators',
		'instructor'             => 'instructors',
		'guardian'               => 'guardians',
	];

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager The Nextcloud group manager.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Resolve the user's primary Scholiq role.
	 *
	 * An admin-group member always resolves to `admin`. Otherwise the
	 * highest-priority group in {@see GROUP_BACKED_ROLES} the user belongs to
	 * wins; with none, the user is a `learner` — the unconditional fallback,
	 * NOT gated on the `learners` group (a user provisioned into no group yet
	 * still resolves to `learner` rather than no role at all).
	 *
	 * @param IUser $user The authenticated Nextcloud user.
	 *
	 * @return string One of: admin, compliance-officer, hr, administration-manager, team-lead, coordinator, instructor, guardian, learner.
	 *
	 * @spec openspec/changes/fix-dead-role-gates/specs/dashboard/spec.md#requirement-every-manifest-role-visibility-literal-must-resolve-to-a-value-the-role-resolver-can-emit
	 */
	public function resolvePrimaryRole(IUser $user): string {
		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			return 'admin';
		}

		foreach (self::GROUP_BACKED_ROLES as $role => $groupId) {
			if ($this->groupManager->isInGroup($user->getUID(), $groupId) === true) {
				return $role;
			}
		}

		return 'learner';
	}//end resolvePrimaryRole()

	/**
	 * Resolve the set of dashboard views the user may switch between.
	 *
	 * Every user can see the `student` view. Operational staff
	 * (administration-manager/team-lead/coordinator/instructor) also get
	 * `teacher`; admins and oversight staff (hr, compliance-officer) also get
	 * `admin`. `guardian` deliberately falls into neither check — a guardian
	 * is not staff and only ever gets the base `student`-tier view, same as
	 * `learner`. The order is admin, teacher, student (most → least privileged).
	 *
	 * @param IUser $user The authenticated Nextcloud user.
	 *
	 * @return string[] Ordered list of accessible views (subset of admin|teacher|student).
	 *
	 * @spec openspec/changes/fix-dead-role-gates/specs/dashboard/spec.md#requirement-every-manifest-role-visibility-literal-must-resolve-to-a-value-the-role-resolver-can-emit
	 */
	public function resolveViews(IUser $user): array {
		$role = $this->resolvePrimaryRole(user: $user);

		// Admins oversee the whole instance — let them preview every view.
		if ($role === 'admin') {
			return ['admin', 'teacher', 'student'];
		}

		$views = [];

		if (in_array($role, ['hr', 'compliance-officer'], true) === true) {
			$views[] = 'admin';
		}

		if (in_array($role, ['administration-manager', 'team-lead', 'coordinator', 'instructor'], true) === true) {
			$views[] = 'teacher';
		}

		// Everyone is at least a learner.
		$views[] = 'student';

		return $views;
	}//end resolveViews()

	/**
	 * Resolve the default dashboard view for the user — the most privileged
	 * view they can access.
	 *
	 * @param IUser $user The authenticated Nextcloud user.
	 *
	 * @return string One of: admin, teacher, student.
	 *
	 * @spec openspec/changes/fix-dashboards-settings-notifications/specs/dashboard/spec.md#requirement-per-resolved-role-default-dashboard
	 */
	public function resolveDefaultView(IUser $user): string {
		$views = $this->resolveViews(user: $user);

		return $views[0];
	}//end resolveDefaultView()
}//end class
