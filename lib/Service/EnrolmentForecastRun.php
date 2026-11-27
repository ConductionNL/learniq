<?php

/**
 * Learniq Enrolment Forecast Run
 *
 * One forecast as EnrolmentForecastService::compute() works it out: the
 * scenario, the school years, the programmes and rates it was given, this
 * year's groups and next year's buckets and choices. Built and read only
 * inside EnrolmentForecastService, for the length of one compute.
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
 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * One forecast being computed.
 *
 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
 */
final class EnrolmentForecastRun {

	/**
	 * The scenario.
	 *
	 * @var array<string, mixed>
	 */
	public array $forecast;

	/**
	 * The target school year, such as 2027-2028.
	 *
	 * @var string
	 */
	public string $targetYear;

	/**
	 * The school year before it.
	 *
	 * @var string
	 */
	public string $currentYear;

	/**
	 * Programmes by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $programmes;

	/**
	 * Rates keyed `programmeId|programmeYear`.
	 *
	 * @var array<string, array{upRate: float, repeatRate: float, leaveRate: float}>
	 */
	public array $rates;

	/**
	 * Target group size.
	 *
	 * @var int
	 */
	public int $size;

	/**
	 * This year's groups.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $current;

	/**
	 * Next year's learners per programme year, keyed `programmeId|programmeYear`.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $years;

	/**
	 * The approved choices counted per next year's programme year.
	 *
	 * @var array<string, array{learners: int, courses?: array<string, int>}>
	 */
	public array $chosen;

	/**
	 * Course names by id.
	 *
	 * @var array<string, string>
	 */
	public array $courseNames;
}//end class
