<?php

/**
 * Contract of ExcuseRequestStaffOwner
 *
 * The public surface of ExcuseRequestStaffOwner that other components call, so they depend
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
 * Contract of ExcuseRequestStaffOwner.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExcuseRequestStaffOwnerInterface {

	/**
	 * The owner fields a signed-in create stores: the caller as `submittedBy`,
	 * and the pupil from the profile when no `learnerId` was sent. Empty for
	 * an update or without a signed-in caller.
	 *
	 * @param bool                 $isCreate Whether the write creates the report.
	 * @param array<string, mixed> $payload  The ExcuseRequest being written.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-the-server-stamps-who-an-excuse-request-is-about-and-who-filed-it
	 */
	public function ownerFields(bool $isCreate, array $payload): array;

	/**
	 * The school a report without one stores: that of the pupil's profile.
	 * Empty when the report names a school or the profile has none.
	 *
	 * @param array<string, mixed> $payload    The ExcuseRequest being written.
	 * @param string|null          $learnerRef The pupil's LearnerProfile uuid.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-the-server-stamps-who-an-excuse-request-is-about-and-who-filed-it
	 */
	public function schoolFields(array $payload, ?string $learnerRef): array;
}//end interface
