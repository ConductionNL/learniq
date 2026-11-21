<?php

/**
 * Learniq First Today Rules
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The ordered rule list behind the First today card on Today. Each role has
 * its rules in a fixed order (today-first-per-school-role design.md); the
 * card shows the first rule that has at least one row, and no card when no
 * rule has one. The order is written here once, so a reviewer changes it in
 * one place. A rule is a name; what it counts is FirstTodayFacts', and what
 * the card says is the manifest's, one card per rule.
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

/**
 * Which role reads which rules, in which order, and which rule wins.
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
 */
final class FirstTodayRules {

	public const ROLE_GROUP_TEACHER = 'group-teacher';
	public const ROLE_SUBJECT_TEACHER = 'subject-teacher';
	public const ROLE_MENTOR = 'mentor';
	public const ROLE_CARE_COORDINATOR = 'care-coordinator';
	public const ROLE_HEAD = 'head';
	public const ROLE_BPV_COACH = 'bpv-coach';
	public const ROLE_EXAM_COMMITTEE = 'exam-committee';

	public const REGISTER_NOT_FILLED = 'register-not-filled';
	public const BOOKINGS_TO_CONFIRM = 'bookings-to-confirm';
	public const NEARING_LIMIT = 'nearing-limit';
	public const LESSON_REGISTER_OPEN = 'lesson-register-open';
	public const MISSING_HAND_INS = 'missing-hand-ins';
	public const UNAUTHORISED_ABSENCE = 'unauthorised-absence';
	public const MENTOR_TALKS_CLOSING = 'mentor-talks-closing';
	public const PLAN_ENDING = 'plan-ending';
	public const SIGNAL_NOT_TAKEN_UP = 'signal-not-taken-up';
	public const LEAVE_PAST_HALF = 'leave-past-half';
	public const OVER_LIMIT_NOT_REPORTED = 'over-limit-not-reported';
	public const REGISTERS_NOT_FILLED_AT_930 = 'registers-not-filled-at-930';
	public const PLACEMENT_WITHOUT_COMPANY = 'placement-without-company';
	public const VISIT_WITHOUT_DATE = 'visit-without-date';
	public const HOURS_WAITING = 'hours-waiting';
	public const OBJECTION_PAST_HALF = 'objection-past-half';
	public const RESULTS_WAITING = 'results-waiting';

	/**
	 * The view a mentor switches Today to.
	 *
	 * @var string
	 */
	public const VIEW_MENTOR = 'mentor';

	/**
	 * The view a BPV-begeleider switches Today to (bpv-coach-today-and-placement-board).
	 *
	 * @var string
	 */
	public const VIEW_BPV = 'bpv';

	/**
	 * The school kinds that have an exam committee.
	 *
	 * @var list<string>
	 */
	private const EXAM_SCHOOLS = ['vo', 'mbo', 'he'];

	/**
	 * Each role's rules, first wins (design.md, "The First today card is one ordered list of rules per role").
	 *
	 * @var array<string, list<string>>
	 */
	public const ORDER = [
		self::ROLE_GROUP_TEACHER => [
			self::REGISTER_NOT_FILLED,
			self::BOOKINGS_TO_CONFIRM,
			self::NEARING_LIMIT,
		],
		self::ROLE_SUBJECT_TEACHER => [
			self::LESSON_REGISTER_OPEN,
			self::NEARING_LIMIT,
			self::MISSING_HAND_INS,
		],
		self::ROLE_MENTOR => [
			self::UNAUTHORISED_ABSENCE,
			self::MENTOR_TALKS_CLOSING,
		],
		self::ROLE_CARE_COORDINATOR => [
			self::PLAN_ENDING,
			self::SIGNAL_NOT_TAKEN_UP,
		],
		self::ROLE_HEAD => [
			self::LEAVE_PAST_HALF,
			self::OVER_LIMIT_NOT_REPORTED,
			self::REGISTERS_NOT_FILLED_AT_930,
		],
		self::ROLE_BPV_COACH => [
			self::PLACEMENT_WITHOUT_COMPANY,
			self::VISIT_WITHOUT_DATE,
			self::HOURS_WAITING,
		],
		self::ROLE_EXAM_COMMITTEE => [
			self::OBJECTION_PAST_HALF,
			self::RESULTS_WAITING,
		],
	];

