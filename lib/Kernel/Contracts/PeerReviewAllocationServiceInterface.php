<?php

/**
 * Contract of PeerReviewAllocationService
 *
 * The public surface of PeerReviewAllocationService that other components call, so they depend
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
 * Contract of PeerReviewAllocationService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PeerReviewAllocationServiceInterface {

	/**
	 * Allocate PeerReview rows for every Submission of an Assignment.
	 *
	 * @param string $assignmentId UUID of the Assignment to allocate reviewers for.
	 *
	 * @return array{strategy: string, submissionsProcessed: int, createdCount: int}
	 *
	 * @spec openspec/specs/assignments/spec.md#scenario-round-robin-allocates-the-configured-reviewer-count-while-excluding-self
	 * @spec openspec/specs/assignments/spec.md#scenario-manual-strategy-performs-no-automatic-allocation
	 * @spec openspec/specs/assignments/spec.md#scenario-re-running-allocation-is-idempotent
	 */
	public function allocate(string $assignmentId): array;
}//end interface
