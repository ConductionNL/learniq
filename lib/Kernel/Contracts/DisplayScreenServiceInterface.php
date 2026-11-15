<?php

/**
 * Contract of DisplayScreenService
 *
 * The public surface of DisplayScreenService that other components call, so they depend
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

/**
 * Contract of DisplayScreenService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DisplayScreenServiceInterface {

	/**
	 * Create or renew the screen's address. The old address stops working.
	 *
	 * @param string $screenId The screen's uuid.
	 *
	 * @return string|null The new token, shown once, or null when the caller cannot see the screen.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
	 */
	public function issueToken(string $screenId): ?string;

	/**
	 * Revoke the screen's address at once.
	 *
	 * @param string $screenId The screen's uuid.
	 *
	 * @return bool False when the caller cannot see the screen.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
	 */
	public function revoke(string $screenId): bool;

	/**
	 * The active screen a token opens, or null for an unknown, wrong or
	 * revoked token. Reads as the system: the caller is anonymous.
	 *
	 * @param string $token The address token.
	 *
	 * @return array<string, mixed>|null The screen.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-display-screen-shows-todays-lessons-and-changes-without-a-signed-in-user
	 */
	public function screenForToken(string $token): ?array;
}//end interface
