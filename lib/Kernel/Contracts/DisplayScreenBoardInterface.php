<?php

/**
 * Contract of DisplayScreenBoard
 *
 * The public surface of DisplayScreenBoard that other components call, so they depend
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
 * Contract of DisplayScreenBoard.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DisplayScreenBoardInterface {

	/**
	 * The screen's lessons, from the cache when it is under a minute old.
	 *
	 * @param array<string, mixed> $screen The screen.
	 *
	 * @return array{name: string, updatedAt: string, lessons: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-display-screen-shows-todays-lessons-and-changes-without-a-signed-in-user
	 */
	public function forScreen(array $screen): array;
}//end interface
