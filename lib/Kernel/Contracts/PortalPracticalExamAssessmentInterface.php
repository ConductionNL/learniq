<?php

/**
 * Learniq PortalPracticalExamAssessmentInterface
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

use OCA\Learniq\Service\Portal\PortalOutcome;

/**
 * Contract of PortalPracticalExamAssessment.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalPracticalExamAssessmentInterface {

	/**
	 * Save the assessor's assessment of one candidate at one sitting, as a draft or submitted.
	 *
	 * @param string               $assessorRef The `externalAssessorId` claim.
	 * @param string               $trust       The session's trust (`low`, `substantial`, `high`).
	 * @param array<string, mixed> $body        What the form sent.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-assessment-records-who-assessed-and-how-sure-the-school-is
	 */
	public function submit(string $assessorRef, string $trust, array $body): PortalOutcome;

	/**
	 * The assessor's own draft for one candidate at one sitting, to resume it.
	 *
	 * @param string $assessorRef The `externalAssessorId` claim.
	 * @param string $sittingId   The sitting.
	 * @param string $learnerRef  The candidate.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-assessment-records-who-assessed-and-how-sure-the-school-is
	 */
	public function draft(string $assessorRef, string $sittingId, string $learnerRef): PortalOutcome;
}//end interface
