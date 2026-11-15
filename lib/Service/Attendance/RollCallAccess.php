<?php

/**
 * Learniq Roll Call Access
 *
 * Who may open and save which register:
 *
 * - a member of `instructors` opens the current groups that list them in
 *   `teacherIds` or `teacherAssignments`;
 * - members of `coordinators` and `administration-managers`, and admins,
 *   open every current group;
 * - anyone else opens none.
 *
 * And when a register can be saved: today always; an earlier day by
 * school-wide staff, or by a group teacher while no teacher saved it yet;
 * a later day never. "Today" is the caller's own date.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Attendance
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
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-who-may-open-which-groups-register
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Attendance;

use DateTimeImmutable;
use DateTimeZone;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;

/**
 * Decides who opens which group, and whether a day can be saved.
 *
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-who-may-open-which-groups-register
 */
class RollCallAccess {
	use RollCallRows;

	private const TEACHER_GROUP = 'instructors';
	private const SCHOOL_WIDE_GROUPS = ['coordinators', 'administration-managers'];

	/**
	 * The page's lock reasons.
	 */
	public const LOCKED_FUTURE = 'future';
	public const LOCKED_PAST_SAVED = 'past-saved';

	/**
	 * Constructor.
	 *
	 * @param RollCallReader $reader       The groups.
	 * @param IGroupManager  $groupManager Group membership.
	 * @param IDateTimeZone  $timeZone     The caller's time zone.
	 * @param ITimeFactory   $time         Clock.
	 * @param IL10N          $l10n         Messages.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RollCallReader $reader,
		private readonly IGroupManager $groupManager,
		private readonly IDateTimeZone $timeZone,
		private readonly ITimeFactory $time,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The groups the caller may open, sorted by name.
	 *
	 * @param IUser $user The caller.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws RollCallException 403 for someone who is neither a teacher nor school-wide staff.
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-who-may-open-which-groups-register
	 */
	public function cohortsFor(IUser $user): array {
		if ($this->isSchoolWide(user: $user) === true) {
			return $this->reader->currentCohorts();
		}

		$uid = $user->getUID();
		if ($this->groupManager->isInGroup($uid, self::TEACHER_GROUP) === false) {
			throw new RollCallException($this->l10n->t('Only teachers and coordinators can take the register.'), RollCallException::FORBIDDEN);
		}

		return array_values(
			array_filter(
				$this->reader->currentCohorts(),
				fn (array $cohort): bool => in_array($uid, $this->reader->teachersOf(cohort: $cohort), true)
			)
		);
	}//end cohortsFor()

	/**
	 * The asked group among the caller's groups, or the first one.
	 *
	 * @param array<int, array<string, mixed>> $cohorts  The caller's groups.
	 * @param string|null                      $cohortId The asked group.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RollCallException 403 when the asked group is not one of them.
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-who-may-open-which-groups-register
	 */
	public function chosenCohort(array $cohorts, ?string $cohortId): array {
		foreach ($cohorts as $cohort) {
			if ($cohortId === null || $cohortId === '' || $this->idOf(row: $cohort) === $cohortId) {
				return $cohort;
			}
		}

		throw new RollCallException($this->l10n->t('You cannot take the register of this group.'), RollCallException::FORBIDDEN);
	}//end chosenCohort()

	/**
	 * Why the register of a day cannot be saved, or '' when it can.
	 *
	 * @param IUser                               $user    The caller.
	 * @param string                              $date    The day.
	 * @param string                              $today   Today.
	 * @param array<string, array<string, mixed>> $records The day's saved records.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-the-register-can-be-changed-the-same-day
	 */
	public function lockReason(IUser $user, string $date, string $today, array $records): string {
		if ($date > $today) {
			return self::LOCKED_FUTURE;
		}

		if ($date === $today || $this->isSchoolWide(user: $user) === true) {
			return '';
		}

		foreach ($records as $record) {
			if (($record['markedVia'] ?? 'teacher') !== 'self-check-in') {
				return self::LOCKED_PAST_SAVED;
			}
		}

		return '';
	}//end lockReason()

	/**
	 * The caller's time zone.
	 *
	 * @param IUser $user The caller.
	 *
	 * @return DateTimeZone
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-the-register-can-be-changed-the-same-day
	 */
	public function zone(IUser $user): DateTimeZone {
		return $this->timeZone->getTimeZone(false, $user->getUID());
	}//end zone()

	/**
	 * Today in a time zone, `Y-m-d`.
	 *
	 * @param DateTimeZone $zone The time zone.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-the-register-can-be-changed-the-same-day
	 */
	public function today(DateTimeZone $zone): string {
		return (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone($zone)->format('Y-m-d');
	}//end today()

	/**
	 * Whether the caller opens every group.
	 *
	 * @param IUser $user The caller.
	 *
	 * @return bool
	 */
	private function isSchoolWide(IUser $user): bool {
		$uid = $user->getUID();
		if ($this->groupManager->isAdmin($uid) === true) {
			return true;
		}

		foreach (self::SCHOOL_WIDE_GROUPS as $group) {
			if ($this->groupManager->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		return false;
	}//end isSchoolWide()
}//end class
