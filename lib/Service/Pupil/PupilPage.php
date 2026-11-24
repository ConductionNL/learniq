<?php

/**
 * Learniq Pupil Page
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the pupil's page puts in its header and its side column, worked out
 * from records already read: the name and initials, the state pill (the most
 * important open signal, else today's state), the group, the age, and the
 * qualification with its crebo of a running placement, and the school's own
 * pupil number; and the people around the pupil (the mentor, a duo partner,
 * the group's team leader, the care coordinator or that no plan runs, the
 * group's career coach, the praktijkopleider and the BPV-begeleider). It answers data, the
 * page words it, and it holds no data of its own.
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
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupils-page-opens-with-a-header-one-next-step-and-the-people-around-the-pupil
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Pupil;

/**
 * Composes the pupil page's header and people.
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupils-page-opens-with-a-header-one-next-step-and-the-people-around-the-pupil
 */
final class PupilPage {

	public const PILL_SIGNAL = 'signal';
	public const PILL_TODAY = 'today';

	public const MENTOR = 'mentor';
	public const DUO_PARTNER = 'duo-partner';
	public const CARE_COORDINATOR = 'care-coordinator';
	public const PRAKTIJKOPLEIDER = 'praktijkopleider';
	public const BPV_BEGELEIDER = 'bpv-begeleider';
	public const TEAM_LEADER = 'team-leader';
	public const CAREER_COACH = 'career-coach';

	/**
	 * Attendance flag states that are still open.
	 *
	 * @var list<string>
	 */
	public const OPEN_FLAG = ['open', 'in-handling'];

	/**
	 * Learning plan states in which a plan runs.
	 *
	 * @var list<string>
	 */
	public const RUNNING_PLAN = ['active', 'under-evaluation'];

	/**
	 * Placement states in which a placement runs, the most current first.
	 *
	 * @var list<string>
	 */
	public const RUNNING_PLACEMENT = ['active', 'confirmed'];

	/**
	 * The page header.
	 *
	 * @param array<string, mixed>       $profile    The pupil's profile.
	 * @param array<string, mixed>       $cohort     The group the page is opened from.
	 * @param string                     $todayState The pupil's state today (a TodayState constant).
	 * @param list<array<string, mixed>> $flags      The pupil's attendance flags.
	 * @param array<string, mixed>|null  $placement  The running placement, or null.
	 *
	 * @return array<string, mixed> name, initials, statePill {kind, value}, group, age, pupilNumber, qualification and crebo.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
	 */
	public static function header(array $profile, array $cohort, string $todayState, array $flags, ?array $placement): array {
		$name = self::nameOf(profile: $profile);
		$age = ($profile['ageYears'] ?? null);
		if (is_int($age) === false) {
			$age = null;
		}

		return [
			'name' => $name,
			'initials' => self::initialsOf(profile: $profile, name: $name),
			'statePill' => self::statePill(todayState: $todayState, flags: $flags),
			'group' => (string)($cohort['name'] ?? ''),
			'age' => $age,
			'pupilNumber' => self::stringOrNull(value: ($profile['pupilNumber'] ?? null)),
			'qualification' => trim((string)($placement['qualificationName'] ?? '')),
			'crebo' => trim((string)($placement['crebo'] ?? '')),
		];
	}//end header()

	/**
	 * The state pill: the oldest open signal, else today's state.
	 *
	 * @param string                     $todayState The state today.
	 * @param list<array<string, mixed>> $flags      The attendance flags.
	 *
	 * @return array{kind: string, value: string}
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
	 */
	public static function statePill(string $todayState, array $flags): array {
		$open = array_values(
			array_filter($flags, static fn (array $flag): bool => in_array((string)($flag['lifecycle'] ?? ''), self::OPEN_FLAG, true))
		);
		if ($open === []) {
			return ['kind' => self::PILL_TODAY, 'value' => $todayState];
		}

		usort($open, static fn (array $a, array $b): int => (string)($a['windowEnd'] ?? '') <=> (string)($b['windowEnd'] ?? ''));

		return ['kind' => self::PILL_SIGNAL, 'value' => (string)($open[0]['flagKind'] ?? '')];
	}//end statePill()

