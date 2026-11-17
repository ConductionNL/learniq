<?php

/**
 * Learniq PortalExamSittingDocumentsInterface
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
 * Contract of PortalExamSittingDocuments.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalExamSittingDocumentsInterface {

	/**
	 * One sitting and its documents, for an assessor assigned to it.
	 *
	 * @param string $assessorRef The `externalAssessorId` claim.
	 * @param string $sittingId   The sitting asked for.
	 *
	 * @return PortalOutcome The outcome.
	 *
	 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-an-assessor-is-linked-to-an-exam-sitting-by-a-record
	 */
	public function sitting(string $assessorRef, string $sittingId): PortalOutcome;
}//end interface
