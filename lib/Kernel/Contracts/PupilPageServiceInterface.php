<?php

/**
 * Contract of PupilPageService
 *
 * The public surface of PupilPageService that other components call, so they depend
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

use OCA\Learniq\Service\Attendance\RollCallException;
use OCP\IUser;

/**
 * Contract of PupilPageService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PupilPageServiceInterface {

	/**
	 * The opening data of a pupil's page.
	 *
	 * @param IUser       $user      The caller.
	 * @param string      $learnerId The pupil's Nextcloud user id.
	 * @param string|null $cohortId  The group the page is opened from; null for the pupil's first group of the caller.
	 *
	 * @return array<string, mixed> learnerId, cohortId, todayState, header, nextStep (or null) and people.
	 *
	 * @throws RollCallException 403 for a caller who may not open a group of this pupil, 404 for a pupil without a profile.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#scenario-veras-page
	 */
	public function open(IUser $user, string $learnerId, ?string $cohortId=null): array;
}//end interface
