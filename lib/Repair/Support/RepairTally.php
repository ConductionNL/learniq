<?php

/**
 * Learniq tally of one back-fill walk.
 *
 * @category Repair
 * @package  OCA\Learniq\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Repair plumbing, not a behavioural requirement: it counts
 *       the rows a back-fill step scanned and what became of each.
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair\Support;

use Closure;

/**
 * Hands each row of a walk to the step's visit and counts the outcome it
 * names (`stamped`, `failed`, ...), next to the number of rows scanned.
 *
 * @spec exclude Repair plumbing, not a behavioural requirement.
 */
final class RepairTally {

	/**
	 * Count per outcome, `scanned` included.
	 *
	 * @var array<string, int>
	 */
	private array $counts = [];

	/**
	 * Constructor.
	 *
	 * @param Closure(array<string, mixed>): ?string $visit Handles one row and
	 *     names its outcome, or null when there is nothing to count.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly Closure $visit,
	) {
	}//end __construct()

	/**
	 * Count the row as scanned, hand it to the visit, count its outcome.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return void
	 *
	 * @spec exclude Repair plumbing, not a behavioural requirement.
	 */
	public function visit(array $row): void {
		$this->add(key: 'scanned');
		$outcome = ($this->visit)($row);
		if ($outcome !== null) {
			$this->add(key: $outcome);
		}
	}//end visit()

	/**
	 * How many rows ended with this outcome (`scanned` for all rows read).
	 *
	 * @param string $key The outcome.
	 *
	 * @return int
	 *
	 * @spec exclude Repair plumbing, not a behavioural requirement.
	 */
	public function countOf(string $key): int {
		return ($this->counts[$key] ?? 0);
	}//end countOf()

	/**
	 * Count one more row for an outcome.
	 *
	 * @param string $key The outcome.
	 *
	 * @return void
	 */
	private function add(string $key): void {
		$this->counts[$key] = ($this->countOf(key: $key) + 1);
	}//end add()
}//end class
