<?php

/**
 * Contract of ReportPeriodLocks
 *
 * The public surface of ReportPeriodLocks that other components call, so they depend
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
 * Contract of ReportPeriodLocks.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReportPeriodLocksInterface {

	/**
	 * Whether a report period is locked: its stored `isLocked` is true, or its
	 * `lockDate` is set and has passed.
	 *
	 * @param array<string,mixed> $period The report period as stored or returned.
	 * @param int|null $now The moment to compare with (unix time), now when null.
	 *
	 * @return bool True when locked.
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
	 */
	public function isLocked(array $period, ?int $now = null): bool;

	/**
	 * The locked report period over a grade entry, or null when the entry is
	 * not in a locked period.
	 *
	 * @param array<string,mixed> $entry The grade entry.
	 *
	 * @return array<string,mixed>|null The locked period.
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
	 */
	public function lockedPeriodFor(array $entry): ?array;
}//end interface
