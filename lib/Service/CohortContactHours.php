<?php

/**
 * Learniq Cohort Contact Hours
 *
 * One group's contact hours for a window as ContactHoursService works them
 * out: the group, the course names, the margin, and the hours owed, given
 * and attended. Built and read only inside ContactHoursService, for one
 * group of one report.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * One group's owed, given and attended hours.
 *
 * @spec openspec/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
 */
final class CohortContactHours {

	/**
	 * The group.
	 *
	 * @var array<string, mixed>
	 */
	public array $cohort;

	/**
	 * The group's id.
	 *
	 * @var string
	 */
	public string $cohortId;

	/**
	 * Course names by id.
	 *
	 * @var array<string, string>
	 */
	public array $courseNames;

	/**
	 * The margin in percent.
	 *
	 * @var int
	 */
	public int $margin;

	/**
	 * Owed hours by course, or null without an active plan.
	 *
	 * @var array<string, float>|null
	 */
	public ?array $owed;

	/**
	 * Given hours by course.
	 *
	 * @var array<string, float>
	 */
	public array $given;

	/**
	 * Attended hours by learner and course.
	 *
	 * @var array<string, array<string, float>>
	 */
	public array $attended;
}//end class
