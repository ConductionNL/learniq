<?php

/**
 * Learniq First Today Facts
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What each First today rule counts, from rows already read. Every method
 * takes the rows and the moment and answers the rule's facts: `count` (the
 * rows the rule found, 0 when none) and what the card names (a group, a
 * lesson, a round, a date). It reads nothing itself, so every rule and its
 * empty case is a plain unit test (today-first-per-school-role T7).
 *
 * @category Service
 * @package  OCA\Learniq\Service\Today
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Today;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use OCA\Learniq\Service\Exam\WorkingDays;

/**
 * The facts of each First today rule.
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
 */
final class FirstTodayFacts {

	/**
	 * How far back an unauthorised absence still asks the mentor, in days.
	 *
	 * @var int
	 */
	public const ABSENCE_WINDOW_DAYS = 14;

	/**
	 * How far ahead a plan's end asks the care coordinator, in days.
	 *
	 * @var int
	 */
	public const PLAN_HORIZON_DAYS = 7;

	/**
	 * How many school days before its close a round's booking asks the mentor.
	 *
	 * @var int
	 */
	public const BOOKING_CLOSE_SCHOOL_DAYS = 2;

	/**
	 * The moment of the day after which an unfilled register asks the head.
	 *
	 * @var string
	 */
	public const HEAD_REGISTER_TIME = '09:30';

	/**
	 * Conference slot states in which a guardian has taken the time.
	 *
	 * @var list<string>
	 */
	private const TAKEN_SLOT = ['booked', 'acknowledged', 'confirmed', 'completed'];

	/**
	 * Conference round states in which guardians are still choosing.
	 *
	 * @var list<string>
	 */
	private const CHOOSING_ROUND = ['invitations-sent', 'booking-open'];

	/**
	 * Attendance flag kinds that the school reports to the attendance officer.
	 *
	 * @var list<string>
	 */
	private const REPORTABLE_FLAG = ['langdurig-relatief-verzuim', 'thuiszitter'];

	/**
	 * Attendance flag states that are not reported yet.
	 *
	 * @var list<string>
	 */
	private const UNREPORTED_FLAG = ['open', 'in-handling'];

	/**
	 * Groups whose register of today is not filled after their first lesson started.
	 *
	 * A group is `{cohort, sessions, records, reports}`: today's lessons, the
	 * number of saved marks per lesson id, and the number of absence reports
	 * of its pupils that cover today. The card names the first such group
	 * and how many of its pupils were reported absent by their parents.
	 *
	 * @param list<array<string, mixed>> $groups The caller's groups with today's lessons.
	 * @param DateTimeImmutable          $now    Now, in the school's time zone.
	 *
	 * @return array<string, mixed> count, cohortId, cohortName, reportCount.
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#scenario-meester-daan-before-845
	 */
	public static function registerNotFilled(array $groups, DateTimeImmutable $now): array {
		$open = array_values(array_filter($groups, static fn (array $group): bool => self::registerOpen(group: $group, now: $now)));

		return [
			'count' => count($open),
			'cohortId' => self::idOf(row: (array)($open[0]['cohort'] ?? [])),
			'cohortName' => (string)($open[0]['cohort']['name'] ?? ''),
			'reportCount' => (int)($open[0]['reports'] ?? 0),
		];
	}//end registerNotFilled()

	/**
	 * Groups whose register is not filled at 9.30, against every group with a lesson today.
	 *
	 * Before 9.30 nothing counts: the head's rule waits for the moment the
	 * board names.
	 *
	 * @param list<array<string, mixed>> $groups Every group with today's lessons (see registerNotFilled()).
	 * @param DateTimeImmutable          $now    Now, in the school's time zone.
	 *
	 * @return array<string, mixed> count (groups not filled), total (groups with a lesson today), cohortName (the first not filled).
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#scenario-the-directeur-at-905
	 */
	public static function registersNotFilledAt930(array $groups, DateTimeImmutable $now): array {
		$withLessons = array_values(array_filter($groups, static fn (array $group): bool => (array)($group['sessions'] ?? []) !== []));
		if ($now->format('H:i') < self::HEAD_REGISTER_TIME) {
			return ['count' => 0, 'total' => count($withLessons), 'cohortName' => ''];
		}

		$open = array_values(array_filter($withLessons, static fn (array $group): bool => self::registerOpen(group: $group, now: $now)));

		return [
			'count' => count($open),
			'total' => count($withLessons),
			'cohortName' => (string)($open[0]['cohort']['name'] ?? ''),
		];
	}//end registersNotFilledAt930()

