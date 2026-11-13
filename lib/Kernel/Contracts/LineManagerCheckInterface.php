<?php

/**
 * Contract of LineManagerCheck
 *
 * The public surface of LineManagerCheck that other components call, so they depend
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

use OCP\IUser;

/**
 * Contract of LineManagerCheck.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LineManagerCheckInterface {



	/**
	 * Whether at least one learner profile names the user as manager.
	 *
	 * Read without RBAC on purpose: the answer is one boolean about the
	 * caller themselves, and a manager need not be able to read the profile
	 * to be named on it. No profile data leaves this method. Any failure
	 * answers false, so the menu entry stays hidden rather than the page
	 * breaking.
	 *
	 * @param IUser $user The signed-in user.
	 *
	 * @return bool True when the user manages at least one learner.
	 *
	 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-request-waits-for-a-teacher-or-manager
	 */
	public function managesLearners(IUser $user): bool;
}//end interface
