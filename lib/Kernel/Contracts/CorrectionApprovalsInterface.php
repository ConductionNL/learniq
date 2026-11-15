<?php

/**
 * Contract of CorrectionApprovals
 *
 * The public surface of CorrectionApprovals that other components call, so they depend
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
 * Contract of CorrectionApprovals.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CorrectionApprovalsInterface {

	/**
	 * The approved request that covers this publish of the grade entry.
	 *
	 * A request covers it when it names the entry, is approved, was decided
	 * by someone other than the person who asked, the person publishing is
	 * not the one who approved, and the entry carries the value that was
	 * approved. The lookup runs without the caller's read rights: the guard
	 * judges facts the publishing teacher may not be allowed to list.
	 *
	 * @param array<string, mixed> $entry     The grade entry at its target state.
	 * @param string               $publisher The uid of the person publishing.
	 *
	 * @return array<string, mixed>|null The request, or null when none covers the publish.
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-a-second-person-approves-a-correction
	 */
	public function approvedFor(array $entry, string $publisher): ?array;

	/**
	 * Whether the entry's value is the approved value. Numbers compare as
	 * numbers (7.5 equals "7.5"); an empty proposal matches an empty value.
	 *
	 * @param mixed $approved The request's proposedValue.
	 * @param mixed $current  The grade entry's value.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-a-second-person-approves-a-correction
	 */
	public function sameValue(mixed $approved, mixed $current): bool;
}//end interface
