<?php

/**
 * Learniq Check-in Service
 *
 * A learner checks in to a lesson with the code on the teacher's screen
 * (attendance-self-check-in). Every rule is checked here before anything is
 * written: the window exists, is open and has not passed its close time or
 * the lesson's end; the code is valid now; the learner is in the lesson's
 * group; and no attendance record exists yet for the learner and lesson. Only
 * then is one AttendanceRecord written, `present` or `late` after the grace
 * minutes, with `markedVia: self-check-in`.
 *
 * The write skips the object API's rights check on purpose: a learner may not
 * create attendance records, or they could mark a friend. For a portal
 * check-in it runs inside `ObjectService::runAs()` for the pupil, so the
 * audit trail names the pupil (the receiver pattern of learniq #1096). The
 * service never updates an existing record, so a teacher's mark always wins.
 *
 * The lesson is read from learniq's own Session, the table the attendance
 * register works on; with planninq as the timetable source the register and
 * this check-in follow the sessions learniq holds (D10, fallback path).
 *
 * @category Service
 * @package  OCA\Learniq\Service\CheckIn
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CheckIn;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use Throwable;

/**
 * Checks and writes one self check-in.
 *
 * @spec openspec/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
 */
class CheckInService {

	private const REGISTER = 'learniq';
	private const WINDOW_SCHEMA = 'check-in-window';
	private const RECORD_SCHEMA = 'attendance-record';

	/**
	 * Constructor.
	 *
	 * @param ObjectService      $objects OpenRegister object access.
	 * @param CheckInCodeService $codes   Makes and checks codes.
	 * @param ITimeFactory       $time    The clock.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly CheckInCodeService $codes,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Check a learner in.
	 *
	 * @param string      $windowId   The CheckInWindow uuid.
	 * @param string      $code       The code the learner sent.
	 * @param string      $userId     The learner's Nextcloud user id.
	 * @param string|null $learnerRef The learner's profile uuid, when known.
	 * @param IUser|null  $runAs      The pupil's account for a portal check-in, null in the app.
	 *
	 * @return PortalOutcome 200 `{status, sessionId}`, or 404 / 403 / 409 / 422 with a reason.
	 *
	 * @spec openspec/specs/attendance/spec.md#scenario-a-learner-scans-the-code-at-the-start-of-the-lesson
	 * @spec openspec/specs/attendance/spec.md#requirement-a-self-check-in-never-overwrites-a-mark
	 */
	public function checkIn(string $windowId, string $code, string $userId, ?string $learnerRef = null, ?IUser $runAs = null): PortalOutcome {
		$context = $this->context(windowId: $windowId, userId: $userId);
		if ($context instanceof PortalOutcome) {
			return $context;
		}

		[$window, $session] = $context;
		if ($this->isOpen(window: $window, session: $session) === false) {
			return new PortalOutcome(status: Http::STATUS_UNPROCESSABLE_ENTITY, body: ['error' => 'window_closed'], reason: 'window-closed');
		}

		if ($this->codes->verify(windowId: $windowId, mode: (string)($window['mode'] ?? 'rotating-qr'), code: $code) === false) {
			return new PortalOutcome(status: Http::STATUS_UNPROCESSABLE_ENTITY, body: ['error' => 'invalid_code'], reason: 'invalid-code');
		}

		if ($this->hasRecord(sessionId: (string)$session['id'], userId: $userId) === true) {
			return new PortalOutcome(status: Http::STATUS_CONFLICT, body: ['error' => 'already_recorded'], reason: 'already-recorded');
		}

		$status = $this->status(session: $session, window: $window);
		$record = [
			'sessionId' => (string)$session['id'],
			'learnerId' => $userId,
			'learnerRef' => $learnerRef,
			'cohortId' => (string)($session['cohortId'] ?? ''),
			'status' => $status,
			'markedVia' => 'self-check-in',
			'markedBy' => $userId,
			'markedAt' => $this->now()->format(DateTimeInterface::ATOM),
			'tenant_id' => (string)($window['tenant_id'] ?? ($session['tenant_id'] ?? '')),
		];

		$write = fn () => $this->objects->saveObject(object: $record, register: self::REGISTER, schema: self::RECORD_SCHEMA, _rbac: false);
		if ($runAs === null) {
			$write();
			return new PortalOutcome(status: Http::STATUS_OK, body: ['status' => $status, 'sessionId' => (string)$session['id']]);
		}

		$this->objects->runAs(user: $runAs, operation: $write);

		return new PortalOutcome(status: Http::STATUS_OK, body: ['status' => $status, 'sessionId' => (string)$session['id']]);
	}//end checkIn()

