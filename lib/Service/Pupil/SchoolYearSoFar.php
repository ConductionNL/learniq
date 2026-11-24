<?php

/**
 * Learniq SchoolYearSoFar
 *
 * The school year as the pupil list reads it: the day it starts, and how many
 * school days it has held up to today (weekdays outside the school's
 * holidays), the base of "present this year".
 *
 * @category Service
 * @package  OCA\Learniq\Service\Pupil
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T2
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Pupil;

use OCA\Learniq\Service\Calendar\SchoolYear as SchoolCalendarYear;
use OCA\Learniq\Service\Leave\LeaveCalendar;

/**
 * The school year's start and the school days it has held so far.
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T2
 */
class SchoolYearSoFar {

	/**
	 * Constructor.
	 *
	 * @param LeaveCalendar      $calendar   Counts school days.
	 * @param SchoolCalendarYear $schoolYear The school's school year, which says the day it starts.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T2
	 */
	public function __construct(
		private readonly LeaveCalendar $calendar,
		private readonly SchoolCalendarYear $schoolYear,
	) {
	}//end __construct()

	/**
	 * The first day of the school year, `MM-DD`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T2
	 */
	public function start(): string {
		return $this->schoolYear->start();
	}//end start()

	/**
	 * The school days from the school year's first day up to and including today.
	 *
	 * @param string $today Today, `Y-m-d`.
	 *
	 * @return int|null The count, or null when today is not a date.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T2
	 */
	public function schoolDaysUpTo(string $today): ?int {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1) {
			return null;
		}

		return $this->calendar->schoolDays(from: $this->schoolYear->around(date: $today)[0], to: $today);
	}//end schoolDaysUpTo()
}//end class
