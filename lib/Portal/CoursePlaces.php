<?php

/**
 * Learniq course places
 *
 * How many places a course date has left, and the words a course row shows
 * about them (board-data-the-schemas-lacked): "Nog 1 plek", "6 plekken vrij",
 * "Vol". Places left are the cohort's capacity minus the places of every live
 * company booking and every live enrolment that came without a booking.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
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
 * @spec openspec/changes/board-data-the-schemas-lacked/specs/portal-contribution/spec.md#requirement-a-course-row-says-how-many-places-are-left
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * Places left on a course date.
 *
 * @spec openspec/changes/board-data-the-schemas-lacked/specs/portal-contribution/spec.md#requirement-a-course-row-says-how-many-places-are-left
 */
class CoursePlaces {

	/**
	 * Booking states that hold their places.
	 *
	 * @var array<int, string>
	 */
	private const LIVE_BOOKINGS = ['received', 'confirmed'];

	/**
	 * Enrolment states that hold a place.
	 *
	 * @var array<int, string>
	 */
	private const LIVE_ENROLMENTS = ['pending', 'active'];

	/**
	 * From how few places left a course row warns.
	 */
	private const FEW_PLACES = 3;

	/**
	 * Constructor.
	 *
	 * @param PublicIndexReads $reads The shared reads and words.
	 */
	public function __construct(
		private readonly PublicIndexReads $reads,
	) {
	}//end __construct()

	/**
	 * The places each course date has given away, by cohort id.
	 *
	 * @param string $namespace The uuid namespace.
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/changes/board-data-the-schemas-lacked/specs/portal-contribution/spec.md#requirement-a-course-row-says-how-many-places-are-left
	 */
	public function taken(string $namespace): array {
		$taken = [];
		foreach ($this->reads->rows(schema: 'course-booking', namespace: $namespace) as $booking) {
			if (in_array(($booking['lifecycle'] ?? null), self::LIVE_BOOKINGS, true) === true) {
				$cohort         = (string)($booking['cohortId'] ?? '');
				$taken[$cohort] = ($taken[$cohort] ?? 0) + max(0, (int)($booking['participantCount'] ?? 0));
			}
		}

		foreach ($this->reads->rows(schema: 'enrolment', namespace: $namespace) as $enrolment) {
			$loose = ((string)($enrolment['bookingRef'] ?? '') === '');
			if ($loose === true && in_array(($enrolment['lifecycle'] ?? null), self::LIVE_ENROLMENTS, true) === true) {
				$cohort         = (string)($enrolment['cohortId'] ?? '');
				$taken[$cohort] = ($taken[$cohort] ?? 0) + 1;
			}
		}

		return $taken;
	}//end taken()

	/**
	 * Places left, never below zero; null without a capacity.
	 *
	 * @param mixed $capacity The cohort's capacity.
	 * @param int   $taken    The places given away.
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/board-data-the-schemas-lacked/specs/portal-contribution/spec.md#requirement-a-course-row-says-how-many-places-are-left
	 */
	public function left(mixed $capacity, int $taken): ?int {
		if (is_int($capacity) === false) {
			return null;
		}

		return max(0, $capacity - $taken);
	}//end left()

	/**
	 * The note under a course row: a warning from three places down, "Full"
	 * at none; nothing without a capacity.
	 *
	 * @param int|null $left Places left.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/board-data-the-schemas-lacked/specs/portal-contribution/spec.md#requirement-a-course-row-says-how-many-places-are-left
	 */
	public function note(?int $left): array {
		if ($left === null) {
			return [];
		}

		if ($left === 0) {
			return ['note' => $this->reads->word(text: 'Full'), 'noteTone' => 'warning'];
		}

		if ($left === 1) {
			return ['note' => $this->reads->word(text: 'One place left'), 'noteTone' => 'warning'];
		}

		if ($left <= self::FEW_PLACES) {
			return ['note' => $this->reads->word(text: '%s places left', args: [$left]), 'noteTone' => 'warning'];
		}

		return ['note' => $this->reads->word(text: '%s places free', args: [$left]), 'noteTone' => 'positive'];
	}//end note()
}//end class
