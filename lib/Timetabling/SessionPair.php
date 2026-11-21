<?php

/**
 * Learniq Session Pair
 *
 * Two overlapping Sessions the conflict detector evaluates together.
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
 * Two Sessions whose times overlap.
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-conflict-detection-flags-double-bookings-and-capacity-overruns-without-resolving-them
 */
final class SessionPair {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $sessionA Session A.
	 * @param array<string,mixed> $sessionB Session B.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $sessionA,
		public readonly array $sessionB,
	) {
	}//end __construct()
}//end class
