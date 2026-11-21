<?php

/**
 * Contract of StandbyCalendar
 *
 * The public surface of StandbyCalendar that other components call, so they depend
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
 * Contract of StandbyCalendar.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface StandbyCalendarInterface {

	/**
	 * The teacher's standby blocks in the window.
	 *
	 * @param string $uid  The teacher.
	 * @param string $from Window start, ISO 8601.
	 * @param string $to   Window end (exclusive), ISO 8601.
	 *
	 * @return array<int,array{slotId:string,date:string,startsAt:string,endsAt:string,vestigingId:string|null}>
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours
	 */
	public function blocksFor(string $uid, string $from, string $to): array;
}//end interface
