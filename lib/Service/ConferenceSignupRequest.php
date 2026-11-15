<?php

/**
 * Learniq conference signup request.
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
 * @spec openspec/specs/parent-conferences/spec.md#scenario-conflict-free-generation-from-sign-ups-and-availability
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * One signup to place in a generation pass, with its id and the intervals it
 * already occupies.
 *
 * @spec openspec/specs/parent-conferences/spec.md#scenario-conflict-free-generation-from-sign-ups-and-availability
 */
final class ConferenceSignupRequest {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>            $signup            The signup row.
	 * @param string                         $signupId          The signup id.
	 * @param array<int,array<string,mixed>> $assignedIntervals Intervals this signup already occupies.
	 */
	public function __construct(
		public readonly array $signup,
		public readonly string $signupId,
		public readonly array $assignedIntervals,
	) {
	}//end __construct()
}//end class
