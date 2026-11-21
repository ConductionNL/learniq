<?php

/**
 * Contract of CheckInCodeService
 *
 * The public surface of CheckInCodeService that other components call, so they depend
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
 * Contract of CheckInCodeService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CheckInCodeServiceInterface {

	/**
	 * The code that is valid now for a window.
	 *
	 * @param string $windowId The CheckInWindow uuid.
	 * @param string $mode     `rotating-qr` or `link`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/attendance/spec.md#scenario-an-old-photo-of-the-code-does-not-work
	 */
	public function current(string $windowId, string $mode): string;

	/**
	 * Seconds until the current rotating code changes.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-the-check-in-code-changes-every-thirty-seconds-in-the-room
	 */
	public function secondsLeft(): int;
}//end interface