	/**
	 * The caller's lessons of today that ended with no register saved.
	 *
	 * @param list<array<string, mixed>> $sessions Today's lessons of the caller, each with `markCount` (saved marks).
	 * @param DateTimeImmutable          $now      Now.
	 *
	 * @return array<string, mixed> count, sessionId, cohortId, sessionTitle, endsAt (of the earliest).
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 */
	public static function lessonRegisterOpen(array $sessions, DateTimeImmutable $now): array {
		$open = array_values(
			array_filter(
				$sessions,
				static function (array $session) use ($now): bool {
					$ends = self::moment(value: ($session['endsAt'] ?? null));
					return $ends !== null && $ends <= $now && (int)($session['markCount'] ?? 0) === 0
						&& ($session['lifecycle'] ?? '') !== 'cancelled';
				}
			)
		);
		usort($open, static fn (array $one, array $two): int => strcmp((string)($one['endsAt'] ?? ''), (string)($two['endsAt'] ?? '')));

		return [
			'count' => count($open),
			'sessionId' => self::idOf(row: (array)($open[0] ?? [])),
			'cohortId' => (string)($open[0]['cohortId'] ?? ''),
			'sessionTitle' => (string)($open[0]['title'] ?? ''),
			'endsAt' => ($open[0]['endsAt'] ?? null),
		];
	}//end lessonRegisterOpen()

	/**
	 * Conference times guardians booked with the caller that wait for her confirmation.
	 *
	 * @param list<array<string, mixed>> $slots Conference slots.
	 * @param string                     $uid   The caller.
	 *
	 * @return array<string, mixed> count.
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 */
	public static function bookingsToConfirm(array $slots, string $uid): array {
		$waiting = array_filter(
			$slots,
			static fn (array $slot): bool => ($slot['teacherId'] ?? null) === $uid && ($slot['lifecycle'] ?? '') === 'booked'
		);

		return ['count' => count($waiting)];
	}//end bookingsToConfirm()

	/**
	 * Pupils of the caller's groups whose absence is nearing the attendance limit.
	 *
	 * @param list<array<string, mixed>> $summaries Attendance summaries.
	 * @param string                     $uid       The caller.
	 *
	 * @return array<string, mixed> count.
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 */
	public static function nearingLimit(array $summaries, string $uid): array {
		$learners = [];
		foreach ($summaries as $summary) {
			if (($summary['thresholdNearing'] ?? false) !== true) {
				continue;
			}

			if (in_array($uid, (array)($summary['teacherIds'] ?? []), true) === false && ($summary['mentorId'] ?? null) !== $uid) {
				continue;
			}

			$learners[(string)($summary['learnerId'] ?? ($summary['learnerRef'] ?? self::idOf(row: $summary)))] = true;
		}

		return ['count' => count($learners)];
	}//end nearingLimit()

	/**
	 * Assignments of the caller's groups due today with hand-ins missing.
	 *
	 * @param list<array<string, mixed>> $assignments Assignments.
	 * @param array<string, int>         $groupSizes  The number of pupils per group id the caller teaches.
	 * @param string                     $today       Today, `Y-m-d`.
	 * @param DateTimeZone               $zone        The school's time zone.
	 *
	 * @return array<string, mixed> count (assignments), missing (hand-ins missing over them), assignmentId, assignmentTitle (the first).
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 */
	public static function missingHandIns(array $assignments, array $groupSizes, string $today, DateTimeZone $zone): array {
		$due = [];
		$missing = 0;
		foreach ($assignments as $assignment) {
			$cohortId = (string)($assignment['cohortId'] ?? '');
			$dueAt = self::moment(value: ($assignment['dueAt'] ?? null));
			if (isset($groupSizes[$cohortId]) === false || $dueAt === null || ($assignment['lifecycle'] ?? '') !== 'published') {
				continue;
			}

			if ($dueAt->setTimezone($zone)->format('Y-m-d') !== $today) {
				continue;
			}

			$short = $groupSizes[$cohortId] - (int)($assignment['submissionCount'] ?? 0);
			if ($short > 0) {
				$due[] = $assignment;
				$missing += $short;
			}
		}

		return [
			'count' => count($due),
			'missing' => $missing,
			'assignmentId' => self::idOf(row: (array)($due[0] ?? [])),
			'assignmentTitle' => (string)($due[0]['title'] ?? ''),
		];
	}//end missingHandIns()