	/**
	 * Check a learner in with only the code on the board: the window is the
	 * open one of the learner's own lessons whose current code matches. A
	 * learner who types the code therefore needs no link, and the portal
	 * needs no window id.
	 *
	 * @param string      $code       The code the learner typed.
	 * @param string      $userId     The learner's Nextcloud user id.
	 * @param string|null $learnerRef The learner's profile uuid, when known.
	 * @param IUser|null  $runAs      The pupil's account for a portal check-in, null in the app.
	 *
	 * @return PortalOutcome As checkIn(); 422 `invalid_code` when no open window of the learner matches.
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
	 */
	public function checkInWithCode(string $code, string $userId, ?string $learnerRef = null, ?IUser $runAs = null): PortalOutcome {
		foreach ($this->openWindows() as $window) {
			$windowId = (string)$window['id'];
			if ($this->codes->verify(windowId: $windowId, mode: (string)($window['mode'] ?? 'rotating-qr'), code: $code) === true) {
				return $this->checkIn(windowId: $windowId, code: $code, userId: $userId, learnerRef: $learnerRef, runAs: $runAs);
			}
		}

		return new PortalOutcome(status: Http::STATUS_UNPROCESSABLE_ENTITY, body: ['error' => 'invalid_code'], reason: 'invalid-code');
	}//end checkInWithCode()

	/**
	 * The open check-ins of the learner's own lessons, for the learner's
	 * check-in page: window id, lesson title and times. Never the code.
	 *
	 * @param string $userId The learner's Nextcloud user id.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
	 */
	public function openFor(string $userId): array {
		$open = [];
		foreach ($this->openWindows() as $window) {
			$shown = $this->show(windowId: (string)$window['id'], userId: $userId);
			if ($shown->status === Http::STATUS_OK && $shown->body['open'] === true) {
				$open[] = ['windowId' => (string)$window['id'], 'mode' => (string)($window['mode'] ?? 'rotating-qr')] + $shown->body;
			}
		}

		return $open;
	}//end openFor()

	/**
	 * Windows in the `open` state, read as the system.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function openWindows(): array {
		$rows = $this->objects->findAll(
			config: [
				'filters' => ['register' => self::REGISTER, 'schema' => self::WINDOW_SCHEMA, 'lifecycle' => 'open'],
				'limit' => 500,
			],
			_rbac: false
		);

		$windows = [];
		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$row['id'] = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
				$windows[] = $row;
			}
		}

		return $windows;
	}//end openWindows()

	/**
	 * What the learner's check-in page shows: the lesson and whether the
	 * window is open. Only for a learner of the lesson's group.
	 *
	 * @param string $windowId The CheckInWindow uuid.
	 * @param string $userId   The learner's Nextcloud user id.
	 *
	 * @return PortalOutcome 200 `{title, startsAt, endsAt, open}`, or a refusal.
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
	 */
	public function show(string $windowId, string $userId): PortalOutcome {
		$context = $this->context(windowId: $windowId, userId: $userId);
		if ($context instanceof PortalOutcome) {
			return $context;
		}

		[$window, $session] = $context;

		return new PortalOutcome(
			status: Http::STATUS_OK,
			body: [
				'title' => (string)($session['title'] ?? ''),
				'startsAt' => $session['startsAt'] ?? null,
				'endsAt' => $session['endsAt'] ?? null,
				'open' => $this->isOpen(window: $window, session: $session),
			]
		);
	}//end show()

