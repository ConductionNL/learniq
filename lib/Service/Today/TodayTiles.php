<?php

/**
 * Learniq Today Tiles
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The role tiles on Today: the tiles a role acts on, in the order the
 * boards draw them, and each tile's count. The groups and lessons come from
 * the First today probe, so a request that asks the card and the tiles
 * reads today's groups once. A role with no tiles reads nothing.
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
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-each-role-tile-counts-what-that-role-acts-on-and-opens-the-list-it-counts
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Today;

/**
 * Counts the role tiles on Today.
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-each-role-tile-counts-what-that-role-acts-on-and-opens-the-list-it-counts
 */
final class TodayTiles {

	public const REGISTERS_NOT_FILLED = 'registers-not-filled';
	public const PARENT_REPORTS_WAITING = 'parent-reports-waiting';
	public const SUBMISSIONS_TO_MARK = 'submissions-to-mark';
	public const CONFERENCE_TIMES = 'conference-times';
	public const PLANS_ENDING = 'plans-ending';
	public const REGISTERS_FILLED_PER_GROUP = 'registers-filled-per-group';
	public const INSUFFICIENT_PER_YEAR = 'insufficient-per-year';
	public const LESSONS_CANCELLED_TODAY = 'lessons-cancelled-today';
	public const LEAVE_TO_DECIDE = 'leave-to-decide';

	/**
	 * The tiles per role on Today, in the order the boards draw them.
	 *
	 * @var array<string, list<string>>
	 */
	public const TILES = [
		FirstTodayRules::ROLE_GROUP_TEACHER => [self::REGISTERS_NOT_FILLED, self::PARENT_REPORTS_WAITING, self::CONFERENCE_TIMES],
		FirstTodayRules::ROLE_SUBJECT_TEACHER => [self::REGISTERS_NOT_FILLED, self::SUBMISSIONS_TO_MARK, self::PARENT_REPORTS_WAITING],
		FirstTodayRules::ROLE_MENTOR => [self::CONFERENCE_TIMES, self::PARENT_REPORTS_WAITING],
		FirstTodayRules::ROLE_CARE_COORDINATOR => [self::PLANS_ENDING],
		FirstTodayRules::ROLE_HEAD => [
			self::REGISTERS_FILLED_PER_GROUP,
			self::INSUFFICIENT_PER_YEAR,
			self::LESSONS_CANCELLED_TODAY,
			self::LEAVE_TO_DECIDE,
		],
	];

	/**
	 * The tile counts.
	 *
	 * @var TodayCountFacts
	 */
	private readonly TodayCountFacts $facts;

	/**
	 * Constructor.
	 *
	 * @param FirstTodayReader $reader The rows behind each count, read with the caller's rights.
	 * @param FirstTodayProbe  $probe  Today's groups and lessons, read once per request.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly FirstTodayReader $reader,
		private readonly FirstTodayProbe $probe,
	) {
		$this->facts = new TodayCountFacts();
	}//end __construct()

	/**
	 * Whether Today has tiles for a role.
	 *
	 * @param string|null $role A role of FirstTodayRules, or null.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/today-first-per-school-role/tasks.md#T2
	 */
	public function hasTiles(?string $role): bool {
		return (self::TILES[$role ?? ''] ?? []) !== [];
	}//end hasTiles()

	/**
	 * The tiles of a role with their counts.
	 *
	 * @param string       $role    A role of FirstTodayRules.
	 * @param TodayContext $context The caller and now.
	 *
	 * @return list<array<string, mixed>> Each `{id, count, ...}`.
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-each-role-tile-counts-what-that-role-acts-on-and-opens-the-list-it-counts
	 */
	public function forRole(string $role, TodayContext $context): array {
		$tiles = [];
		foreach ((self::TILES[$role] ?? []) as $tile) {
			$tiles[] = array_merge(['id' => $tile], $this->count(tile: $tile, context: $context));
		}

		return $tiles;
	}//end forRole()

	/**
	 * One tile's count for the caller.
	 *
	 * @param string       $tile    A tile of TILES.
	 * @param TodayContext $context The caller and now.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/today-first-per-school-role/tasks.md#T2
	 */
	private function count(string $tile, TodayContext $context): array {
		$uid = $context->uid();

		return match ($tile) {
			self::REGISTERS_NOT_FILLED => $this->facts->registersNotFilled(sessions: $this->probe->ownSessions(context: $context), now: $context->now),
			self::PARENT_REPORTS_WAITING => $this->facts->parentReportsWaiting(groups: $this->probe->groups(context: $context, own: true)),
			self::SUBMISSIONS_TO_MARK => $this->submissionsToMark(context: $context),
			self::CONFERENCE_TIMES => $this->facts->conferenceTimes(
				slots: $this->reader->rows(schema: 'conference-slot', filters: ['teacherId' => $uid]),
				uid: $uid
			),
			self::PLANS_ENDING => ['count' => (int)($this->probe->facts(rule: FirstTodayRules::PLAN_ENDING, context: $context)['count'] ?? 0)],
			self::REGISTERS_FILLED_PER_GROUP => $this->facts->registersFilledPerGroup(groups: $this->probe->groups(context: $context, own: false)),
			self::INSUFFICIENT_PER_YEAR => $this->facts->insufficientPerYear(
				finalGrades: $this->reader->rows(schema: 'final-grade', filters: ['passed' => false]),
				cohorts: array_map(static fn (array $group): array => (array)$group['cohort'], $this->probe->groups(context: $context, own: false))
			),
			self::LESSONS_CANCELLED_TODAY => $this->facts->lessonsCancelledToday(groups: $this->probe->groups(context: $context, own: false)),
			self::LEAVE_TO_DECIDE => ['count' => count($this->reader->rows(schema: 'leave-request', filters: ['lifecycle' => 'submitted']))],
			default => ['count' => 0],
		};
	}//end count()

	/**
	 * Hand-ins without a grade on the published assignments of the caller's groups.
	 *
	 * @param TodayContext $context The caller and now.
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/changes/today-first-per-school-role/tasks.md#T2
	 */
	private function submissionsToMark(TodayContext $context): array {
		$cohortIds = [];
		foreach ($this->probe->groups(context: $context, own: true) as $group) {
			$cohort = (array)$group['cohort'];
			$cohortIds[] = (string)($cohort['id'] ?? ($cohort['uuid'] ?? ''));
		}

		return $this->facts->submissionsToMark(
			assignments: $this->reader->rows(schema: 'assignment', filters: ['lifecycle' => 'published']),
			submissions: array_merge(
				$this->reader->rows(schema: 'submission', filters: ['lifecycle' => 'submitted']),
				$this->reader->rows(schema: 'submission', filters: ['lifecycle' => 'late'])
			),
			cohortIds: array_values(array_filter($cohortIds, static fn (string $id): bool => $id !== ''))
		);
	}//end submissionsToMark()
}//end class
