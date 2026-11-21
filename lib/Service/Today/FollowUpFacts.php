<?php

/**
 * Learniq Follow Up Facts
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the First today rules about follow-up count: conference times to
 * confirm, a mentor round that closes with pupils who did not choose,
 * hand-ins missing for a deadline today, plans that end without an
 * evaluation, support requests nobody took up, and leave requests past half
 * their decision time. Every method takes rows already read, so each rule
 * and its empty case is a unit test.
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

/**
 * The facts of the First today rules about follows.
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
 */
final class FollowUpFacts {
	use RowValues;

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
	 * Conference times guardians booked with the caller that wait for her confirmation.
	 *
	 * @param list<array<string, mixed>> $slots Conference slots.
	 * @param string                     $uid   The caller.
	 *
	 * @return array<string, mixed> count.
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 */
	public function bookingsToConfirm(array $slots, string $uid): array {
		$waiting = array_filter(
			$slots,
			static fn (array $slot): bool => ($slot['teacherId'] ?? null) === $uid && ($slot['lifecycle'] ?? '') === 'booked'
		);

		return ['count' => count($waiting)];
	}//end bookingsToConfirm()

	/**
	 * Assignments of the caller's groups due today with hand-ins missing.
	 *
	 * @param list<array<string, mixed>> $assignments Assignments.
	 * @param array<string, int>         $groupSizes  The number of pupils per group id the caller teaches.
	 * @param TodayContext               $context     The caller and now.
	 *
	 * @return array<string, mixed> count (assignments), missing (hand-ins missing over them), assignmentId, assignmentTitle (the first).
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 */
	public function missingHandIns(array $assignments, array $groupSizes, TodayContext $context): array {
		$today = $context->today();
		$zone = $context->zone();
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
	 * Pupils who have not chosen a mentor talk in a round of the caller that closes within two school days.
	 *
	 * @param array<string, list<array<string, mixed>>> $conference `rounds` (conference rounds) and `slots` (conference slots).
	 * @param TodayContext                              $context    The caller and now.
	 *
	 * @return array<string, mixed> count (pupils not chosen), roundId, roundName, closesAt.
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-a-mentor-switches-today-to-her-mentor-class
	 */
	public function mentorTalksClosing(array $conference, TodayContext $context): array {
		$slots = ($conference['slots'] ?? []);
		$now = $context->now;
		$uid = $context->uid();
		$horizon = $now->setTime(23, 59, 59);
		for ($left = self::BOOKING_CLOSE_SCHOOL_DAYS; $left > 0;) {
			$horizon = $horizon->modify('+1 day');
			$left -= (int)($horizon->format('N') <= 5);
		}

		$count = 0;
		$first = null;
		foreach (($conference['rounds'] ?? []) as $round) {
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
	 * @param array<string, list<array<string, mixed>>> $plans The plans by schema: `group-plan` and `learning-plan`.
	 * @param string                                    $today Today, `Y-m-d`.
	 *
	 * @return array<string, mixed> count, planId, schema (`group-plan` or `learning-plan`), endsOn (of the earliest).
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 */
	public function planEnding(array $plans, string $today): array {
		$until = (new DateTimeImmutable($today))->modify('+' . self::PLAN_HORIZON_DAYS . ' days')->format('Y-m-d');
		$ending = [];
		foreach (['group-plan' => 'periodEndDate', 'learning-plan' => 'nextReviewAt'] as $schema => $field) {
			foreach (($plans[$schema] ?? []) as $plan) {
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
	public function signalNotTakenUp(array $requests): array {
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
	public function leavePastHalf(array $requests, DateTimeImmutable $now): array {
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
}//end class
