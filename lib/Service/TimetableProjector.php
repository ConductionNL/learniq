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
	 * The lesson fields a projected session copies as strings, in order.
	 */
	private const LESSON_FIELDS = ['title', 'startsAt', 'endsAt', 'location', 'cohortId', 'courseId', 'lessonId', 'lifecycle'];
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
	 * @param array<string,mixed> $lessons         `sessions` (raw lessons), `window` (inclusive start and exclusive
	 *                                             end, ISO 8601) and `roomCache` (pre-loaded rooms keyed by UUID).
	 * @param string              $uid             The caller.
	 * @param array<int,string>   $taughtCohortIds Cohorts the caller teaches.
	 *
	 * @return array<int,array<string,mixed>> The ordered, projected sessions.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-learners-see-a-lessons-note-in-their-timetable
	 */
	public function personalSessions(array $lessons, string $uid, array $taughtCohortIds): array {
		$sessions = $this->windowedSessions(
			rawSessions: $lessons['sessions'],
			window: $lessons['window'],
			roomCache: $lessons['roomCache'],
			notes: $this->noteReader->forSessions(sessions: $lessons['sessions'], uid: $uid, taughtCohortIds: $taughtCohortIds)
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
		$start = $this->instant(value: (string)$from, name: 'from');
		$end = $this->instant(value: (string)$to, name: 'to');

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
	 * A window edge as a UTC instant, or null when it is blank or unparseable
	 * (logged), so the default applies.
	 *
	 * @param string $value The requested edge.
	 * @param string $name  `from` or `to`.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function instant(string $value, string $name): ?DateTimeImmutable {
		if (trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value, new DateTimeZone('UTC'));
		} catch (Throwable $e) {
			$this->logger->warning('[TimetableProjector] Ignoring unparseable "' . $name . '"; using default window.', [$name => $value]);
			return null;
		}
	}//end instant()

	/**
	 * Project the raw sessions overlapping the requested window, ordered
	 * globally by `startsAt`.
	 *
	 * @param array<int,array<string,mixed>> $rawSessions Raw session data arrays, all cohorts.
	 * @param array{0: string, 1: string} $window Inclusive window start and exclusive window end (ISO 8601).
	 * @param array<string,array<string,mixed>> $roomCache Pre-loaded Room data keyed by UUID.
	 * @param array<string,array<int,array<string,mixed>>> $notes Visible lesson notes keyed by session id (timetabling-lesson-note).
	 *
	 * @return array<int,array<string,mixed>> The ordered, projected sessions.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-learners-see-a-lessons-note-in-their-timetable
	 */
	public function windowedSessions(array $rawSessions, array $window, array $roomCache, array $notes = []): array {
		$fromTs = strtotime($window[0]);
		$toTs = strtotime($window[1]);

		$sessions = [];
		foreach ($rawSessions as $session) {
			if ($this->overlapsWindow(session: $session, window: [$fromTs, $toTs]) === false) {
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
		$roomIdOrNull = null;
		if ($roomId !== '') {
			$roomIdOrNull = $roomId;
		}

		return ['id' => (string)($session['id'] ?? ($session['uuid'] ?? ''))]
			+ self::strings(session: $session, fields: self::LESSON_FIELDS)
			+ ['roomId' => $roomIdOrNull, 'room' => self::roomOf(roomCache: $roomCache, roomId: $roomId)]
			+ self::changeFields(session: $session)
			// Online lesson link (timetabling-online-lesson-link): only https reaches a page.
			+ ['onlineMeetingUrl' => $this->httpsOrNull(value: $session['onlineMeetingUrl'] ?? null)];
	}//end projectSession()

	/**
	 * The session's values for the fields, each as a string ('' when missing).
	 *
	 * @param array<string,mixed> $session The session.
	 * @param list<string>        $fields  The fields.
	 *
	 * @return array<string,string>
	 */
	private static function strings(array $session, array $fields): array {
		$values = [];
		foreach ($fields as $field) {
			$values[$field] = (string)($session[$field] ?? '');
		}

		return $values;
	}//end strings()

	/**
	 * The room of the session from the scan's room cache, or null when it has none or the room is unknown.
	 *
	 * @param array<string,array<string,mixed>> $roomCache Rooms by id.
	 * @param string                            $roomId    The session's room id ('' for none).
	 *
	 * @return array{id: string, name: string, capacity: mixed, facilities: mixed}|null
	 */
	private static function roomOf(array $roomCache, string $roomId): ?array {
		if ($roomId === '' || isset($roomCache[$roomId]) === false) {
			return null;
		}

		$roomData = $roomCache[$roomId];
		return [
			'id' => (string)($roomData['id'] ?? ($roomData['uuid'] ?? $roomId)),
			'name' => (string)($roomData['name'] ?? ''),
			'capacity' => $roomData['capacity'] ?? null,
			'facilities' => $roomData['facilities'] ?? [],
		];
	}//end roomOf()

	/**
	 * The session's cover, change and source fields.
	 *
	 * @param array<string,mixed> $session The session.
	 *
	 * @return array<string,mixed>
	 */
	private static function changeFields(array $session): array {
		return [
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
		];
	}//end changeFields()

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
	 * @param array<string,mixed>               $session The session data.
	 * @param array{0: int|false, 1: int|false} $window  Window start and end as unix timestamps.
	 *
	 * @return bool True when the session overlaps the window.
	 */
	private function overlapsWindow(array $session, array $window): bool {
		[$fromTs, $toTs] = $window;
		if ($fromTs === false || $toTs === false) {
			// Unparseable window — do not silently drop everything.
			return true;
		}

		$startTs = self::timeOf(session: $session, field: 'startsAt');
		if ($startTs === false) {
			return false;
		}

		// Overlap: starts before window end AND ends after window start.
		return ($startTs < $toTs && self::endOf(session: $session, startTs: $startTs) >= $fromTs);
	}//end overlapsWindow()

	/**
	 * When a session ends: its `endsAt`, or its start when that is missing or unparseable.
	 *
	 * @param array<string, mixed> $session The raw session.
	 * @param int                  $startTs Its start.
	 *
	 * @return int
	 */
	private static function endOf(array $session, int $startTs): int {
		$endTs = self::timeOf(session: $session, field: 'endsAt');
		if ($endTs === false) {
			return $startTs;
		}

		return $endTs;
	}//end endOf()

	/**
	 * A session time field as a unix timestamp, or false when it is missing, empty or unparseable.
	 *
	 * @param array<string, mixed> $session The raw session.
	 * @param string               $field   `startsAt` or `endsAt`.
	 *
	 * @return int|false
	 */
	private static function timeOf(array $session, string $field): int|false {
		return strtotime((string)($session[$field] ?? ''));
	}//end timeOf()
}//end class
