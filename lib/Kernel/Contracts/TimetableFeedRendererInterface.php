<?php

/**
 * Contract of TimetableFeedRenderer
 *
 * The public surface of TimetableFeedRenderer that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

use OCP\IUser;

/**
 * Contract of TimetableFeedRenderer.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TimetableFeedRendererInterface {

	/**
	 * The owner's feed as iCalendar text.
	 *
	 * @param IUser                          $owner    The feed's owner.
	 * @param array<int,array<string,mixed>> $sessions The sessions as My timetable projects them.
	 * @param int                            $now      The stamp time (unix seconds).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-calendar-subscription-feed
	 */
	public function render(IUser $owner, array $sessions, int $now): string;
}//end interface
