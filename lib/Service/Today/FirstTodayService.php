<?php

/**
 * Learniq First Today Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The First today card of the signed-in user: her role on Today (from her
 * primary role, the school kind and the view she switched to), and the first
 * rule of that role's ordered list that has a row, with what the card names.
 * Each rule's reads are made only when the rules before it found nothing.
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
use OCA\Learniq\Kernel\Contracts\DashboardRoleServiceInterface;
use OCA\Learniq\Kernel\Contracts\SegmentServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;

/**
 * Answers the First today card for a user.
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
 */
class FirstTodayService {

	/**
	 * Today's groups already read in this request, by caller, kind and day.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $groupsRead = [];

	/**
	 * Constructor.
	 *
	 * @param DashboardRoleServiceInterface $roles    The user's primary role.
	 * @param SegmentServiceInterface       $segments The school kind.
	 * @param FirstTodayReader              $reader   The reads behind each rule.
	 * @param ITimeFactory                  $time     The clock.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DashboardRoleServiceInterface $roles,
		private readonly SegmentServiceInterface $segments,
		private readonly FirstTodayReader $reader,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The user's First today card.
	 *
	 * @param IUser  $user The caller.
	 * @param string $view The view asked for: '' or `mentor`.
	 *
	 * @return array<string, mixed> `role` (null when Today has no card for her), `rule` (null when nothing is first),
	 *                              `count` (0 when nothing is first) and the winning rule's facts.
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-a-mentor-switches-today-to-her-mentor-class
	 */
	public function forUser(IUser $user, string $view=''): array {
		$role = FirstTodayRules::roleFor(
			primaryRole: $this->roles->resolvePrimaryRole(user: $user),
			segment: $this->segments->currentSegment(),
			view: $view
		);
		if ($role === null) {
			return ['role' => null, 'rule' => null, 'count' => 0];
		}

		$zone = $this->reader->zone(user: $user);
		$now = (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone($zone);
		$context = ['user' => $user, 'now' => $now, 'today' => $now->format('Y-m-d'), 'zone' => $zone];

		$first = FirstTodayRules::first(
			role: $role,
			probe: fn (string $rule): ?array => $this->facts(rule: $rule, context: $context)
		);

		return ($first ?? ['role' => $role, 'rule' => null, 'count' => 0]);
	}//end forUser()

	/**
	 * One rule's facts for the caller.
	 *
	 * @param string               $rule    A rule of FirstTodayRules.
	 * @param array<string, mixed> $context `user`, `now`, `today` and `zone`.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/today-first-per-school-role/tasks.md#T1
	 */
	private function facts(string $rule, array $context): ?array {
		/** @var IUser $user */
		$user = $context['user'];
		/** @var DateTimeImmutable $now */
		$now = $context['now'];
		$uid = $user->getUID();

		return match ($rule) {
			FirstTodayRules::REGISTER_NOT_FILLED => FirstTodayFacts::registerNotFilled(
				groups: $this->groups(context: $context, own: true),
				now: $now
			),
			FirstTodayRules::REGISTERS_NOT_FILLED_AT_930 => FirstTodayFacts::registersNotFilledAt930(
				groups: $this->groups(context: $context, own: false),
				now: $now
			),
			FirstTodayRules::LESSON_REGISTER_OPEN => FirstTodayFacts::lessonRegisterOpen(
				sessions: $this->ownSessions(context: $context),
				now: $now
			),
			FirstTodayRules::BOOKINGS_TO_CONFIRM => FirstTodayFacts::bookingsToConfirm(
				slots: $this->reader->rows(schema: 'conference-slot', filters: ['teacherId' => $uid, 'lifecycle' => 'booked']),
				uid: $uid
			),
			FirstTodayRules::NEARING_LIMIT => FirstTodayFacts::nearingLimit(
				summaries: $this->reader->rows(schema: 'attendance-summary', filters: ['thresholdNearing' => true]),
				uid: $uid
			),
			FirstTodayRules::MISSING_HAND_INS => FirstTodayFacts::missingHandIns(
				assignments: $this->reader->rows(schema: 'assignment', filters: ['lifecycle' => 'published']),
				groupSizes: $this->groupSizes(context: $context),
				today: (string)$context['today'],
				zone: $context['zone']
			),
			FirstTodayRules::UNAUTHORISED_ABSENCE => FirstTodayFacts::unauthorisedAbsence(
				flags: $this->reader->rows(schema: 'attendance-flag', filters: ['mentorId' => $uid, 'lifecycle' => 'open']),
				uid: $uid,
				now: $now
			),
			FirstTodayRules::MENTOR_TALKS_CLOSING => FirstTodayFacts::mentorTalksClosing(
				rounds: $this->reader->rows(schema: 'conference-round'),
				slots: $this->reader->rows(schema: 'conference-slot'),
				uid: $uid,
				now: $now
			),
			FirstTodayRules::PLAN_ENDING => FirstTodayFacts::planEnding(
				groupPlans: $this->reader->rows(schema: 'group-plan', filters: ['lifecycle' => 'active']),
				learningPlans: $this->reader->rows(schema: 'learning-plan', filters: ['lifecycle' => 'active']),
				today: (string)$context['today']
			),
			FirstTodayRules::SIGNAL_NOT_TAKEN_UP => FirstTodayFacts::signalNotTakenUp(
				requests: $this->reader->rows(schema: 'support-request', filters: ['lifecycle' => 'submitted'])
			),
			FirstTodayRules::LEAVE_PAST_HALF => FirstTodayFacts::leavePastHalf(
				requests: $this->reader->rows(schema: 'leave-request', filters: ['lifecycle' => 'submitted']),
				now: $now
			),
			FirstTodayRules::OVER_LIMIT_NOT_REPORTED => FirstTodayFacts::overLimitNotReported(
				flags: $this->reader->rows(schema: 'attendance-flag')
			),
			default => null,
		};
	}//end facts()

	/**
	 * Today's groups of the caller, read once per request and kind.
	 *
	 * @param array<string, mixed> $context The request's context.
	 * @param bool                 $own     Only the groups she teaches.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function groups(array $context, bool $own): array {
		/** @var DateTimeZone $zone */
		$zone = $context['zone'];
		/** @var IUser $user */
		$user = $context['user'];
		$key = $user->getUID() . '|' . (int)$own . '|' . (string)$context['today'];
		if (isset($this->groupsRead[$key]) === false) {
			$this->groupsRead[$key] = $this->reader->groupsToday(user: $user, own: $own, today: (string)$context['today'], zone: $zone);
		}

		return $this->groupsRead[$key];
	}//end groups()

	/**
	 * The caller's lessons of today, each with its number of saved marks.
	 *
	 * @param array<string, mixed> $context The request's context.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function ownSessions(array $context): array {
		$sessions = [];
		foreach ($this->groups(context: $context, own: true) as $group) {
			foreach ((array)$group['sessions'] as $session) {
				$sessionId = (string)($session['id'] ?? ($session['uuid'] ?? ''));
				$sessions[] = array_merge((array)$session, ['markCount' => (int)($group['records'][$sessionId] ?? 0)]);
			}
		}

		return $sessions;
	}//end ownSessions()

	/**
	 * The number of pupils per group the caller teaches.
	 *
	 * @param array<string, mixed> $context The request's context.
	 *
	 * @return array<string, int>
	 */
	private function groupSizes(array $context): array {
		$sizes = [];
		foreach ($this->groups(context: $context, own: true) as $group) {
			$cohort = (array)$group['cohort'];
			$sizes[(string)($cohort['id'] ?? ($cohort['uuid'] ?? ''))] = count((array)($cohort['learnerIds'] ?? []));
		}

		return $sizes;
	}//end groupSizes()
}//end class
