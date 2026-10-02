<?php

/**
 * Learniq Timetable Projector
 *
 * The window/projection half of the personal timetable, split out of
 * {@see \OCA\Learniq\Controller\TimetableController} so the controller owns
 * only authentication, cohort resolution and the OpenRegister reads while this
 * collaborator owns the two things the caller actually sees:
 *
 *  1. the resolved window — `from`/`to` when supplied and parseable, else the
 *     current ISO week (Monday 00:00 → +7 days, UTC);
 *  2. the projected Session shape — the caller-facing field set, the resolved
 *     Room detail, the substitution fields, the `startsAt`-ordered list of
 *     sessions overlapping the window, and the `changedAt`-ordered list of
 *     today's changes (the "dagrooster" surface).
 *
 * This class performs NO I/O: it never reads or writes an OpenRegister object
 * and never touches the session, so the projection rules are testable without
 * a register. It is a read-shaping service only — nothing here mutates a
 * Session.
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
 * @spec openspec/specs/personal-timetable/spec.md#requirement-the-timetable-is-a-read-surface-only-over-existing-objects
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Window resolution and Session projection for the personal timetable.
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
 */
class TimetableProjector {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface  $logger     Application logger.
	 * @param LessonNoteReader $noteReader The notes on each lesson the caller may read (timetabling-lesson-note).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly LessonNoteReader $noteReader,
	) {
	}//end __construct()

	/**
	 * The caller's own lessons in the window, each with the notes the caller
	 * may read and whether the caller may add one (timetabling-lesson-note).
	 *
	 * @param array<int,array<string,mixed>>    $rawSessions     Raw session data arrays.
	 * @param string                            $windowFrom      Inclusive window start (ISO 8601).
	 * @param string                            $windowTo        Exclusive window end (ISO 8601).
	 * @param array<string,array<string,mixed>> $roomCache       Pre-loaded Room data keyed by UUID.
	 * @param string                            $uid             The caller.
	 * @param array<int,string>                 $taughtCohortIds Cohorts the caller teaches.
	 *
	 * @return array<int,array<string,mixed>> The ordered, projected sessions.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-learners-see-a-lessons-note-in-their-timetable
	 */
	public function personalSessions(
		array $rawSessions,
		string $windowFrom,
		string $windowTo,
		array $roomCache,
		string $uid,
		array $taughtCohortIds
	): array {
		$sessions = $this->windowedSessions(
			rawSessions: $rawSessions,
			windowFrom: $windowFrom,
			windowTo: $windowTo,
			roomCache: $roomCache,
			notes: $this->noteReader->forSessions(sessions: $rawSessions, uid: $uid, taughtCohortIds: $taughtCohortIds)
		);

		return $this->noteReader->markWritable(sessions: $sessions, uid: $uid, taughtCohortIds: $taughtCohortIds);
	}//end personalSessions()

	/**
	 * Resolve the requested window, defaulting to the current ISO week (UTC).
	 *
	 * @param string|null $from Requested inclusive window start.
	 * @param string|null $to Requested exclusive window end.
	 *
	 * @return array{0:string,1:string} The [from, to] ISO 8601 pair.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
	 */
	public function resolveWindow(?string $from, ?string $to): array {
		$tz = new DateTimeZone('UTC');

		$start = null;
		if ($from !== null && trim($from) !== '') {
			try {
				$start = new DateTimeImmutable($from, $tz);
			} catch (Throwable $e) {
				$this->logger->warning('[TimetableProjector] Ignoring unparseable "from"; using default window.', ['from' => $from]);
				$start = null;
			}
		}

		$end = null;
		if ($to !== null && trim($to) !== '') {
			try {
				$end = new DateTimeImmutable($to, $tz);
			} catch (Throwable $e) {
				$this->logger->warning('[TimetableProjector] Ignoring unparseable "to"; using default window.', ['to' => $to]);
				$end = null;
			}
		}

		if ($start === null) {
			// Monday 00:00:00 of the current week.
			$now = new DateTimeImmutable('now', $tz);
			$start = $now->modify('monday this week')->setTime(0, 0, 0);
		}

		if ($end === null) {
			// One week after the resolved start (exclusive end).
			$end = $start->modify('+7 days');
		}

		return [$start->format(DateTimeInterface::ATOM), $end->format(DateTimeInterface::ATOM)];
	}//end resolveWindow()

	/**
	 * Project the raw sessions overlapping the requested window, ordered
	 * globally by `startsAt`.
	 *
	 * @param array<int,array<string,mixed>> $rawSessions Raw session data arrays, all cohorts.
	 * @param string $windowFrom Inclusive window start (ISO 8601).
	 * @param string $windowTo Exclusive window end (ISO 8601).
	 * @param array<string,array<string,mixed>> $roomCache Pre-loaded Room data keyed by UUID.
	 * @param array<string,array<int,array<string,mixed>>> $notes Visible lesson notes keyed by session id (timetabling-lesson-note).
	 *
	 * @return array<int,array<string,mixed>> The ordered, projected sessions.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-learners-see-a-lessons-note-in-their-timetable
	 */
	public function windowedSessions(array $rawSessions, string $windowFrom, string $windowTo, array $roomCache, array $notes = []): array {
		$fromTs = strtotime($windowFrom);
		$toTs = strtotime($windowTo);

		$sessions = [];
		foreach ($rawSessions as $session) {
			if ($this->overlapsWindow(session: $session, fromTs: $fromTs, toTs: $toTs) === false) {
				continue;
			}

			$projected = $this->projectSession(session: $session, roomCache: $roomCache);
			$projected['notes'] = ($notes[$projected['id']] ?? []);
			$sessions[] = $projected;
		}

		usort(
			$sessions,
			static function (array $a, array $b): int {
				return strcmp((string)$a['startsAt'], (string)$b['startsAt']);
			}
		);

		return $sessions;
	}//end windowedSessions()

	/**
	 * Project the raw sessions whose `cancel`/`substitute-teacher` transition
	 * (`changedAt`, stamped server-side by SessionChangeNoticeHandler)
	 * occurred today (UTC calendar date) — the dagrooster surface —
	 * regardless of whether the Session's own `startsAt` falls inside the
	 * requested window.
	 *
	 * @param array<int,array<string,mixed>> $rawSessions Raw session data arrays, all cohorts.
	 * @param array<string,array<string,mixed>> $roomCache Pre-loaded Room data keyed by UUID.
	 *
	 * @return array<int,array<string,mixed>> The projected same-day changes, ordered by changedAt.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#scenario-today-s-cancellation-surfaces-in-the-dagrooster-changes-list-even-for-a-future-session
	 */
	public function todaysChanges(array $rawSessions, array $roomCache): array {
		$today = gmdate('Y-m-d');

		$changes = [];
		foreach ($rawSessions as $session) {
			$changedAt = (string)($session['changedAt'] ?? '');
			if ($changedAt === '') {
				continue;
			}

			$stamp = strtotime($changedAt);
			if ($stamp === false || gmdate('Y-m-d', $stamp) !== $today) {
				continue;
			}

			$changes[] = $this->projectSession(session: $session, roomCache: $roomCache);
		}

		usort(
			$changes,
			static function (array $a, array $b): int {
				return strcmp((string)$a['changedAt'], (string)$b['changedAt']);
			}
		);

		return $changes;
	}//end todaysChanges()

	/**
	 * Project one raw Session row to the caller-facing shape, including
	 * resolved Room detail (when `roomId` is set) and substitution fields.
	 *
	 * @param array<string,mixed> $session Raw session data.
	 * @param array<string,array<string,mixed>> $roomCache Pre-loaded Room data keyed by UUID.
	 *
	 * @return array<string,mixed> The projected session.
	 */
	private function projectSession(array $session, array $roomCache): array {
		$roomId = (string)($session['roomId'] ?? '');
		$room = null;
		$roomIdOrNull = null;
		if ($roomId !== '') {
			$roomIdOrNull = $roomId;
		}

		if ($roomId !== '' && isset($roomCache[$roomId]) === true) {
			$roomData = $roomCache[$roomId];
			$room = [
				'id' => (string)($roomData['id'] ?? ($roomData['uuid'] ?? $roomId)),
				'name' => (string)($roomData['name'] ?? ''),
				'capacity' => $roomData['capacity'] ?? null,
				'facilities' => $roomData['facilities'] ?? [],
			];
		}

		return [
			'id' => (string)($session['id'] ?? ($session['uuid'] ?? '')),
			'title' => (string)($session['title'] ?? ''),
			'startsAt' => (string)($session['startsAt'] ?? ''),
			'endsAt' => (string)($session['endsAt'] ?? ''),
			'location' => (string)($session['location'] ?? ''),
			'cohortId' => (string)($session['cohortId'] ?? ''),
			'courseId' => (string)($session['courseId'] ?? ''),
			'lessonId' => (string)($session['lessonId'] ?? ''),
			'lifecycle' => (string)($session['lifecycle'] ?? ''),
			'roomId' => $roomIdOrNull,
			'room' => $room,
			'substituteTeacherId' => $session['substituteTeacherId'] ?? null,
			// A planninq lesson's teacher, and its key for a lesson note
			// (timetabling-lesson-note); empty on a learniq Session.
			'teacherUserId' => (string)($session['teacherUserId'] ?? ''),
			'externalRef' => (string)($session['externalRef'] ?? ''),
			'sourceSystem' => (string)($session['sourceSystem'] ?? ''),
			'cover' => (($session['cover'] ?? false) === true),
			'changeReasonKind' => $session['changeReasonKind'] ?? null,
			'changeReason' => $session['changeReason'] ?? null,
			'changedAt' => $session['changedAt'] ?? null,
			// Where the lesson lives: `learniq` (a Session) or `planninq` (the
			// school timetable, sessions-from-planninq). A page opens only a
			// learniq Session as a learniq Session.
			'source' => (string)($session['source'] ?? 'learniq'),
			// Online lesson link (timetabling-online-lesson-link): only https reaches a page.
			'onlineMeetingUrl' => $this->httpsOrNull(value: $session['onlineMeetingUrl'] ?? null),
		];
	}//end projectSession()

	/**
	 * An https link, or null for anything else (older data, another source).
	 *
	 * @param mixed $value The stored link.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/timetabling-online-lesson-link/specs/timetable-online-lesson-link/spec.md#requirement-online-meeting-link-on-a-lesson
	 */
	public function httpsOrNull(mixed $value): ?string {
		if (is_string($value) === false || preg_match('#^https://[^\s/?\#]+\S*$#', $value) !== 1) {
			return null;
		}

		return $value;
	}//end httpsOrNull()

	/**
	 * Decide whether a session overlaps the requested window.
	 *
	 * A session overlaps when it starts before the window end AND ends after
	 * the window start. When `endsAt` is absent, the session is treated as a
	 * point in time and included if its `startsAt` falls within the window.
	 *
	 * @param array<string,mixed> $session The session data.
	 * @param int|false $fromTs Window start as a unix timestamp.
	 * @param int|false $toTs Window end as a unix timestamp.
	 *
	 * @return bool True when the session overlaps the window.
	 */
	private function overlapsWindow(array $session, int|false $fromTs, int|false $toTs): bool {
		if ($fromTs === false || $toTs === false) {
			// Unparseable window — do not silently drop everything.
			return true;
		}

		$startsAt = (string)($session['startsAt'] ?? '');
		if ($startsAt === '') {
			return false;
		}

		$startTs = strtotime($startsAt);
		if ($startTs === false) {
			return false;
		}

		$endsAtRaw = (string)($session['endsAt'] ?? '');
		$endTs = $startTs;
		if ($endsAtRaw !== '') {
			$endTs = strtotime($endsAtRaw);
		}

		if ($endTs === false) {
			$endTs = $startTs;
		}

		// Overlap: starts before window end AND ends after window start.
		return ($startTs < $toTs && $endTs >= $fromTs);
	}//end overlapsWindow()
}//end class
