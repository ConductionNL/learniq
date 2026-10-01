<?php

/**
 * Learniq Timetable Feed Event Builder
 *
 * Turns the lessons My timetable shows a user into calendar events for their
 * feed: the lesson title, the room (or the location), a cancelled lesson as
 * cancelled, and a substitute teacher. A substitute is named only when the
 * school's timetable visibility policy lets this user open that teacher's
 * timetable; otherwise the event says a substitute covers the lesson, without
 * a name. Free-text change reasons are left out, because they can name a
 * person (attendance-timetable-calendar-feed D5).
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
use OCP\IUserManager;

/**
 * Maps projected sessions to feed events for one user.
 */
class TimetableFeedEventBuilder {
	/**
	 * Constructor.
	 *
	 * @param TimetableVisibilityService $visibility  Which teachers the user may see.
	 * @param IUserManager               $userManager Display names of teachers.
	 */
	public function __construct(
		private readonly TimetableVisibilityService $visibility,
		private readonly IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * The feed events for a user's projected sessions.
	 *
	 * @param string                         $uid      The feed's owner.
	 * @param array<int,array<string,mixed>> $sessions The sessions as My timetable projects them.
	 * @param IL10N                          $l10n     Strings in the owner's language.
	 *
	 * @return array<int,array<string,mixed>> Events for {@see TimetableIcsWriter::write()}.
	 *
	 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-calendar-subscription-feed
	 */
	public function events(string $uid, array $sessions, IL10N $l10n): array {
		$events = [];
		foreach ($sessions as $session) {
			$id = (string)($session['id'] ?? '');
			if ($id === '') {
				continue;
			}

			$cancelled = (($session['lifecycle'] ?? '') === 'cancelled');
			$summary = trim((string)($session['title'] ?? ''));
			if ($summary === '') {
				$summary = $l10n->t('Lesson');
			}

			if ($cancelled === true) {
				$summary = $l10n->t('Cancelled: %s', [$summary]);
			}

			$events[] = [
				'uid' => $id . '@learniq',
				'start' => (string)($session['startsAt'] ?? ''),
				'end' => (string)($session['endsAt'] ?? ''),
				'summary' => $summary,
				'location' => $this->location(session: $session),
				'description' => implode("\n", $this->notes(uid: $uid, session: $session, cancelled: $cancelled, l10n: $l10n)),
				'cancelled' => $cancelled,
			];
		}

		return $events;
	}//end events()

	/**
	 * The room's name, or the session's location text.
	 *
	 * @param array<string,mixed> $session The projected session.
	 *
	 * @return string
	 */
	private function location(array $session): string {
		$room = $session['room'] ?? null;
		if (is_array($room) === true && trim((string)($room['name'] ?? '')) !== '') {
			return trim((string)$room['name']);
		}

		return trim((string)($session['location'] ?? ''));
	}//end location()

	/**
	 * The description lines of one event.
	 *
	 * @param string              $uid       The feed's owner.
	 * @param array<string,mixed> $session   The projected session.
	 * @param bool                $cancelled Whether the lesson is cancelled.
	 * @param IL10N               $l10n      Strings in the owner's language.
	 *
	 * @return array<int,string>
	 */
	private function notes(string $uid, array $session, bool $cancelled, IL10N $l10n): array {
		$lines = [];
		if ($cancelled === true) {
			$lines[] = $l10n->t('This lesson is cancelled.');
		}

		$substitute = (string)($session['substituteTeacherId'] ?? '');
		if ($substitute !== '' && $cancelled === false) {
			$lines[] = $this->substituteLine(uid: $uid, substitute: $substitute, l10n: $l10n);
		}

		if (($session['cover'] ?? false) === true) {
			$lines[] = $l10n->t('You cover this lesson.');
		}

		$reason = $this->reason(kind: (string)($session['changeReasonKind'] ?? ''), l10n: $l10n);
		if ($reason !== '' && ($cancelled === true || $substitute !== '')) {
			$lines[] = $l10n->t('Reason: %s', [$reason]);
		}

		return $lines;
	}//end notes()

	/**
	 * The substitute line, with a name only when the policy allows it.
	 *
	 * @param string $uid        The feed's owner.
	 * @param string $substitute The substitute's user id.
	 * @param IL10N  $l10n       Strings in the owner's language.
	 *
	 * @return string
	 */
	private function substituteLine(string $uid, string $substitute, IL10N $l10n): string {
		if ($substitute === $uid) {
			return $l10n->t('You cover this lesson.');
		}

		if ($this->visibility->mayOpen(uid: $uid, kind: 'teacher', id: $substitute) === true) {
			$name = $this->userManager->getDisplayName($substitute);
			if ($name === null || trim($name) === '') {
				$name = $substitute;
			}

			return $l10n->t('Substitute teacher: %s', [$name]);
		}

		return $l10n->t('A substitute teacher covers this lesson.');
	}//end substituteLine()

	/**
	 * The label of a change reason kind, or '' for none or an unknown kind.
	 *
	 * @param string $kind The changeReasonKind value.
	 * @param IL10N  $l10n Strings in the owner's language.
	 *
	 * @return string
	 */
	private function reason(string $kind, IL10N $l10n): string {
		return match ($kind) {
			'teacher-absence' => $l10n->t('teacher absent'),
			'room-unavailable' => $l10n->t('room not available'),
			'timetable-change' => $l10n->t('timetable change'),
			'other' => $l10n->t('other'),
			default => '',
		};
	}//end reason()
}//end class
