<?php

/**
 * Learniq School Activity Places
 *
 * The places of a term-long school activity: the lower of its capacity and
 * its distinct supervisors times the children per supervisor, or the capacity
 * alone when no ratio is set. Also splits an activity's sign-ups into the
 * confirmed children and the waiting list, oldest sign-up first.
 *
 * @category Service
 * @package  OCA\Learniq\Service\SchoolActivity
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
 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md#requirement-staff-must-set-an-activitys-places-by-capacity-and-supervision
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\SchoolActivity;

/**
 * Places, confirmed children and waiting list of one activity.
 *
 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md#requirement-staff-must-set-an-activitys-places-by-capacity-and-supervision
 */
class ActivityPlaces {

	/**
	 * A sign-up holding a place.
	 */
	public const CONFIRMED = 'confirmed';

	/**
	 * A sign-up waiting for a place.
	 */
	public const WAITLISTED = 'waitlisted';

	/**
	 * The places of an activity.
	 *
	 * @param array<string, mixed> $activity The activityOffer row.
	 *
	 * @return int The places, never below 0.
	 *
	 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md#requirement-staff-must-set-an-activitys-places-by-capacity-and-supervision
	 */
	public function placesFor(array $activity): int {
		$capacity = max(0, (int)($activity['capacity'] ?? 0));
		$ratio    = max(0, (int)($activity['childrenPerSupervisor'] ?? 0));
		if ($ratio === 0) {
			return $capacity;
		}

		$supervisors = [];
		foreach ((array)($activity['supervisorRefs'] ?? []) as $ref) {
			if (is_string($ref) === true && $ref !== '') {
				$supervisors[$ref] = true;
			}
		}

		return min($capacity, (count($supervisors) * $ratio));
	}//end placesFor()

	/**
	 * The sign-ups holding a place.
	 *
	 * @param array<int, array<string, mixed>> $signups One activity's sign-ups.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md#requirement-staff-must-set-an-activitys-places-by-capacity-and-supervision
	 */
	public function confirmed(array $signups): array {
		return array_values(array_filter($signups, static fn (array $signup): bool => ($signup['status'] ?? '') === self::CONFIRMED));
	}//end confirmed()

	/**
	 * The waiting list, oldest sign-up first (id breaks a tie).
	 *
	 * @param array<int, array<string, mixed>> $signups One activity's sign-ups.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md#requirement-staff-must-set-an-activitys-places-by-capacity-and-supervision
	 */
	public function waitlist(array $signups): array {
		$waiting = array_values(array_filter($signups, static fn (array $signup): bool => ($signup['status'] ?? '') === self::WAITLISTED));
		usort(
			$waiting,
			static function (array $left, array $right): int {
				$byTime = strcmp((string)($left['signedUpAt'] ?? ''), (string)($right['signedUpAt'] ?? ''));
				if ($byTime !== 0) {
					return $byTime;
				}

				return strcmp((string)($left['id'] ?? ''), (string)($right['id'] ?? ''));
			}
		);

		return $waiting;
	}//end waitlist()
}//end class
