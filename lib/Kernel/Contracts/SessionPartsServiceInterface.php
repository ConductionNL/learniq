<?php

/**
 * Contract of SessionPartsService
 *
 * The public surface of SessionPartsService that other components call, so they depend
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
 * Contract of SessionPartsService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SessionPartsServiceInterface {

	/**
	 * The session's parts with their attendance, for a trainer of the day or a planner.
	 *
	 * @param string $sessionId The session uuid.
	 * @param IUser  $user      The signed-in user.
	 *
	 * @return array{sessionId: string, cohortId: string, parts: list<array<string, mixed>>, due: string|null}
	 *
	 * @throws \RuntimeException When the session or its course day is gone (404) or the user may not read it (403).
	 * @throws \Throwable        When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/attendance/spec.md#requirement-a-trainer-registers-attendance-per-part-of-the-day
	 */
	public function forSession(string $sessionId, IUser $user): array;
}//end interface
