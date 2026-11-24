<?php

/**
 * Learniq Today Counts Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The role tiles of the signed-in user on Today: her role (the same one the
 * First today card resolves), the tiles that role acts on, and each tile's
 * count. Every row is read with the caller's own rights; the register groups
 * come through RollCallAccess, so a count never covers a row she may not
 * read. A role with no tiles reads nothing.
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

use OCA\Learniq\Kernel\Contracts\DashboardRoleServiceInterface;
use OCA\Learniq\Kernel\Contracts\SegmentServiceInterface;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers the role tiles on Today for a user.
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-each-role-tile-counts-what-that-role-acts-on-and-opens-the-list-it-counts
 */
class TodayCountsService {

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
	 * The ordered rules per role, for the role on Today.
	 *
	 * @var FirstTodayRules
	 */
	private readonly FirstTodayRules $rules;

	/**
	 * The tile counts.
	 *
	 * @var TodayCountFacts
	 */
	private readonly TodayCountFacts $facts;

	/**
	 * Today's groups already read in this request, by kind.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $groupsRead = [];

	/**
	 * Constructor.
	 *
	 * @param DashboardRoleServiceInterface $roles    The user's primary role.
	 * @param SegmentServiceInterface       $segments The school kind.
	 * @param FirstTodayReader              $reader   The reads behind each count, and the clock.
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
		$this->rules = new FirstTodayRules();
		$this->facts = new TodayCountFacts();
	}//end __construct()

	/**
	 * The user's tiles, or null when they could not be read.
	 *
	 * @param IUser  $user The caller.
	 * @param string $view The view asked for: '' or `mentor`.
	 *
	 * @return array<string, mixed>|null `role` (null when Today has no tiles for her) and `tiles`, a list of `{id, count, ...}`.
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-each-role-tile-counts-what-that-role-acts-on-and-opens-the-list-it-counts
	 */
	public function tilesFor(IUser $user, string $view=''): ?array {
		try {
			return $this->forUser(user: $user, view: $view);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[TodayCountsService] The tiles on Today could not be read: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return null;
		}
	}//end tilesFor()

	/**
	 * The user's tiles.
	 *
	 * @param IUser  $user The caller.
	 * @param string $view The view asked for: '' or `mentor`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-each-role-tile-counts-what-that-role-acts-on-and-opens-the-list-it-counts
	 */
	public function forUser(IUser $user, string $view=''): array {
		$role = $this->rules->inView(
			role: $this->rules->roleFor(primaryRole: $this->roles->resolvePrimaryRole(user: $user), segment: $this->segments->currentSegment()),
			view: $view
		);
		$tiles = self::TILES[$role ?? ''] ?? [];
		if ($tiles === []) {
			return ['role' => $role, 'tiles' => []];
		}

		$context = $this->reader->contextFor(user: $user);
		$answer = [];
		foreach ($tiles as $tile) {
			$answer[] = array_merge(['id' => $tile], $this->count(tile: $tile, context: $context));
		}

		return ['role' => $role, 'tiles' => $answer];
	}//end forUser()

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
			self::REGISTERS_NOT_FILLED => $this->facts->registersNotFilled(sessions: $this->ownSessions(context: $context), now: $context->now),
			self::PARENT_REPORTS_WAITING => $this->facts->parentReportsWaiting(groups: $this->groups(context: $context, own: true)),
			self::SUBMISSIONS_TO_MARK => $this->facts->submissionsToMark(
				assignments: $this->reader->rows(schema: 'assignment', filters: ['lifecycle' => 'published']),
				submissions: array_merge(
					$this->reader->rows(schema: 'submission', filters: ['lifecycle' => 'submitted']),
					$this->reader->rows(schema: 'submission', filters: ['lifecycle' => 'late'])
				),
				cohortIds: $this->ownCohortIds(context: $context)
			),
			self::CONFERENCE_TIMES => $this->facts->conferenceTimes(
				slots: $this->reader->rows(schema: 'conference-slot', filters: ['teacherId' => $uid]),
				uid: $uid
			),
			self::PLANS_ENDING => $this->plansEnding(context: $context),
			self::REGISTERS_FILLED_PER_GROUP => $this->facts->registersFilledPerGroup(groups: $this->groups(context: $context, own: false)),
			self::INSUFFICIENT_PER_YEAR => $this->facts->insufficientPerYear(
				finalGrades: $this->reader->rows(schema: 'final-grade', filters: ['passed' => false]),
				cohorts: array_map(static fn (array $group): array => (array)$group['cohort'], $this->groups(context: $context, own: false))
			),
			self::LESSONS_CANCELLED_TODAY => $this->facts->lessonsCancelledToday(groups: $this->groups(context: $context, own: false)),
			self::LEAVE_TO_DECIDE => ['count' => count($this->reader->rows(schema: 'leave-request', filters: ['lifecycle' => 'submitted']))],
			default => ['count' => 0],
		};
	}//end count()

	/**
	 * Active plans that end within seven days, the count the First today rule of the intern begeleider uses.
	 *
	 * @param TodayContext $context The caller and now.
	 *
	 * @return array<string, int> count.
	 *
	 * @spec openspec/changes/today-first-per-school-role/tasks.md#T2
	 */
	private function plansEnding(TodayContext $context): array {
		$ending = (new FollowUpFacts())->planEnding(
			plans: [
				'group-plan' => $this->reader->rows(schema: 'group-plan', filters: ['lifecycle' => 'active']),
				'learning-plan' => $this->reader->rows(schema: 'learning-plan', filters: ['lifecycle' => 'active']),
			],
			today: $context->today()
		);

		return ['count' => (int)$ending['count']];
	}//end plansEnding()

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
	 * The ids of the groups the caller teaches.
	 *
	 * @param TodayContext $context The caller and now.
	 *
	 * @return list<string>
	 */
	private function ownCohortIds(TodayContext $context): array {
		$ids = [];
		foreach ($this->groups(context: $context, own: true) as $group) {
			$cohort = (array)$group['cohort'];
			$ids[] = (string)($cohort['id'] ?? ($cohort['uuid'] ?? ''));
		}

		return array_values(array_filter($ids, static fn (string $id): bool => $id !== ''));
	}//end ownCohortIds()

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
}//end class
