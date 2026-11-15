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
		$owner = new TimetableFeedOwner();
		$owner->uid = $uid;
		$owner->l10n = $l10n;

		$events = [];
		foreach ($sessions as $session) {
			if ((string)($session['id'] ?? '') === '') {
				continue;
			}

			$events[] = $this->event(session: $session, owner: $owner);
		}

		return $events;
	}//end events()

	/**
	 * The feed event of one session that has an id.
	 *
	 * @param array<string,mixed> $session The projected session.
	 * @param TimetableFeedOwner  $owner   The feed's owner and their language.
	 *
	 * @return array<string,mixed>
	 */
	private function event(array $session, TimetableFeedOwner $owner): array {
		$cancelled = $this->isCancelled(session: $session);
		$summary = $this->summary(session: $session, owner: $owner);

		return [
			'uid' => (string)$session['id'] . '@learniq',
			'start' => (string)($session['startsAt'] ?? ''),
			'end' => (string)($session['endsAt'] ?? ''),
			'summary' => $summary,
			'location' => $this->location(session: $session),
			'description' => implode("\n", $this->notes(session: $session, owner: $owner)),
			'cancelled' => $cancelled,
			// The online lesson link; the projection already dropped a non-https value.
			'url' => (string)($session['onlineMeetingUrl'] ?? ''),
		];
	}//end event()

	/**
	 * The event's title: the lesson title, a placeholder without one, marked when cancelled.
	 *
	 * @param array<string,mixed> $session The projected session.
	 * @param TimetableFeedOwner  $owner   The feed's owner and their language.
	 *
	 * @return string
	 */
	private function summary(array $session, TimetableFeedOwner $owner): string {
		$summary = trim((string)($session['title'] ?? ''));
		if ($summary === '') {
			$summary = $owner->l10n->t('Untitled lesson');
		}

		if ($this->isCancelled(session: $session) === true) {
			$summary = $owner->l10n->t('Cancelled: %s', [$summary]);
		}

		return $summary;
	}//end summary()

	/**
	 * Whether the lesson is cancelled.
	 *
	 * @param array<string,mixed> $session The projected session.
	 *
	 * @return bool
	 */
	private function isCancelled(array $session): bool {
		return (($session['lifecycle'] ?? '') === 'cancelled');
	}//end isCancelled()

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
	 * @param array<string,mixed> $session The projected session.
	 * @param TimetableFeedOwner  $owner   The feed's owner and their language.
	 *
	 * @return array<int,string>
	 */
	private function notes(array $session, TimetableFeedOwner $owner): array {
		$cancelled = $this->isCancelled(session: $session);
		$lines = [];
		if ($cancelled === true) {
			$lines[] = $owner->l10n->t('This lesson is cancelled.');
		}

		$substitute = (string)($session['substituteTeacherId'] ?? '');
		if ($substitute !== '' && $cancelled === false) {
			$lines[] = $this->substituteLine(substitute: $substitute, owner: $owner);
		}

		return array_merge($lines, $this->coverLines(session: $session, owner: $owner), $this->reasonLines(session: $session, owner: $owner));
	}//end notes()

	/**
	 * The cover line of a lesson the owner covers, unless the substitute line already says it.
	 *
	 * @param array<string,mixed> $session The projected session.
	 * @param TimetableFeedOwner  $owner   The feed's owner and their language.
	 *
	 * @return array<int,string>
	 */
	private function coverLines(array $session, TimetableFeedOwner $owner): array {
		// The substitute line already says it when the reader is the substitute.
		if (($session['cover'] ?? false) === true && (string)($session['substituteTeacherId'] ?? '') !== $owner->uid) {
			return [$owner->l10n->t('You cover this lesson.')];
		}

		return [];
	}//end coverLines()

	/**
	 * The reason line of a cancelled lesson or one with a substitute, when its kind has a label.
	 *
	 * @param array<string,mixed> $session The projected session.
	 * @param TimetableFeedOwner  $owner   The feed's owner and their language.
	 *
	 * @return array<int,string>
	 */
	private function reasonLines(array $session, TimetableFeedOwner $owner): array {
		$reason = $this->reason(kind: (string)($session['changeReasonKind'] ?? ''), l10n: $owner->l10n);
		if ($reason !== '' && ($this->isCancelled(session: $session) === true || (string)($session['substituteTeacherId'] ?? '') !== '')) {
			return [$owner->l10n->t('Reason: %s', [$reason])];
		}

		return [];
	}//end reasonLines()

	/**
	 * The substitute line, with a name only when the policy allows it.
	 *
	 * @param string             $substitute The substitute's user id.
	 * @param TimetableFeedOwner $owner      The feed's owner and their language.
	 *
	 * @return string
	 */
	private function substituteLine(string $substitute, TimetableFeedOwner $owner): string {
		if ($substitute === $owner->uid) {
			return $owner->l10n->t('You cover this lesson.');
		}

		if ($this->visibility->mayOpen(uid: $owner->uid, kind: 'teacher', id: $substitute) === true) {
			$name = $this->userManager->getDisplayName($substitute);
			if ($name === null || trim($name) === '') {
				$name = $substitute;
			}

			return $owner->l10n->t('Substitute teacher: %s', [$name]);
		}

		return $owner->l10n->t('A substitute teacher covers this lesson.');
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
