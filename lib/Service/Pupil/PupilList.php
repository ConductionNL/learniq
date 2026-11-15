<?php

/**
 * Learniq Pupil List
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rules of the staff list of the pupils of one group: one row per pupil
 * with what the school kinds act on (today's state, absence and lateness this
 * school year, the conference time and its state, care, a signal, the grade
 * average, and for a student in a placement the company, the approved hours
 * and the placement's state), the saved views (absent today, no conference
 * time yet, with care, with a signal) with how many pupils each holds, and the
 * signal-first order of the mentor view. Pure: the rows are built from what
 * PupilListService read.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Pupil
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupil-list-shows-what-the-school-kind-acts-on-and-its-saved-views-count
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Pupil;

use DateTimeImmutable;
use Exception;
use OCA\Learniq\Service\Attendance\TodayStateService;

/**
 * Builds, filters and orders the rows of a group's pupil list.
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupil-list-shows-what-the-school-kind-acts-on-and-its-saved-views-count
 */
final class PupilList {

	public const VIEW_ABSENT_TODAY = 'absent-today';
	public const VIEW_NO_CONFERENCE_TIME = 'no-conference-time';
	public const VIEW_WITH_CARE = 'with-care';
	public const VIEW_WITH_SIGNAL = 'with-signal';

	/**
	 * The saved views, in the order the list offers them.
	 *
	 * @var array<int, string>
	 */
	public const VIEWS = [self::VIEW_ABSENT_TODAY, self::VIEW_NO_CONFERENCE_TIME, self::VIEW_WITH_CARE, self::VIEW_WITH_SIGNAL];

	public const SORT_SIGNAL_FIRST = 'signal-first';

	public const CONFERENCE_NONE = 'none';

	public const CARE_PLAN = 'plan';
	public const CARE_SUPPORT_REQUEST = 'support-request';

	public const SIGNAL_ATTENDANCE_FLAG = 'attendance-flag';
	public const SIGNAL_NEARING_THRESHOLD = 'nearing-threshold';
	public const SIGNAL_INSUFFICIENT_GRADES = 'insufficient-grades';

	/**
	 * A grade below this is insufficient, on the Dutch 1 to 10 scale.
	 *
	 * @var float
	 */
	public const INSUFFICIENT_BELOW = 5.5;

	/**
	 * This many insufficient grades or more is a signal.
	 *
	 * @var int
	 */
	public const INSUFFICIENT_SIGNAL_COUNT = 3;

	/**
	 * Conference slot states that hold a chosen time.
	 *
	 * @var array<int, string>
	 */
	private const TIMED_SLOT_STATES = ['booked', 'confirmed', 'acknowledged'];

	/**
	 * Conference slot states that are still to come; `proposed` waits for a guardian to choose.
	 *
	 * @var array<int, string>
	 */
	private const COMING_SLOT_STATES = ['proposed', 'booked', 'confirmed', 'acknowledged'];

	/**
	 * Learning-plan states that count as a running plan.
	 *
	 * @var array<int, string>
	 */
	private const RUNNING_PLAN_STATES = ['active', 'under-evaluation'];

	/**
	 * Support-request states that are still open.
	 *
	 * @var array<int, string>
	 */
	private const OPEN_SUPPORT_STATES = ['submitted', 'routed-to-swv', 'in-deliberation'];

	/**
	 * Attendance-flag states that are still open.
	 *
	 * @var array<int, string>
	 */
	private const OPEN_FLAG_STATES = ['open', 'in-handling'];

	/**
	 * Grade-entry states that count towards the average.
	 *
	 * @var array<int, string>
	 */
	private const COUNTING_GRADE_STATES = ['published', 'revised'];

