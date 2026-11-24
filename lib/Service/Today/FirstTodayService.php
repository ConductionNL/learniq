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
	 * What each rule counts for a caller.
	 *
	 * @var FirstTodayProbe
	 */
	private readonly FirstTodayProbe $probe;

	/**
	 * The role tiles on Today.
	 *
	 * @var TodayTiles
	 */
	private readonly TodayTiles $tiles;

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
		$this->rules = new FirstTodayRules();
		$this->probe = new FirstTodayProbe(reader: $reader);
		$this->tiles = new TodayTiles(reader: $reader, probe: $this->probe);
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
		$role = $this->roleOf(user: $user, view: $view);
		if ($role === null) {
			return ['role' => null, 'rule' => null, 'count' => 0];
		}

		$context = $this->reader->contextFor(user: $user);
		$first = $this->rules->first(
			role: $role,
			probe: fn (string $rule): ?array => $this->probe->facts(rule: $rule, context: $context)
		);

		return ($first ?? ['role' => $role, 'rule' => null, 'count' => 0]);
	}//end forUser()

	/**
	 * The user's role tiles on Today, or null when they could not be read.
	 *
	 * @param IUser  $user The caller.
	 * @param string $view The view asked for: '' or `mentor`.
	 *
	 * @return array<string, mixed>|null `role` (null when Today has no card for her) and `tiles`, each `{id, count, ...}`.
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-each-role-tile-counts-what-that-role-acts-on-and-opens-the-list-it-counts
	 */
	public function tilesFor(IUser $user, string $view=''): ?array {
		try {
			$role = $this->roleOf(user: $user, view: $view);
			if ($this->tiles->hasTiles(role: $role) === false) {
				return ['role' => $role, 'tiles' => []];
			}

			return ['role' => $role, 'tiles' => $this->tiles->forRole(role: (string)$role, context: $this->reader->contextFor(user: $user))];
		} catch (Throwable $exception) {
			$this->logger->error(
				'[FirstTodayService] The tiles on Today could not be read: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return null;
		}
	}//end tilesFor()

	/**
	 * The user's role on Today in the view she asked for.
	 *
	 * @param IUser  $user The caller.
	 * @param string $view The view asked for: '' or `mentor`.
	 *
	 * @return string|null A role of FirstTodayRules, or null when Today has none for her.
	 */
	private function roleOf(IUser $user, string $view): ?string {
		return $this->rules->inView(
			role: $this->rules->roleFor(primaryRole: $this->roles->resolvePrimaryRole(user: $user), segment: $this->segments->currentSegment()),
			view: $view
		);
	}//end roleOf()

}//end class
