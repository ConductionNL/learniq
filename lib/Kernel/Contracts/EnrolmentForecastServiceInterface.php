<?php

/**
 * Contract of EnrolmentForecastService
 *
 * The public surface of EnrolmentForecastService that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of EnrolmentForecastService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EnrolmentForecastServiceInterface {

	/**
	 * Compute a scenario's forecast.
	 *
	 * @param array<string, mixed> $forecast The scenario.
	 *
	 * @return array<string, mixed> The result, with `computedAt`.
	 *
	 * @throws InvalidArgumentException When the target year is malformed or rates do not add up to one.
	 *
	 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
	 */
	public function compute(array $forecast): array;
}//end interface
