<?php

/**
 * Contract of PortalElectiveSignUp
 *
 * The public surface of PortalElectiveSignUp that other components call, so they depend
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
use OCP\IUser;

/**
 * Contract of PortalElectiveSignUp.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalElectiveSignUpInterface {

	/**
	 * Sign the pupil up for the next lesson of an offer.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param string        $offerId The offer.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
	 */
	public function signUp(PortalLearner $learner, string $offerId): PortalOutcome;

	/**
	 * The pupil-facing message for a refusal reason, in her language.
	 *
	 * @param string     $reason The reason.
	 * @param IUser|null $user   The pupil's account, if any.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
	 */
	public function message(string $reason, ?IUser $user): string;
}//end interface
