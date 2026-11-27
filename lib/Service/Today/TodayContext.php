<?php

/**
 * Learniq Today Context
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Who asks for Today and when: the signed-in user and the moment, in her
 * time zone. The First today rules read their user id, today's date and the
 * time zone from it.
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
 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/tasks.md#T1
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Today;

use DateTimeImmutable;
use DateTimeZone;
use OCP\IUser;

/**
 * The user and the moment Today is asked for.
 *
 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/tasks.md#T1
 */
final class TodayContext {

	/**
	 * Constructor.
	 *
	 * @param IUser             $user The signed-in user.
	 * @param DateTimeImmutable $now  Now, in her time zone.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly IUser $user,
		public readonly DateTimeImmutable $now,
	) {
	}//end __construct()

	/**
	 * The user's id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/tasks.md#T1
	 */
	public function uid(): string {
		return $this->user->getUID();
	}//end uid()

	/**
	 * Today, `Y-m-d`, in the user's time zone.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/tasks.md#T1
	 */
	public function today(): string {
		return $this->now->format('Y-m-d');
	}//end today()

	/**
	 * The user's time zone.
	 *
	 * @return DateTimeZone
	 *
	 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/tasks.md#T1
	 */
	public function zone(): DateTimeZone {
		return $this->now->getTimezone();
	}//end zone()
}//end class