	/**
	 * The caller's mentor pupils with unauthorised absence in the last two weeks that nobody took up.
	 *
	 * @param list<array<string, mixed>> $flags Attendance flags.
	 * @param string                     $uid   The caller.
	 * @param DateTimeImmutable          $now   Now.
	 *
	 * @return array<string, mixed> count (pupils), learnerId (the first).
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-a-mentor-switches-today-to-her-mentor-class
	 */
	public static function unauthorisedAbsence(array $flags, string $uid, DateTimeImmutable $now): array {
		$since = $now->modify('-' . self::ABSENCE_WINDOW_DAYS . ' days')->format('Y-m-d');
		$learners = [];
		foreach ($flags as $flag) {
			if (($flag['mentorId'] ?? null) !== $uid || ($flag['lifecycle'] ?? '') !== 'open') {
				continue;
			}

			if (substr((string)($flag['windowEnd'] ?? ''), 0, 10) < $since) {
				continue;
			}

			$learners[] = (string)($flag['learnerId'] ?? '');
		}

		$learners = array_values(array_unique($learners));

		return ['count' => count($learners), 'learnerId' => ($learners[0] ?? '')];
	}//end unauthorisedAbsence()

	/**
	 * Pupils who have not chosen a mentor talk in a round of the caller that closes within two school days.
	 *
	 * @param list<array<string, mixed>> $rounds Conference rounds.
	 * @param list<array<string, mixed>> $slots  Conference slots.
	 * @param string                     $uid    The caller.
	 * @param DateTimeImmutable          $now    Now.
	 *
	 * @return array<string, mixed> count (pupils not chosen), roundId, roundName, closesAt.
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-a-mentor-switches-today-to-her-mentor-class
	 */
	public static function mentorTalksClosing(array $rounds, array $slots, string $uid, DateTimeImmutable $now): array {
		$horizon = WorkingDays::after(start: $now->setTime(0, 0), days: self::BOOKING_CLOSE_SCHOOL_DAYS)->setTime(23, 59, 59);
		$count = 0;
		$first = null;
		foreach ($rounds as $round) {
			$closes = self::moment(value: ($round['bookingClosesAt'] ?? null));
			if (in_array(($round['lifecycle'] ?? ''), self::CHOOSING_ROUND, true) === false
				|| in_array($uid, (array)($round['teacherIds'] ?? []), true) === false
				|| $closes === null || $closes < $now || $closes > $horizon
			) {
				continue;
			}

			$notChosen = self::notChosen(round: $round, slots: $slots);
			if ($notChosen === 0) {
				continue;
			}

			$count += $notChosen;
			$first = ($first ?? $round);
		}

		return [
			'count' => $count,
			'roundId' => self::idOf(row: (array)($first ?? [])),
			'roundName' => (string)($first['name'] ?? ''),
			'closesAt' => ($first['bookingClosesAt'] ?? null),
		];
	}//end mentorTalksClosing()

	/**
	 * Plans that end within seven days and are not under evaluation yet.
	 *
	 * A group plan ends on its `periodEndDate`; a pupil's learning plan is
	 * due on its `nextReviewAt`. A plan that is still `active` has no
	 * evaluation under way (`under-evaluation` is the state that starts one).
	 *
	 * @param list<array<string, mixed>> $groupPlans    Group plans.
	 * @param list<array<string, mixed>> $learningPlans Learning plans.
	 * @param string                     $today         Today, `Y-m-d`.
	 *
	 * @return array<string, mixed> count, planId, schema (`group-plan` or `learning-plan`), endsOn (of the earliest).
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 */
	public static function planEnding(array $groupPlans, array $learningPlans, string $today): array {
		$until = (new DateTimeImmutable($today))->modify('+' . self::PLAN_HORIZON_DAYS . ' days')->format('Y-m-d');
		$ending = [];
		foreach (['group-plan' => [$groupPlans, 'periodEndDate'], 'learning-plan' => [$learningPlans, 'nextReviewAt']] as $schema => [$plans, $field]) {
			foreach ($plans as $plan) {
				$ends = substr((string)($plan[$field] ?? ''), 0, 10);
				if (($plan['lifecycle'] ?? '') === 'active' && $ends !== '' && $ends >= $today && $ends <= $until) {
					$ending[] = ['plan' => $plan, 'schema' => $schema, 'endsOn' => $ends];
				}
			}
		}

		usort($ending, static fn (array $one, array $two): int => strcmp($one['endsOn'], $two['endsOn']));

		return [
			'count' => count($ending),
			'planId' => self::idOf(row: (array)($ending[0]['plan'] ?? [])),
			'schema' => (string)($ending[0]['schema'] ?? ''),
			'endsOn' => ($ending[0]['endsOn'] ?? null),
		];
	}//end planEnding()

