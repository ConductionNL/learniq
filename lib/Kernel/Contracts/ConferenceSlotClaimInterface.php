<?php

/**
 * Contract of ConferenceSlotClaim
 *
 * The public surface of ConferenceSlotClaim that other components call, so they depend
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
 * Contract of ConferenceSlotClaim.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConferenceSlotClaimInterface {

	/**
	 * Claim a slot for a child.
	 *
	 * @param string $slotId The slot the parent picked.
	 * @param array<string, mixed> $round The round (already checked open and direct).
	 * @param array<string, string> $booking learnerRef, learnerId, guardianRef and signupId to stamp.
	 *
	 * @return array{slot?: array<string, mixed>, refuse?: string} The booked slot, or why not.
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
	 */
	public function claim(string $slotId, array $round, array $booking): array;
}//end interface
