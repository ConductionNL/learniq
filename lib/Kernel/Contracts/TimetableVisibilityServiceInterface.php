<?php

/**
 * Contract of TimetableVisibilityService
 *
 * The public surface of TimetableVisibilityService that other components call, so they depend
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
 * Contract of TimetableVisibilityService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TimetableVisibilityServiceInterface {

	public const KINDS = ['cohort', 'teacher', 'room'];

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
	public function mayOpen(string $uid, string $kind, string $id): bool;

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
	public function options(string $uid, string $kind): array;

	/**
	 * The policy of the caller's tenant, with the defaults filled in.
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-school-sets-whose-timetables-each-role-may-see
	 */
	public function policy(): array;

	/**
	 * The id of the tenant's policy object, or null when the defaults apply.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-school-sets-whose-timetables-each-role-may-see
	 */
	public function policyId(): ?string;

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
	public function role(string $uid): string;

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
	public function scope(string $uid, string $kind): string;
}//end interface