	/**
	 * Support requests teachers raised that nobody took up yet.
	 *
	 * @param list<array<string, mixed>> $requests Support requests.
	 *
	 * @return array<string, mixed> count.
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 */
	public static function signalNotTakenUp(array $requests): array {
		return ['count' => count(array_filter($requests, static fn (array $request): bool => ($request['lifecycle'] ?? '') === 'submitted'))];
	}//end signalNotTakenUp()

	/**
	 * Leave requests waiting for a decision past half their decision time.
	 *
	 * Half is midway between the moment the request was filed (`created`,
	 * else `@self.created`) and its `decideBy`.
	 *
	 * @param list<array<string, mixed>> $requests Leave requests.
	 * @param DateTimeImmutable          $now      Now.
	 *
	 * @return array<string, mixed> count, decideBy (the earliest).
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 */
	public static function leavePastHalf(array $requests, DateTimeImmutable $now): array {
		$late = [];
		foreach ($requests as $request) {
			$filed = self::moment(value: ($request['created'] ?? ($request['@self']['created'] ?? null)));
			$decideBy = self::moment(value: ($request['decideBy'] ?? null));
			if (($request['lifecycle'] ?? '') !== 'submitted' || $filed === null || $decideBy === null) {
				continue;
			}

			$half = $filed->getTimestamp() + intdiv(max(0, $decideBy->getTimestamp() - $filed->getTimestamp()), 2);
			if ($now->getTimestamp() >= $half) {
				$late[] = (string)$request['decideBy'];
			}
		}

		sort($late);

		return ['count' => count($late), 'decideBy' => ($late[0] ?? null)];
	}//end leavePastHalf()

	/**
	 * Pupils over the attendance limit whose flag is not reported yet.
	 *
	 * @param list<array<string, mixed>> $flags Attendance flags.
	 *
	 * @return array<string, mixed> count (pupils).
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 */
	public static function overLimitNotReported(array $flags): array {
		$learners = [];
		foreach ($flags as $flag) {
			if (in_array(($flag['flagKind'] ?? ''), self::REPORTABLE_FLAG, true) === true
				&& in_array(($flag['lifecycle'] ?? ''), self::UNREPORTED_FLAG, true) === true
			) {
				$learners[(string)($flag['learnerId'] ?? '')] = true;
			}
		}

		return ['count' => count($learners)];
	}//end overLimitNotReported()

	/**
	 * Whether a group's register of today is open after its first lesson started.
	 *
	 * @param array<string, mixed> $group The group (see registerNotFilled()).
	 * @param DateTimeImmutable    $now   Now.
	 *
	 * @return bool
	 */
	private static function registerOpen(array $group, DateTimeImmutable $now): bool {
		$sessions = (array)($group['sessions'] ?? []);
		$started = false;
		foreach ($sessions as $session) {
			$starts = self::moment(value: ($session['startsAt'] ?? null));
			$started = $started || ($starts !== null && $starts <= $now);
			if ((int)(((array)($group['records'] ?? []))[self::idOf(row: (array)$session)] ?? 0) > 0) {
				return false;
			}
		}

		return $started;
	}//end registerOpen()

	/**
	 * The invited pupils of a round with no time taken.
	 *
	 * @param array<string, mixed>       $round The round.
	 * @param list<array<string, mixed>> $slots Conference slots.
	 *
	 * @return int
	 */
	private static function notChosen(array $round, array $slots): int {
		$roundId = self::idOf(row: $round);
		$invited = array_values(array_unique(array_filter(
			array_merge((array)($round['invitedLearnerIds'] ?? []), (array)($round['invitedLearnerRefs'] ?? [])),
			static fn (mixed $id): bool => is_string($id) === true && $id !== ''
		)));
		$chosen = [];
		foreach ($slots as $slot) {
			if (($slot['conferenceRoundId'] ?? null) === $roundId && in_array(($slot['lifecycle'] ?? ''), self::TAKEN_SLOT, true) === true) {
				$chosen[] = (string)($slot['learnerId'] ?? '');
				$chosen[] = (string)($slot['learnerRef'] ?? '');
			}
		}

		return count(array_diff($invited, $chosen));
	}//end notChosen()

	/**
	 * A row's id: `id`, else `uuid`, else `@self.id`.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private static function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['uuid'] ?? ($row['@self']['id'] ?? '')));
	}//end idOf()

	/**
	 * A date-time value as a moment, or null when it is empty or not a date.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function moment(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Exception) {
			return null;
		}
	}//end moment()
}//end class
