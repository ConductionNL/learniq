<?php

/**
 * Learniq WorkingDays
 *
 * Counts a term in working days, as an exam regulation states it: "the
 * committee decides within ten working days". The day something was submitted
 * does not count; Saturdays and Sundays never count.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Exam
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-25-exam-committee-today-and-keuzedelen/tasks.md#task-T1
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Exam;

use DateTimeImmutable;

/**
 * Adds working days to a date.
 *
 * @spec openspec/changes/archive/2026-11-25-exam-committee-today-and-keuzedelen/tasks.md#task-T1
 */
final class WorkingDays {

	/**
	 * The date a number of working days after a start day.
	 *
	 * @param DateTimeImmutable $start The day the term starts from (not counted itself).
	 * @param int               $days  The term in working days; below 1 counts as 1.
	 *
	 * @return DateTimeImmutable The last day of the term, at midnight.
	 *
	 * @spec openspec/changes/archive/2026-11-25-exam-committee-today-and-keuzedelen/tasks.md#task-T1
	 */
	public static function after(DateTimeImmutable $start, int $days): DateTimeImmutable {
		$day  = $start->setTime(0, 0);
		$left = max(1, $days);
		while ($left > 0) {
			$day = $day->modify('+1 day');
			if ((int) $day->format('N') <= 5) {
				$left--;
			}
		}

		return $day;
	}//end after()
}//end class
