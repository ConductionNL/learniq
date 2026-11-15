<?php

/**
 * Contract of ExampleSetDates
 *
 * The public surface of ExampleSetDates that other components call, so they depend
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
 * Contract of ExampleSetDates.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExampleSetDatesInterface {

	/**
	 * The offset the set's dates carry now: the remembered one; 0 for a set
	 * loaded before offsets were kept (its dates are the boards' own); null
	 * for a set never loaded.
	 *
	 * @param string $setId The set.
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/demo-dates-follow-the-load-week/specs/example-sets/spec.md#requirement-a-reload-in-another-week-moves-what-exists-and-a-reload-in-the-same-week-writes-nothing
	 */
	public function appliedOffset(string $setId): ?int;

	/**
	 * The offset for a load that runs today.
	 *
	 * @return int Days, a multiple of 7.
	 *
	 * @spec openspec/changes/demo-dates-follow-the-load-week/specs/example-sets/spec.md#requirement-a-load-moves-every-date-to-the-week-it-runs-in
	 */
	public function currentOffset(): int;
}//end interface
