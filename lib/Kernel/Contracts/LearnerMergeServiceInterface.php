<?php

/**
 * Contract of LearnerMergeService
 *
 * The public surface of LearnerMergeService that other components call, so they depend
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
 * Contract of LearnerMergeService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LearnerMergeServiceInterface {

	/**
	 * Move every learner-owned record from the merged profile to its survivor.
	 *
	 * The profiles themselves are never rewritten, so `mergedInto` stays set on
	 * the merged profile as the audit link. Each save goes through OpenRegister,
	 * which writes the audit trail entry for the moved record.
	 *
	 * @param array<string,mixed> $merged The merged profile (carries mergedInto).
	 *
	 * @return int How many records were moved.
	 *
	 * @spec openspec/parity/capabilities.json#gov-merge-duplicate-accounts
	 */
	public function moveRecords(array $merged): int;

	/**
	 * Why a merge of $profile into its `mergedInto` target must be refused.
	 *
	 * @param array<string,mixed> $profile The profile being merged.
	 *
	 * @return string|null A reason, or null when the merge may proceed.
	 *
	 * @spec openspec/parity/capabilities.json#gov-merge-duplicate-accounts
	 */
	public function refusalReason(array $profile): ?string;
}//end interface