	/**
	 * The placement that runs now: an active one before a confirmed one, else null.
	 *
	 * @param list<array<string, mixed>> $placements The pupil's placements.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T4
	 */
	public static function runningPlacement(array $placements): ?array {
		foreach (self::RUNNING_PLACEMENT as $state) {
			foreach ($placements as $placement) {
				if ((string)($placement['lifecycle'] ?? '') === $state) {
					return $placement;
				}
			}
		}

		return null;
	}//end runningPlacement()

	/**
	 * The people around the pupil, as role and user id; names are added by the caller.
	 *
	 * The care coordinator is always listed: with `noPlan` true and no user
	 * when no learning plan runs. The group's team leader and career coach
	 * (studieloopbaan) are listed only when the group names them. The
	 * praktijkopleider and the BPV-begeleider are listed only for a pupil in
	 * a running placement.
	 *
	 * @param array<string, mixed>       $cohort    The group.
	 * @param list<array<string, mixed>> $plans     The pupil's learning plans.
	 * @param array<string, mixed>|null  $placement The running placement, or null.
	 *
	 * @return list<array{role: string, userId: string|null, noPlan?: bool}>
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T4
	 */
	public static function people(array $cohort, array $plans, ?array $placement): array {
		$people = [];
		foreach (self::teachers(cohort: $cohort) as $role => $userId) {
			$people[] = ['role' => $role, 'userId' => $userId];
		}

		$teamLeader = self::stringOrNull(value: ($cohort['teamLeaderId'] ?? null));
		if ($teamLeader !== null) {
			$people[] = ['role' => self::TEAM_LEADER, 'userId' => $teamLeader];
		}

		$coordinator = self::coordinatorOf(plans: $plans);
		$people[]    = ['role' => self::CARE_COORDINATOR, 'userId' => $coordinator, 'noPlan' => ($coordinator === null)];

		$careerCoach = self::stringOrNull(value: ($cohort['careerCoachId'] ?? null));
		if ($careerCoach !== null) {
			$people[] = ['role' => self::CAREER_COACH, 'userId' => $careerCoach];
		}

		if ($placement !== null) {
			$people[] = ['role' => self::PRAKTIJKOPLEIDER, 'userId' => self::stringOrNull(value: ($placement['practicalTrainerId'] ?? null))];
			$people[] = ['role' => self::BPV_BEGELEIDER, 'userId' => self::stringOrNull(value: ($placement['schoolCoachId'] ?? null))];
		}

		return $people;
	}//end people()

	/**
	 * The group's mentor and duo partner: the primary teacher of its
	 * assignments (else its first teacher) and the first duo partner.
	 *
	 * @param array<string, mixed> $cohort The group.
	 *
	 * @return array<string, string>
	 */
	private static function teachers(array $cohort): array {
		$roles = self::withFirstTeacherAsMentor(roles: self::assignedRoles(cohort: $cohort), cohort: $cohort);

		$ordered = [];
		foreach ([self::MENTOR, self::DUO_PARTNER] as $role) {
			if (isset($roles[$role]) === true) {
				$ordered[$role] = $roles[$role];
			}
		}

		return $ordered;
	}//end teachers()

	/**
	 * The first teacher per role among the group's teacher assignments.
	 *
	 * @param array<string, mixed> $cohort The group.
	 *
	 * @return array<string, string> Role => teacher id.
	 */
	private static function assignedRoles(array $cohort): array {
		$roles = [];
		foreach ((array)($cohort['teacherAssignments'] ?? []) as $assignment) {
			$pair = self::assignmentPair(assignment: $assignment);
			if ($pair !== null && isset($roles[$pair[0]]) === false) {
				$roles[$pair[0]] = $pair[1];
			}
		}

		return $roles;
	}//end assignedRoles()