	/**
	 * The window and its session for a learner of the session's group, or the
	 * refusal. Whether the window is open is the caller's question.
	 *
	 * @param string $windowId The CheckInWindow uuid.
	 * @param string $userId   The learner.
	 *
	 * @return PortalOutcome|array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function context(string $windowId, string $userId): PortalOutcome|array {
		$notFound = new PortalOutcome(status: Http::STATUS_NOT_FOUND, body: ['error' => 'not_found'], reason: 'not-found');
		$window = $this->read(schema: self::WINDOW_SCHEMA, id: $windowId);
		if ($window === null || $userId === '') {
			return $notFound;
		}

		$session = $this->read(schema: 'session', id: (string)($window['sessionId'] ?? ''));
		if ($session === null) {
			return $notFound;
		}

		$cohort = $this->read(schema: 'cohort', id: (string)($session['cohortId'] ?? ''));
		$learners = ($cohort['learnerIds'] ?? []);
		if (is_array($learners) === false || in_array($userId, $learners, true) === false) {
			return new PortalOutcome(status: Http::STATUS_FORBIDDEN, body: ['error' => 'not_in_group'], reason: 'not-in-group');
		}

		return [$window, $session];
	}//end context()

	/**
	 * Whether the window is open now: lifecycle open, after `opensAt`, before
	 * `closesAt` and before the lesson's end.
	 *
	 * @param array<string, mixed> $window  The window.
	 * @param array<string, mixed> $session The session.
	 *
	 * @return bool
	 */
	private function isOpen(array $window, array $session): bool {
		if (($window['lifecycle'] ?? 'open') !== 'open') {
			return false;
		}

		$now = $this->now();
		$opensAt = $this->date(value: ($window['opensAt'] ?? null));
		if ($opensAt !== null && $now < $opensAt) {
			return false;
		}

		foreach ([($window['closesAt'] ?? null), ($session['endsAt'] ?? null)] as $bound) {
			$end = $this->date(value: $bound);
			if ($end !== null && $now > $end) {
				return false;
			}
		}

		return true;
	}//end isOpen()

	/**
	 * `late` after the lesson start plus the grace minutes, else `present`.
	 *
	 * @param array<string, mixed> $session The session.
	 * @param array<string, mixed> $window  The window.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/attendance/spec.md#scenario-a-learner-checks-in-after-the-grace-period
	 */
	private function status(array $session, array $window): string {
		$start = $this->date(value: ($session['startsAt'] ?? null));
		if ($start === null) {
			return 'present';
		}

		$grace = (int)($window['lateAfterMinutes'] ?? 5);
		if ($this->now()->getTimestamp() > $start->getTimestamp() + ($grace * 60)) {
			return 'late';
		}

		return 'present';
	}//end status()

	/**
	 * Whether any attendance record exists for the learner and lesson.
	 *
	 * @param string $sessionId The session uuid.
	 * @param string $userId    The learner.
	 *
	 * @return bool
	 */
	private function hasRecord(string $sessionId, string $userId): bool {
		$rows = $this->objects->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::RECORD_SCHEMA,
					'sessionId' => $sessionId,
					'learnerId' => $userId,
				],
				'limit' => 1,
			],
			_rbac: false
		);

		return $rows !== [];
	}//end hasRecord()

	/**
	 * One learniq object by uuid as a plain array, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objects->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false, _render: false);
		} catch (DoesNotExistException) {
			return null;
		}

		$row = $object?->jsonSerialize();
		if (is_array($row) === false) {
			return null;
		}

		$row['id'] = (string)($row['id'] ?? ($row['@self']['id'] ?? $id));

		return $row;
	}//end read()

	/**
	 * Now, in UTC.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'));
	}//end now()

	/**
	 * A stored date-time, or null when absent or unreadable.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function date(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable) {
			return null;
		}
	}//end date()
}//end class
