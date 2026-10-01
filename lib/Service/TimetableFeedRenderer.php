<?php

/**
 * Learniq Timetable Feed Renderer
 *
 * Renders a user's projected sessions as their iCalendar feed, in the owner's
 * language: the event builder decides what each event says, the writer turns
 * events into RFC 5545 text (attendance-timetable-calendar-feed D4, D5).
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

use OCA\Learniq\AppInfo\Application;
use OCP\IUser;
use OCP\L10N\IFactory;

/**
 * Sessions to an iCalendar feed for one owner.
 */
class TimetableFeedRenderer {
	/**
	 * Constructor.
	 *
	 * @param TimetableFeedEventBuilder $events      Lessons to calendar events.
	 * @param TimetableIcsWriter        $writer      Events to iCalendar.
	 * @param IFactory                  $l10nFactory Strings in the owner's language.
	 */
	public function __construct(
		private readonly TimetableFeedEventBuilder $events,
		private readonly TimetableIcsWriter $writer,
		private readonly IFactory $l10nFactory,
	) {
	}//end __construct()

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
	public function render(IUser $owner, array $sessions, int $now): string {
		$l10n = $this->l10nFactory->get(Application::APP_ID, $this->l10nFactory->getUserLanguage($owner));

		return $this->writer->write(
			name: $l10n->t('My timetable'),
			events: $this->events->events(uid: $owner->getUID(), sessions: $sessions, l10n: $l10n),
			now: $now
		);
	}//end render()
}//end class