	/**
	 * One pupil's row. Its `id` is the profile's, so a row opens the pupil's
	 * page; a pupil without a profile keeps the user id.
	 *
	 * @param string                     $learnerId  The pupil's Nextcloud user id.
	 * @param array<string, mixed>|null  $profile    The pupil's profile, or null when there is none.
	 * @param string                     $todayState The pupil's state today (a TodayState constant).
	 * @param array<string, mixed>|null  $summary    The attendance summary of this school year, or null.
	 * @param array<string, list<array<string, mixed>>> $records The pupil's conference-slot, learning-plan,
	 *                                                           support-request, attendance-flag, grade-entry
	 *                                                           and bpv-placement rows, keyed by schema.
	 * @param DateTimeImmutable          $now        Now, in the school's zone.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T1
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T2
	 */
	public static function row(string $learnerId, ?array $profile, string $todayState, ?array $summary, array $records, DateTimeImmutable $now): array {
		$conference = self::nextConference(slots: ($records['conference-slot'] ?? []), now: $now);
		$grades = self::grades(entries: ($records['grade-entry'] ?? []));
		$placement = PupilPage::runningPlacement(placements: ($records['bpv-placement'] ?? []));

		$conferenceAt = null;
		$conferenceState = self::CONFERENCE_NONE;
		if ($conference !== null) {
			$conferenceAt = (string)$conference['startsAt'];
			$conferenceState = (string)$conference['lifecycle'];
		}

		$bpvHoursApproved = null;
		if ($placement !== null) {
			$bpvHoursApproved = (float)($placement['hoursApprovedTotal'] ?? 0);
		}

		$profileId = self::idOf(row: ($profile ?? []));
		$rowId = $profileId;
		if ($rowId === '') {
			$rowId = $learnerId;
		}

		return [
			'id' => $rowId,
			'learnerId' => $learnerId,
			'profileId' => $profileId,
			'name' => self::nameOf(learnerId: $learnerId, profile: $profile),
			'todayState' => $todayState,
			'absentDays' => self::count(row: $summary, key: 'absentDays'),
			'lateCount' => self::count(row: $summary, key: 'lateCount'),
			'conferenceAt' => $conferenceAt,
			'conferenceState' => $conferenceState,
			'care' => self::care(plans: ($records['learning-plan'] ?? []), requests: ($records['support-request'] ?? [])),
			'signal' => self::signal(flags: ($records['attendance-flag'] ?? []), summary: $summary, insufficient: $grades['insufficient']),
			'average' => $grades['average'],
			'insufficientCount' => $grades['insufficient'],
			'company' => self::text(row: $placement, key: 'trainingCompanyName'),
			'bpvHoursApproved' => $bpvHoursApproved,
			'placementState' => self::text(row: $placement, key: 'lifecycle'),
		];
	}//end row()

	/**
	 * Whether a row belongs to a saved view; an unknown or empty view holds every row.
	 *
	 * @param array<string, mixed> $row  The row.
	 * @param string               $view The view.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#scenario-groep-7-on-monday-morning
	 */
	public static function inView(array $row, string $view): bool {
		return match ($view) {
			self::VIEW_ABSENT_TODAY => in_array($row['todayState'] ?? '', TodayStateService::ABSENT_STATES, true),
			self::VIEW_NO_CONFERENCE_TIME => in_array($row['conferenceState'] ?? self::CONFERENCE_NONE, self::TIMED_SLOT_STATES, true) === false,
			self::VIEW_WITH_CARE => ($row['care'] ?? '') !== '',
			self::VIEW_WITH_SIGNAL => ($row['signal'] ?? '') !== '',
			default => true,
		};
	}//end inView()

	/**
	 * How many rows each saved view holds.
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#scenario-groep-7-on-monday-morning
	 */
	public static function viewCounts(array $rows): array {
		$counts = [];
		foreach (self::VIEWS as $view) {
			$counts[$view] = count(array_filter($rows, static fn (array $row): bool => self::inView(row: $row, view: $view)));
		}

		return $counts;
	}//end viewCounts()

	/**
	 * The rows in the list's order: by name, or with a signal first and then by name.
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 * @param string                     $sort The order: SORT_SIGNAL_FIRST, else by name.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupil-list-shows-what-the-school-kind-acts-on-and-its-saved-views-count
	 */
	public static function ordered(array $rows, string $sort): array {
		$signalFirst = ($sort === self::SORT_SIGNAL_FIRST);
		usort(
			$rows,
			static function (array $left, array $right) use ($signalFirst): int {
				if ($signalFirst === true) {
					$bySignal = (($left['signal'] ?? '') === '') <=> (($right['signal'] ?? '') === '');
					if ($bySignal !== 0) {
						return $bySignal;
					}
				}

				return strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
			}
		);

		return $rows;
	}//end ordered()

	/**
	 * The pupil's first conference slot still to come, or null.
	 *
	 * @param list<array<string, mixed>> $slots The pupil's conference slots.
	 * @param DateTimeImmutable          $now   Now.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function nextConference(array $slots, DateTimeImmutable $now): ?array {
		$next = null;
		$nextAt = null;
		foreach ($slots as $slot) {
			$at = self::moment(value: ($slot['startsAt'] ?? null));
			if ($at === null || $at < $now || in_array($slot['lifecycle'] ?? '', self::COMING_SLOT_STATES, true) === false) {
				continue;
			}

			if ($nextAt === null || $at < $nextAt) {
				$next = $slot;
				$nextAt = $at;
			}
		}

		return $next;
	}//end nextConference()

	/**
	 * The pupil's care: a running plan, else an open support request, else ''.
	 *
	 * @param list<array<string, mixed>> $plans    The learning plans.
	 * @param list<array<string, mixed>> $requests The support requests.
	 *
	 * @return string
	 */
	private static function care(array $plans, array $requests): string {
		foreach ($plans as $plan) {
			if (in_array($plan['lifecycle'] ?? '', self::RUNNING_PLAN_STATES, true) === true) {
				return self::CARE_PLAN;
			}
		}

		foreach ($requests as $request) {
			if (in_array($request['lifecycle'] ?? '', self::OPEN_SUPPORT_STATES, true) === true) {
				return self::CARE_SUPPORT_REQUEST;
			}
		}

		return '';
	}//end care()

