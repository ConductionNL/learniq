<?php

/**
 * Contract of DashboardRoleService
 *
 * The public surface of DashboardRoleService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

use OCP\IUser;

/**
 * Contract of DashboardRoleService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DashboardRoleServiceInterface {

	/**
	 * Learniq roles that MUST be backed by a Nextcloud group, mapped to the
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
		'confidential-counsellor' => 'confidential-counsellors',
		'guardian'               => 'guardians',
	];

	/**
	 * Whether the user holds the confidential counsellor function, i.e. is a
	 * member of the confidential-counsellors group. Admins do not get it for
	 * free: the confidential notes menu is for the function, not for IT.
	 *
	 * @param IUser $user The authenticated Nextcloud user.
	 *
	 * @return bool True when the user is in the confidential-counsellors group.
	 *
	 * @spec openspec/specs/confidential-counsel/spec.md#requirement-the-confidential-notes-menu-is-shown-to-confidential-counsellors-only
	 */
	public function isConfidentialCounsellor(IUser $user): bool;

	/**
	 * Resolve the default dashboard view for the user — the most privileged
	 * view they can access.
	 *
	 * @param IUser $user The authenticated Nextcloud user.
	 *
	 * @return string One of: admin, teacher, student.
	 *
	 * @spec openspec/changes/archive/2026-06-15-fix-dashboards-settings-notifications/specs/dashboard/spec.md#requirement-per-resolved-role-default-dashboard
	 */
	public function resolveDefaultView(IUser $user): string;

	/**
	 * Resolve the user's primary Learniq role.
	 *
	 * An admin-group member always resolves to `admin`. Otherwise the
	 * highest-priority group in {@see GROUP_BACKED_ROLES} the user belongs to
	 * wins; with none, the user is a `learner` — the unconditional fallback,
	 * NOT gated on the `learners` group (a user provisioned into no group yet
	 * still resolves to `learner` rather than no role at all).
	 *
	 * @param IUser $user The authenticated Nextcloud user.
	 *
	 * @return string One of: admin, compliance-officer, hr, administration-manager, team-lead, coordinator,
	 *                instructor, confidential-counsellor, guardian, learner.
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-every-manifest-role-visibility-literal-must-resolve-to-a-value-the-role-resolver-can-emit
	 */
	public function resolvePrimaryRole(IUser $user): string;

	/**
	 * Resolve the set of dashboard views the user may switch between.
	 *
	 * Every user can see the `student` view. Operational staff
	 * (administration-manager/team-lead/coordinator/instructor) also get
	 * `teacher`; admins and oversight staff (hr, compliance-officer) also get
	 * `admin`. `guardian` deliberately falls into neither check — a guardian
	 * is not staff and only ever gets the base `student`-tier view, same as
	 * `learner`. The order is admin, teacher, student (most → least privileged).
	 * Exam board members ({@see EXAM_BOARD_GROUP}) and admins get `exam-board`
	 * last; it is a role flag, not a dashboard view.
	 *
	 * @param IUser $user The authenticated Nextcloud user.
	 *
	 * @return string[] Ordered list of accessible views (subset of admin|teacher|student|exam-board).
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-every-manifest-role-visibility-literal-must-resolve-to-a-value-the-role-resolver-can-emit
	 * @spec openspec/specs/exam-board/spec.md#requirement-fraudcase-read-access-is-restricted-hearing-decision-internals-are-withheld-by-the-server
	 */
	public function resolveViews(IUser $user): array;
}//end interface
