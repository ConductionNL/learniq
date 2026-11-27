<?php

/**
 * Contract of SubmissionMarkAllocationService
 *
 * The public surface of SubmissionMarkAllocationService that other components call, so they depend
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
 * Contract of SubmissionMarkAllocationService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SubmissionMarkAllocationServiceInterface {

	/**
	 * Allocate the given markers to every handed-in submission of the
	 * assignment, or to one submission when `$submissionId` is set.
	 *
	 * @param array<string, mixed> $assignment   The assignment, already read by the caller.
	 * @param array<int, string>   $markerIds    Nextcloud user ids of the markers.
	 * @param string               $submissionId One submission's uuid, or '' for all.
	 *
	 * @return array{error: string|null, submissionsProcessed: int, createdCount: int, refused: list<array<string, string>>}
	 *
	 * @spec openspec/specs/assignments/spec.md#scenario-a-coordinator-allocates-two-markers-to-every-hand-in
	 * @spec openspec/specs/assignments/spec.md#scenario-a-learner-cannot-mark-their-own-group-work
	 */
	public function allocate(array $assignment, array $markerIds, string $submissionId = ''): array;
}//end interface
