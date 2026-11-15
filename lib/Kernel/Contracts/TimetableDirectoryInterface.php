<?php

/**
 * Contract of TimetableDirectory
 *
 * The public surface of TimetableDirectory that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of TimetableDirectory.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TimetableDirectoryInterface {

	/**
	 * The lessons of one cohort, teacher or room in a window.
	 *
	 * @param string $kind `cohort`, `teacher` or `room`.
	 * @param string $id   The cohort, teacher or room id.
	 * @param string $from Window start, ISO 8601.
	 * @param string $to   Window end, ISO 8601.
	 *
	 * @return array{sessions:array<int,array<string,mixed>>,source:string} Raw lessons in learniq's session shape.
	 *
	 * @throws RuntimeException When the timetable source does not answer.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
	 */
	public function lessonsOf(string $kind, string $id, string $from, string $to): array;
}//end interface