	/**
	 * The role and teacher id of one teacher assignment.
	 *
	 * @param mixed $assignment The assignment as stored.
	 *
	 * @return array{0: string, 1: string}|null Role and teacher id; null when it names no teacher or another role.
	 */
	private static function assignmentPair(mixed $assignment): ?array {
		if (is_array($assignment) === false) {
			return null;
		}

		$teacherId = self::stringOrNull(value: ($assignment['teacherId'] ?? null));
		$role = self::roleOf(assignment: $assignment);
		if ($teacherId === null || $role === null) {
			return null;
		}

		return [$role, $teacherId];
	}//end assignmentPair()

	/**
	 * The page role of a teacher assignment.
	 *
	 * @param array<string, mixed> $assignment The assignment.
	 *
	 * @return string|null The mentor or duo partner role, or null for another role.
	 */
	private static function roleOf(array $assignment): ?string {
		return match ($assignment['role'] ?? null) {
			'primary' => self::MENTOR,
			'duo-partner' => self::DUO_PARTNER,
			default => null,
		};
	}//end roleOf()

	/**
	 * The roles, with the group's first teacher who is not the duo partner as
	 * mentor when no assignment names one.
	 *
	 * @param array<string, string> $roles  Role => teacher id.
	 * @param array<string, mixed>  $cohort The group.
	 *
	 * @return array<string, string>
	 */
	private static function withFirstTeacherAsMentor(array $roles, array $cohort): array {
		if (isset($roles[self::MENTOR]) === true) {
			return $roles;
		}

		foreach ((array)($cohort['teacherIds'] ?? []) as $teacherId) {
			$teacherId = self::stringOrNull(value: $teacherId);
			if ($teacherId !== null && $teacherId !== ($roles[self::DUO_PARTNER] ?? null)) {
				$roles[self::MENTOR] = $teacherId;
				break;
			}
		}

		return $roles;
	}//end withFirstTeacherAsMentor()

	/**
	 * The coordinator of the running learning plan, or null when none runs.
	 *
	 * @param list<array<string, mixed>> $plans The learning plans.
	 *
	 * @return string|null
	 */
	private static function coordinatorOf(array $plans): ?string {
		foreach (self::RUNNING_PLAN as $state) {
			foreach ($plans as $plan) {
				if ((string)($plan['lifecycle'] ?? '') === $state) {
					return self::stringOrNull(value: ($plan['coordinatorId'] ?? null));
				}
			}
		}

		return null;
	}//end coordinatorOf()

	/**
	 * The pupil's name: the full name, else given and family name.
	 *
	 * @param array<string, mixed> $profile The profile.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
	 */
	public static function nameOf(array $profile): string {
		$full = trim((string)($profile['fullName'] ?? ''));
		if ($full !== '') {
			return $full;
		}

		return trim(trim((string)($profile['givenName'] ?? '')) . ' ' . trim((string)($profile['familyName'] ?? '')));
	}//end nameOf()

	/**
	 * The initials: the first letters of the given and the last family name part.
	 *
	 * @param array<string, mixed> $profile The profile.
	 * @param string               $name    The name.
	 *
	 * @return string
	 */
	private static function initialsOf(array $profile, string $name): string {
		$given = trim((string)($profile['givenName'] ?? ''));
		$family = preg_split('/\s+/', trim((string)($profile['familyName'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
		if ($given === '' || $family === false || $family === []) {
			$parts = self::nameParts(name: $name);
			if ($parts === null) {
				return '';
			}

			[$given, $family] = $parts;
		}

		$initials = mb_strtoupper(mb_substr($given, 0, 1));
		if ($family !== []) {
			$initials .= mb_strtoupper(mb_substr($family[count($family) - 1], 0, 1));
		}

		return $initials;
	}//end initialsOf()

	/**
	 * The given name and last family name part read from a full name.
	 *
	 * @param string $name The name.
	 *
	 * @return array{0: string, 1: list<string>}|null The first word and the last one (none for one word); null for no words.
	 */
	private static function nameParts(string $name): ?array {
		$parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
		if ($parts === false || $parts === []) {
			return null;
		}

		$family = [];
		if (count($parts) > 1) {
			$family = [$parts[count($parts) - 1]];
		}

		return [$parts[0], $family];
	}//end nameParts()

	/**
	 * A non-empty string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private static function stringOrNull(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return $value;
	}//end stringOrNull()
}//end class