	/**
	 * The pupil's signal: an open attendance flag, else a nearing threshold,
	 * else three or more insufficient grades, else ''.
	 *
	 * @param list<array<string, mixed>> $flags        The attendance flags.
	 * @param array<string, mixed>|null  $summary      This school year's attendance summary.
	 * @param int                        $insufficient The number of insufficient grades.
	 *
	 * @return string
	 */
	private static function signal(array $flags, ?array $summary, int $insufficient): string {
		foreach ($flags as $flag) {
			if (in_array($flag['lifecycle'] ?? '', self::OPEN_FLAG_STATES, true) === true) {
				return self::SIGNAL_ATTENDANCE_FLAG;
			}
		}

		if (($summary['thresholdNearing'] ?? false) === true) {
			return self::SIGNAL_NEARING_THRESHOLD;
		}

		if ($insufficient >= self::INSUFFICIENT_SIGNAL_COUNT) {
			return self::SIGNAL_INSUFFICIENT_GRADES;
		}

		return '';
	}//end signal()

	/**
	 * The weighted average of the counting grades (one decimal, null without
	 * any) and how many of them are insufficient.
	 *
	 * @param list<array<string, mixed>> $entries The grade entries.
	 *
	 * @return array{average: float|null, insufficient: int}
	 */
	private static function grades(array $entries): array {
		$sum = 0.0;
		$weights = 0.0;
		$insufficient = 0;
		foreach ($entries as $entry) {
			$value = ($entry['value'] ?? null);
			if (in_array($entry['lifecycle'] ?? '', self::COUNTING_GRADE_STATES, true) === false || (is_int($value) === false && is_float($value) === false)) {
				continue;
			}

			$weight = ($entry['weight'] ?? 1);
			if ((is_int($weight) === false && is_float($weight) === false) || $weight <= 0) {
				$weight = 1;
			}

			$sum += ((float)$value * (float)$weight);
			$weights += (float)$weight;
			if ((float)$value < self::INSUFFICIENT_BELOW) {
				$insufficient++;
			}
		}

		$average = null;
		if ($weights > 0) {
			$average = round($sum / $weights, 1);
		}

		return ['average' => $average, 'insufficient' => $insufficient];
	}//end grades()

	/**
	 * The pupil's name off the profile, else the user id.
	 *
	 * @param string                    $learnerId The pupil's user id.
	 * @param array<string, mixed>|null $profile   The profile, or null.
	 *
	 * @return string
	 */
	private static function nameOf(string $learnerId, ?array $profile): string {
		$name = '';
		if ($profile !== null) {
			$name = PupilPage::nameOf(profile: $profile);
		}

		if ($name === '') {
			return $learnerId;
		}

		return $name;
	}//end nameOf()

	/**
	 * A count off a row, 0 when it is absent or no number.
	 *
	 * @param array<string, mixed>|null $row The row, or null.
	 * @param string                    $key The field.
	 *
	 * @return int
	 */
	private static function count(?array $row, string $key): int {
		$value = ($row[$key] ?? 0);
		if (is_int($value) === false) {
			return 0;
		}

		return $value;
	}//end count()

	/**
	 * A text off a row, '' when it is absent.
	 *
	 * @param array<string, mixed>|null $row The row, or null.
	 * @param string                    $key The field.
	 *
	 * @return string
	 */
	private static function text(?array $row, string $key): string {
		$value = ($row[$key] ?? '');
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end text()

	/**
	 * A row's id: its `id`, else its `uuid`, else ''.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private static function idOf(array $row): string {
		$id = ($row['id'] ?? ($row['uuid'] ?? ''));
		if (is_string($id) === false) {
			return '';
		}

		return $id;
	}//end idOf()

	/**
	 * A moment, or null when the value is none.
	 *
	 * @param mixed $value An ISO 8601 date-time.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function moment(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Exception) {
			return null;
		}
	}//end moment()
}//end class
