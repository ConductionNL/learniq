<?php

/**
 * Contract of PortalSubmissionHandIn
 *
 * The public surface of PortalSubmissionHandIn that other components call, so they depend
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

use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalOutcome;

/**
 * Contract of PortalSubmissionHandIn.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalSubmissionHandInInterface {

	/**
	 * Hand in the pupil's draft.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param string $submissionId The submission's uuid, from the forward.
	 *
	 * @return PortalOutcome 200 `{submissionId, lifecycle}`, or 404 / 409 / 422 with a reason.
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-a-pupil-hands-in-a-portal-draft-through-learniqs-own-endpoint
	 */
	public function handIn(PortalLearner $learner, string $submissionId): PortalOutcome;
}//end interface
