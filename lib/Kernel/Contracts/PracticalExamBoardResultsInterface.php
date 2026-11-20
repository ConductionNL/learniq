<?php

/**
 * Learniq PracticalExamBoardResultsInterface
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;


/**
 * Contract of PracticalExamBoardResults.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PracticalExamBoardResultsInterface {

	/**
	 * The practical exam results of one sitting that both assessors signed.
	 *
	 * @param string $sittingId The exam sitting.
	 *
	 * @return array<int, array<string, mixed>> One row per co-signed assessment.
	 *
	 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-result-needs-both-assessors
	 */
	public function forSitting(string $sittingId): array;
}//end interface
