<?php

/**
 * Contract of ElectiveSlotService
 *
 * The public surface of ElectiveSlotService that other components call, so they depend
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

use DateTimeZone;
use RuntimeException;

/**
 * Contract of ElectiveSlotService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ElectiveSlotServiceInterface {

	/**
	 * Most courses asked for at once.
	 *
	 * @var int
	 */
	public const MAX_COURSES = 20;

	/**
	 * The slots of each readable course, and of the caller's core lessons when asked.
	 *
	 * A course the caller cannot read is left out, and none of its lessons is read.
	 *
	 * @param string                                                                          $uid   The caller.
	 * @param array{courseIds: array<int,string>, withCore: bool, zone: DateTimeZone, now: int} $query The electives, whether to add the
	 *     caller's own other lessons, the reader's time zone and now (unix seconds).
	 *
	 * @return array{courses: array<string,array<int,array<string,mixed>>>, core: array<int,array<string,mixed>>}
	 *
	 * @throws RuntimeException When the timetable source does not answer.
	 *
	 * @spec openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#requirement-clash-warning-when-choosing
	 */
	public function slots(string $uid, array $query): array;
}//end interface
