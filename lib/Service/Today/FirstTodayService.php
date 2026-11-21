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

use OCA\Learniq\Kernel\Contracts\DashboardRoleServiceInterface;
use OCA\Learniq\Kernel\Contracts\SegmentServiceInterface;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers the First today card for a user.
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
 */
class FirstTodayService {

	/**
	 * The ordered rules per role.
	 *
	 * @var FirstTodayRules
	 */
	private readonly FirstTodayRules $rules;

	/**
	 * What the register rules count.
	 *
	 * @var RegisterFacts
	 */
	private readonly RegisterFacts $registers;

	/**
	 * What the attendance rules count.
	 *
	 * @var AttendanceFacts
	 */
	private readonly AttendanceFacts $attendance;

	/**
	 * What the follow-up rules count.
	 *
	 * @var FollowUpFacts
	 */
	private readonly FollowUpFacts $followUp;

	/**
	 * What the BPV-begeleider's rules count.
	 *
	 * @var PlacementFacts
	 */
	private readonly PlacementFacts $placements;

	/**
	 * What the exam committee's rules count.
	 *
	 * @var ExamFacts
	 */
	private readonly ExamFacts $exams;

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
	 * @param FirstTodayReader              $reader   The reads behind each rule, and the clock.
	 * @param LoggerInterface               $logger   Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DashboardRoleServiceInterface $roles,
		private readonly SegmentServiceInterface $segments,
		private readonly FirstTodayReader $reader,
		private readonly LoggerInterface $logger,
	) {
		$this->rules      = new FirstTodayRules();
		$this->registers  = new RegisterFacts();
		$this->attendance = new AttendanceFacts();
		$this->followUp   = new FollowUpFacts();
		$this->placements = new PlacementFacts();
		$this->exams      = new ExamFacts();
	}//end __construct()

	/**
	 * The user's First today card, or null when it could not be read.
	 *
	 * @param IUser  $user The caller.
	 * @param string $view The view asked for: '' or `mentor`.
	 *
	 * @return array<string, mixed>|null `role` (null when Today has no card for her), `rule` (null when nothing
	 *                                   is first), `count` (0 when nothing is first) and the winning rule's facts.
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-a-mentor-switches-today-to-her-mentor-class
	 */
	public function cardFor(IUser $user, string $view=''): ?array {
		try {
			return $this->forUser(user: $user, view: $view);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[FirstTodayService] The First today card could not be read: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return null;
		}
	}//end cardFor()

	/**
	 * The user's First today card.
	 *
	 * @param IUser  $user The caller.
	 * @param string $view The view asked for: '' or `mentor`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 */
	public function forUser(IUser $user, string $view=''): array {
		$role = $this->rules->inView(
			role: $this->rules->roleFor(primaryRole: $this->roles->resolvePrimaryRole(user: $user), segment: $this->segments->currentSegment()),
			view: $view
		);
		if ($role === null) {
			return ['role' => null, 'rule' => null, 'count' => 0];
		}

		$context = $this->reader->contextFor(user: $user);
		$first = $this->rules->first(
			role: $role,
			probe: fn (string $rule): ?array => $this->facts(rule: $rule, context: $context)
		);

		return ($first ?? ['role' => $role, 'rule' => null, 'count' => 0]);
	}//end forUser()

	/**
	 * One rule's facts for the caller.
	 *
	 * @param string       $rule    A rule of FirstTodayRules.
	 * @param TodayContext $context The caller and now.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/today-first-per-school-role/tasks.md#T1
	 */
	private function facts(string $rule, TodayContext $context): ?array {
		$uid = $context->uid();
		$now = $context->now;

		return match ($rule) {
			FirstTodayRules::REGISTER_NOT_FILLED => $this->registers->registerNotFilled(groups: $this->groups(context: $context, own: true), now: $now),
			FirstTodayRules::REGISTERS_NOT_FILLED_AT_930 => $this->registers->registersNotFilledAt930(
				groups: $this->groups(context: $context, own: false),
				now: $now
			),
			FirstTodayRules::LESSON_REGISTER_OPEN => $this->registers->lessonRegisterOpen(sessions: $this->ownSessions(context: $context), now: $now),
			FirstTodayRules::NEARING_LIMIT => $this->attendance->nearingLimit(
				summaries: $this->reader->rows(schema: 'attendance-summary', filters: ['thresholdNearing' => true]),
				uid: $uid
			),
			FirstTodayRules::UNAUTHORISED_ABSENCE => $this->attendance->unauthorisedAbsence(
				flags: $this->reader->rows(schema: 'attendance-flag', filters: ['mentorId' => $uid, 'lifecycle' => 'open']),
				context: $context
			),
			FirstTodayRules::OVER_LIMIT_NOT_REPORTED => $this->attendance->overLimitNotReported(flags: $this->reader->rows(schema: 'attendance-flag')),
			default => $this->followUpFacts(rule: $rule, context: $context),
		};
	}//end facts()

	/**
	 * One follow-up rule's facts for the caller.
	 *
	 * @param string       $rule    A rule of FirstTodayRules.
	 * @param TodayContext $context The caller and now.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/today-first-per-school-role/tasks.md#T1
	 */
	private function followUpFacts(string $rule, TodayContext $context): ?array {
		$uid = $context->uid();

		return match ($rule) {
			FirstTodayRules::BOOKINGS_TO_CONFIRM => $this->followUp->bookingsToConfirm(
				slots: $this->reader->rows(schema: 'conference-slot', filters: ['teacherId' => $uid, 'lifecycle' => 'booked']),
				uid: $uid
			),
			FirstTodayRules::MISSING_HAND_INS => $this->followUp->missingHandIns(
				assignments: $this->reader->rows(schema: 'assignment', filters: ['lifecycle' => 'published']),
				groupSizes: $this->groupSizes(context: $context),
				context: $context
			),
			FirstTodayRules::MENTOR_TALKS_CLOSING => $this->followUp->mentorTalksClosing(
				conference: ['rounds' => $this->reader->rows(schema: 'conference-round'), 'slots' => $this->reader->rows(schema: 'conference-slot')],
				context: $context
			),
			FirstTodayRules::PLAN_ENDING => $this->followUp->planEnding(
				plans: [
					'group-plan' => $this->reader->rows(schema: 'group-plan', filters: ['lifecycle' => 'active']),
					'learning-plan' => $this->reader->rows(schema: 'learning-plan', filters: ['lifecycle' => 'active']),
				],
				today: $context->today()
			),
			FirstTodayRules::SIGNAL_NOT_TAKEN_UP => $this->followUp->signalNotTakenUp(
				requests: $this->reader->rows(schema: 'support-request', filters: ['lifecycle' => 'submitted'])
			),
			FirstTodayRules::LEAVE_PAST_HALF => $this->followUp->leavePastHalf(
				requests: $this->reader->rows(schema: 'leave-request', filters: ['lifecycle' => 'submitted']),
				now: $context->now
			),
			default => $this->programmeFacts(rule: $rule, context: $context),
		};
	}//end followUpFacts()

	/**
	 * One rule of the BPV-begeleider or the exam committee, for the caller.
	 *
	 * @param string       $rule    A rule of FirstTodayRules.
	 * @param TodayContext $context The caller and now.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/bpv-coach-today-and-placement-board/tasks.md#T5
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#T3
	 */
	private function programmeFacts(string $rule, TodayContext $context): ?array {
		$uid = $context->uid();

		return match ($rule) {
			FirstTodayRules::PLACEMENT_WITHOUT_COMPANY => $this->placements->placementWithoutCompany(
				placements: $this->reader->rows(schema: 'bpv-placement', filters: ['schoolCoachId' => $uid, 'phase' => 'searching']),
				context: $context
			),
			FirstTodayRules::VISIT_WITHOUT_DATE => $this->placements->visitWithoutDate(
				visits: $this->reader->rows(schema: 'bpv-visit', filters: ['schoolCoachId' => $uid, 'lifecycle' => 'unplanned']),
				uid: $uid
			),
			FirstTodayRules::HOURS_WAITING => $this->placements->hoursWaiting(
				bpv: [
					'placements' => $this->reader->rows(schema: 'bpv-placement', filters: ['schoolCoachId' => $uid]),
					'weeks' => $this->reader->rows(schema: 'bpv-hour-week', filters: ['lifecycle' => 'submitted']),
				],
				context: $context
			),
			FirstTodayRules::OBJECTION_PAST_HALF => $this->exams->objectionPastHalf(
				objections: $this->reader->rows(schema: 'exam-objection'),
				now: $context->now
			),
			FirstTodayRules::RESULTS_WAITING => $this->exams->resultsWaiting(
				results: $this->reader->rows(schema: 'grade-entry', filters: ['lifecycle' => 'published']),
				now: $context->now
			),
			default => null,
		};
	}//end programmeFacts()

	/**
	 * Today's groups of the caller, read once per request and kind.
	 *
	 * @param TodayContext $context The caller and now.
	 * @param bool         $own     Only the groups she teaches.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function groups(TodayContext $context, bool $own): array {
		$key = $context->uid() . '|' . (int)$own . '|' . $context->today();
		if (isset($this->groupsRead[$key]) === false) {
			$this->groupsRead[$key] = $this->reader->groupsToday(context: $context, own: $own);
		}

		return $this->groupsRead[$key];
	}//end groups()

	/**
	 * The caller's lessons of today, each with its number of saved marks.
	 *
	 * @param TodayContext $context The caller and now.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function ownSessions(TodayContext $context): array {
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
	 * @param TodayContext $context The caller and now.
	 *
	 * @return array<string, int>
	 */
	private function groupSizes(TodayContext $context): array {
		$sizes = [];
		foreach ($this->groups(context: $context, own: true) as $group) {
			$cohort = (array)$group['cohort'];
			$sizes[(string)($cohort['id'] ?? ($cohort['uuid'] ?? ''))] = count((array)($cohort['learnerIds'] ?? []));
		}

		return $sizes;
	}//end groupSizes()
}//end class