	/**
	 * The role whose rules a user reads on Today, or null for a user Today has no card for.
	 *
	 * A teacher (`instructor`) is a group teacher in primary education and a
	 * subject teacher elsewhere. The care coordinator is `coordinator`; the
	 * directeur and the teamleider are `administration-manager` and
	 * `team-lead`; an administrator reads the head's rules.
	 *
	 * @param string $primaryRole The role DashboardRoleService resolves.
	 * @param string $segment     The school kind (po, vo, mbo, ...).
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 */
	public function roleFor(string $primaryRole, string $segment): ?string {
		if ($primaryRole === 'instructor') {
			if ($segment === 'po') {
				return self::ROLE_GROUP_TEACHER;
			}

			return self::ROLE_SUBJECT_TEACHER;
		}

		if ($primaryRole === 'compliance-officer') {
			return $this->examCommittee(segment: $segment);
		}

		return match ($primaryRole) {
			'coordinator' => self::ROLE_CARE_COORDINATOR,
			'administration-manager', 'team-lead', 'admin' => self::ROLE_HEAD,
			default => null,
		};
	}//end roleFor()

	/**
	 * The exam committee's role, for a school kind that has one.
	 *
	 * In a school the compliance officers' group is the exam committee
	 * (DashboardRoleService::EXAM_BOARD_GROUP); a company or training
	 * provider has none, so its compliance officer gets no card.
	 *
	 * @param string $segment The school kind.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#T3
	 */
	private function examCommittee(string $segment): ?string {
		if (in_array($segment, self::EXAM_SCHOOLS, true) === true) {
			return self::ROLE_EXAM_COMMITTEE;
		}

		return null;
	}//end examCommittee()

	/**
	 * The role a view gives: a teacher in the Mentor view reads the mentor's
	 * rules, in the BPV view the BPV-begeleider's.
	 *
	 * Mentor and BPV-begeleider are views, not roles (design.md): the server
	 * resolves neither, and a role that is no teacher's keeps its own rules.
	 *
	 * @param string|null $role The role roleFor() gave.
	 * @param string      $view The view asked for ('' or `mentor`).
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-a-mentor-switches-today-to-her-mentor-class
	 * @spec openspec/changes/bpv-coach-today-and-placement-board/tasks.md#T5
	 */
	public function inView(?string $role, string $view): ?string {
		if (in_array($role, [self::ROLE_GROUP_TEACHER, self::ROLE_SUBJECT_TEACHER], true) === false) {
			return $role;
		}

		return match ($view) {
			self::VIEW_MENTOR => self::ROLE_MENTOR,
			self::VIEW_BPV => self::ROLE_BPV_COACH,
			default => $role,
		};
	}//end inView()

	/**
	 * The first rule of a role that has at least one row.
	 *
	 * Rules are asked in order and the asking stops at the first that has a
	 * row, so a later rule's reads are not made when an earlier one wins.
	 * The probe answers a rule's facts with at least `count`, or null when
	 * the rule cannot be read for this user.
	 *
	 * @param string                                    $role  A role of ORDER.
	 * @param callable(string): (array<string, mixed>|null) $probe The facts of one rule.
	 *
	 * @return array<string, mixed>|null `role`, `rule`, `count` and the rule's other facts; null when no rule has a row.
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 */
	public function first(string $role, callable $probe): ?array {
		foreach ((self::ORDER[$role] ?? []) as $rule) {
			$facts = $probe($rule);
			if (is_array($facts) === false || (int)($facts['count'] ?? 0) < 1) {
				continue;
			}

			return array_merge($facts, ['role' => $role, 'rule' => $rule, 'count' => (int)$facts['count']]);
		}

		return null;
	}//end first()

	/**
	 * Every rule any role reads, once each, in the order they first appear.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/today-first-per-school-role/tasks.md#T1
	 */
	public function allRules(): array {
		$rules = [];
		foreach (self::ORDER as $list) {
			foreach ($list as $rule) {
				if (in_array($rule, $rules, true) === false) {
					$rules[] = $rule;
				}
			}
		}

		return $rules;
	}//end allRules()
}//end class
