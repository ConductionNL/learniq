<?php

/**
 * Learniq conference booking mode
 *
 * How parents book in a ConferenceRound. `direct`: teachers publish free
 * times and a parent picks one. `preference`: parents ask, and the school
 * plans the times when booking closes (ConferenceScheduleGenerator).
 *
 * A round stored before `bookingMode` existed has no value. It keeps the
 * flow it was created for, so a missing or unknown value reads as
 * `preference`. ConferenceRoundBookingModeStamp fills the value on every new
 * round, so the fallback only ever applies to old rows.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Reads a round's booking mode and booking allowance. Stateless; call it on
 * a fresh instance (`new ConferenceBookingMode()`).
 *
 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
 */
final class ConferenceBookingMode {

	public const DIRECT = 'direct';

	public const PREFERENCE = 'preference';

	/**
	 * The booking mode of a round; `preference` when it carries none.
	 *
	 * @param array<string, mixed> $round The round.
	 *
	 * @return string `direct` or `preference`.
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
	 */
	public function modeOf(array $round): string {
		if (($round['bookingMode'] ?? null) === self::DIRECT) {
			return self::DIRECT;
		}

		return self::PREFERENCE;
	}//end modeOf()

	/**
	 * Whether the round uses direct booking.
	 *
	 * @param array<string, mixed> $round The round.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
	 */
	public function isDirect(array $round): bool {
		return $this->modeOf(round: $round) === self::DIRECT;
	}//end isDirect()

	/**
	 * How many times a family may book per child in the round; one when the
	 * round does not say.
	 *
	 * @param array<string, mixed> $round The round.
	 *
	 * @return int At least 1.
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
	 */
	public function maxBookingsPerChild(array $round): int {
		$value = ($round['maxBookingsPerChild'] ?? null);
		if (is_int($value) === true && $value > 1) {
			return $value;
		}

		return 1;
	}//end maxBookingsPerChild()
}//end class
