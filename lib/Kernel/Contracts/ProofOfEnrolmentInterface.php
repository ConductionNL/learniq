<?php

/**
 * Learniq Proof Of Enrolment contract
 *
 * A learner asks the portal for a proof of her active enrolment for the
 * current school year; it is rendered as a PDF and kept with her documents.
 *
 * @category Contract
 * @package  OCA\Learniq\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCP\IUser;

/**
 * Renders and keeps a learner's proof of enrolment.
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
 */
interface ProofOfEnrolmentInterface {

	/**
	 * Render a proof of the learner's active enrolment for the current
	 * school year and keep it with that enrolment's documents.
	 *
	 * @param PortalLearner $learner The learner, as the portal proved her.
	 *
	 * @return PortalOutcome 201 with the document's title, or a refusal with its reason.
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
	 */
	public function request(PortalLearner $learner): PortalOutcome;

	/**
	 * A refusal reason in the learner's words.
	 *
	 * @param string     $reason The reason code.
	 * @param IUser|null $user   The learner, for her language; null for the default.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
	 */
	public function message(string $reason, ?IUser $user): string;
}//end interface
