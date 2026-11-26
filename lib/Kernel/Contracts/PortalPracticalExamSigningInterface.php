<?php

/**
 * Learniq PortalPracticalExamSigningInterface
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
 * Contract of PortalPracticalExamSigning.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalPracticalExamSigningInterface {

	/**
	 * Sign a submitted practical exam assessment as the calling assessor.
	 *
	 * @param string $assessorRef  The `externalAssessorId` claim.
	 * @param string $trust        The session's trust (`low`, `substantial`, `high`).
	 * @param string $assessmentId The assessment to sign.
	 *
	 * @return PortalOutcome The outcome.
	 *
	 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-result-needs-both-assessors
	 */
	public function sign(string $assessorRef, string $trust, string $assessmentId): PortalOutcome;

	/**
	 * Sign the one submitted assessment of a candidate at a sitting, as the calling assessor.
	 *
	 * @param string $assessorRef The `externalAssessorId` claim.
	 * @param string $trust       The session's trust (`low`, `substantial`, `high`).
	 * @param string $sittingId   The exam sitting.
	 * @param string $learnerRef  The candidate.
	 *
	 * @return PortalOutcome The outcome.
	 *
	 * @spec openspec/changes/practical-exam-assessment/tasks.md#t9
	 */
	public function signForCandidate(string $assessorRef, string $trust, string $sittingId, string $learnerRef): PortalOutcome;
}//end interface
