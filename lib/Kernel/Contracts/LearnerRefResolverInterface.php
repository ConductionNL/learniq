<?php

/**
 * Contract of LearnerRefResolver
 *
 * The public surface of LearnerRefResolver that other components call, so they depend
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

/**
 * Contract of LearnerRefResolver.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LearnerRefResolverInterface {

	/**
	 * The active LearnerProfile a `learnerRef` names, or null when it does not
	 * exist, is merged away, is deleted or names no Nextcloud user.
	 *
	 * Reads without RBAC and across tenants: the portal caller has no session.
	 * The returned row always carries its own uuid at `id`. Errors other than
	 * "does not exist" propagate, so a caller can tell "no such learner" from
	 * "could not look".
	 *
	 * @param string $learnerRef LearnerProfile uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-the-server-stamps-who-a-submission-belongs-to
	 * @spec openspec/specs/grading/spec.md#requirement-one-resolver-finds-a-learners-profile
	 */
	public function byRef(string $learnerRef): ?array;

	/**
	 * The LearnerProfile UUID for a Nextcloud user id, or null when the user
	 * has no profile. Keeps OpenRegister's tenant scoping: for a signed-in
	 * caller.
	 *
	 * Reads without RBAC: the caller may be a team lead who may write a grade
	 * but not read LearnerProfile, and only the UUID leaves this method.
	 * Errors from OpenRegister propagate, so a caller can tell "no profile"
	 * (null) from "could not look" (exception).
	 *
	 * @param string $learnerId Nextcloud user id.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/grading/spec.md#requirement-every-gradeentry-carries-a-server-stamped-learnerref
	 */
	public function resolve(string $learnerId): ?string;

	/**
	 * The same answer as resolve(), read across tenants: for a caller without
	 * a Nextcloud session, such as a portal request or a repair step.
	 *
	 * @param string $learnerId Nextcloud user id.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/grading/spec.md#requirement-one-resolver-finds-a-learners-profile
	 */
	public function resolveAcrossTenants(string $learnerId): ?string;

	/**
	 * The same answer as resolve(), within one named tenant: for a caller that
	 * knows the tenant from the record it acts on (an enrolment, a credential)
	 * rather than from the session, such as an event listener. Reads across
	 * the session's tenant scoping, then narrows on `tenant_id`, so a user with
	 * profiles in two tenants gets the one the record belongs to.
	 *
	 * @param string $learnerId Nextcloud user id.
	 * @param string $tenantId  The tenant the profile must belong to.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/grading/spec.md#requirement-one-resolver-finds-a-learners-profile
	 */
	public function resolveInTenant(string $learnerId, string $tenantId): ?string;

	/**
	 * The Nextcloud user id of the active profile a `learnerRef` names, or
	 * null when byRef() finds none. The other way round from resolve(): for a
	 * record that names its learner by profile uuid and must be compared with
	 * the signed-in user.
	 *
	 * @param string $learnerRef LearnerProfile uuid.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/grading/spec.md#requirement-one-resolver-finds-a-learners-profile
	 */
	public function userIdOf(string $learnerRef): ?string;
}//end interface
