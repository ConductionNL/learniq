<?php

/**
 * Learniq Conflict Finding
 *
 * One conflict the detector found: the Sessions involved, its kind and the
 * shared identity in conflict.
 *
 * @category Timetabling
 * @package  OCA\Learniq\Timetabling
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
 * @spec openspec/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
 */

declare(strict_types=1);

namespace OCA\Learniq\Timetabling;

/**
 * One conflict, before it becomes a TimetableConflict row.
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
 */
final class ConflictFinding {

	/**
	 * Constructor.
	 *
	 * @param array<int,string> $sessionIds Session UUIDs involved.
	 * @param string            $kind       Conflict kind.
	 * @param string|null       $scopeRef   Shared identity in conflict, or null.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $sessionIds,
		public readonly string $kind,
		public readonly ?string $scopeRef,
	) {
	}//end __construct()
}//end class
