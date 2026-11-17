<?php

/**
 * Contract of PlannerTodayService
 *
 * The public surface of PlannerTodayService that other components call, so they depend
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
 * Contract of PlannerTodayService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PlannerTodayServiceInterface {

	/**
	 * What the planner's Today shows: the course days that ask for a go/no-go
	 * decision now, what waits for confirmation, and the occupancy, trainers
	 * and rooms of the coming course days.
	 *
	 * @return array<string, mixed> `today` (Y-m-d), `decisions` (days asking for a go/no-go now),
	 *                              `toConfirm` {bookings, enrolments} and `occupancy` {until, days}.
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#scenario-four-of-six
	 */
	public function today(): array;
}//end interface
