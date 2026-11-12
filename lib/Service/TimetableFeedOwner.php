<?php

/**
 * Learniq Timetable Feed Owner
 *
 * Whose calendar feed TimetableFeedEventBuilder is building: the owner's
 * user id, which decides whose substitutes may be named, and the strings in
 * the owner's language. Built and read only inside TimetableFeedEventBuilder,
 * for the length of one events() call.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-calendar-subscription-feed
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCP\IL10N;

/**
 * The owner of one feed and their language.
 *
 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-calendar-subscription-feed
 */
final class TimetableFeedOwner {

	/**
	 * The feed's owner.
	 *
	 * @var string
	 */
	public string $uid;

	/**
	 * Strings in the owner's language.
	 *
	 * @var IL10N
	 */
	public IL10N $l10n;
}//end class
