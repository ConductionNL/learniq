<?php

/**
 * Learniq Excuse Approval Handler
 *
 * Listens for OpenRegister's ObjectTransitionedEvent and flips matching
 * AttendanceRecords from `absent-unexcused` to `absent-excused` when an
 * ExcuseRequest transitions to `approved`.
 *
 * Algorithm:
 * 1. Filter to register=learniq, schema=excuse-request, to=approved.
 * 2. Read learnerId, dateFrom, dateTo, and request id from the event object.
 * 3. Fetch all AttendanceRecords for the learner with status=absent-unexcused.
 * 4. Filter in PHP to those with markedAt within [dateFrom, dateTo].
 * 5. For each matching record: set status=absent-excused + excuseRequestId, persist.
 *
 * ADR-031 legitimate exception: cross-object write bridge — ExcuseRequest approval
 * must flip related AttendanceRecord objects. This cannot be expressed as schema
 * metadata declarations.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-10
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use DateTimeImmutable;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Service\Attendance\LessonHours;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Flips absent-unexcused AttendanceRecords to absent-excused when an ExcuseRequest is approved.
 *
 * @implements IEventListener<Event>
 */
class ExcuseApprovalHandler implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const EXCUSE_REQUEST_SCHEMA = 'excuse-request';
	private const ATTENDANCE_RECORD_SCHEMA = 'attendance-record';
	private const SESSION_SCHEMA = 'session';

	/**
	 * The sessions of a group read for this approval, by cohort id.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $sessionsByCohort = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param LoggerInterface $logger PSR logger.
	 * @param ListenerSchemaResolverInterface $schemas Resolves the transition event's register and schema ids to slugs.
	 * @param LessonHours $lessonHours Which lesson hour a session is (absence-reports-seen-and-ended T3).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly ListenerSchemaResolverInterface $schemas,
		private readonly LessonHours $lessonHours=new LessonHours(),
	) {
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-10
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		if ($this->schemas->eventRegister(event: $event) !== self::LEARNIQ_REGISTER) {
			return;
		}

		if ($this->schemas->eventSchema(event: $event) !== self::EXCUSE_REQUEST_SCHEMA
			|| $event->getTo() !== 'approved'
		) {
			return;
		}

		$this->flipAttendanceRecords(event: $event);

	}//end handle()

	/**
	 * Flip matching absent-unexcused AttendanceRecords to absent-excused.
	 *
	 * @param ObjectTransitionedEvent $event The ExcuseRequest-approved transition event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-10
	 */
	private function flipAttendanceRecords(ObjectTransitionedEvent $event): void {
		$request = $event->getObject()->jsonSerialize();
		$requestId = $this->requestIdOf(request: $request);

		if ($this->hasExcuseFields(request: $request) === false) {
			$this->logger->warning(
				'[ExcuseApprovalHandler] ExcuseRequest {id} missing learnerId/dateFrom/dateTo — skipping.',
				['id' => $requestId]
			);
			return;
		}

		$window = $this->resolveExcuseWindow(dateFrom: $request['dateFrom'], dateTo: $this->lastDayOf(request: $request), requestId: (string)$requestId);
		if ($window !== null) {
			$this->flipForLearner(request: $request, window: $window);
		}

	}//end flipAttendanceRecords()

	/**
	 * Whether a report is an illness without a last day yet: it covers every
	 * day from its first until the pupil is reported better.
	 *
	 * @param array<string, mixed> $request The ExcuseRequest.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/absence-reports-seen-and-ended/specs/attendance/spec.md#requirement-a-report-may-cover-lesson-hours-and-an-illness-may-stay-open-until-the-pupil-is-better
	 */
	private static function isOpenIllness(array $request): bool {
		return ($request['reasonKind'] ?? null) === 'illness' && ($request['dateTo'] ?? '') === '';
	}//end isOpenIllness()

	/**
	 * The last day a report covers: its `dateTo`, or for an open illness the
	 * furthest date, so every record from the first day on follows it.
	 *
	 * @param array<string, mixed> $request The ExcuseRequest.
	 *
	 * @return string
	 */
	private function lastDayOf(array $request): string {
		if (self::isOpenIllness(request: $request) === true) {
			return '9999-12-31';
		}

		return (string)$request['dateTo'];
	}//end lastDayOf()

	/**
	 * An excuse request's id, or its uuid when it has no id.
	 *
	 * @param array<string,mixed> $request The ExcuseRequest.
	 *
	 * @return mixed
	 */
	private function requestIdOf(array $request): mixed {
		$requestId = $request['id'] ?? '';
		if ($requestId === '') {
			$requestId = $request['uuid'] ?? '';
		}

		return $requestId;
	}//end requestIdOf()

	/**
	 * Whether an excuse request names its learner and both dates.
	 *
	 * @param array<string,mixed> $request The ExcuseRequest.
	 *
	 * @return bool
	 */
	private function hasExcuseFields(array $request): bool {
		return ($request['learnerId'] ?? '') !== ''
			&& ($request['dateFrom'] ?? '') !== ''
			&& (($request['dateTo'] ?? '') !== '' || self::isOpenIllness(request: $request) === true);
	}//end hasExcuseFields()

	/**
	 * Flip the learner's absent-unexcused records inside the excuse window and log how many.
	 *
	 * @param array<string,mixed>              $request The approved ExcuseRequest.
	 * @param array{fromDay: int, toDay: int} $window  The excuse window.
	 *
	 * @return void
	 */
	private function flipForLearner(array $request, array $window): void {
		$requestId = $this->requestIdOf(request: $request);
		$learnerId = $request['learnerId'];

		$this->sessionsByCohort = [];
		$flippedCount = $this->flipRecordsInWindow(
			records: $this->unexcusedRecordsOf(learnerId: $learnerId),
			fromDay: $window['fromDay'],
			toDay: $window['toDay'],
			requestId: (string)$requestId,
			hours: $this->lessonHours->rangeOf(request: $request)
		);

		$this->logger->info(
			'[ExcuseApprovalHandler] ExcuseRequest {id} approved — flipped {n} AttendanceRecord(s) to absent-excused for learner {learner}.',
			['id' => $requestId, 'n' => $flippedCount, 'learner' => $learnerId]
		);
	}//end flipForLearner()

	/**
	 * All absent-unexcused records of a learner.
	 *
	 * @param mixed $learnerId The learner.
	 *
	 * @return array<int,mixed>
	 */
	private function unexcusedRecordsOf(mixed $learnerId): array {
		return $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::ATTENDANCE_RECORD_SCHEMA,
					'learnerId' => $learnerId,
					'status' => 'absent-unexcused',
				],
				'limit' => 1000,
			]
		);
	}//end unexcusedRecordsOf()

	/**
	 * Resolve an ExcuseRequest's date range to an inclusive `Ymd` day window.
	 *
	 * #203: `strtotime()` happily accepts relative strings like 'last year',
	 * '-5 days' or 'yesterday', which would let an approval excuse arbitrary
	 * historic absences. Parsing through DateTimeImmutable AND requiring a
	 * literal `YYYY-MM-DD` prefix rejects those without silently accepting them.
	 *
	 * @param string $dateFrom Stored range start.
	 * @param string $dateTo Stored range end.
	 * @param string $requestId ExcuseRequest id, for the log line.
	 *
	 * @return array{fromDay: int, toDay: int}|null The window, or null when the dates are unusable.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-10
	 */
	private function resolveExcuseWindow(string $dateFrom, string $dateTo, string $requestId): ?array {
		try {
			$fromDt = new DateTimeImmutable($dateFrom);
			$toDt = new DateTimeImmutable($dateTo);
		} catch (\Exception) {
			$this->logger->warning(
				'[ExcuseApprovalHandler] ExcuseRequest {id} has unparsable dates ({from}–{to}) — skipping.',
				['id' => $requestId, 'from' => $dateFrom, 'to' => $dateTo]
			);
			return null;
		}

		// Reject relative-string dates that parse but are not ISO 8601 formatted.
		// A valid stored date must match YYYY-MM-DD (with optional time/TZ).
		if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dateFrom) === 0
			|| preg_match('/^\d{4}-\d{2}-\d{2}/', $dateTo) === 0
		) {
			$this->logger->warning(
				'[ExcuseApprovalHandler] ExcuseRequest {id} dates are not ISO 8601 ({from}–{to}) — skipping.',
				['id' => $requestId, 'from' => $dateFrom, 'to' => $dateTo]
			);
			return null;
		}

		return [
			'fromDay' => (int)date('Ymd', $fromDt->getTimestamp()),
			'toDay' => (int)date('Ymd', $toDt->getTimestamp()),
		];

	}//end resolveExcuseWindow()

	/**
	 * Flip every absent-unexcused record whose `markedAt` day falls inside the
	 * approved window to absent-excused, stamping the originating request.
	 *
	 * A record with no parseable `markedAt` is left alone rather than guessed at.
	 *
	 * @param array<int,mixed> $records Candidate AttendanceRecords.
	 * @param int $fromDay Inclusive window start as `Ymd`.
	 * @param int $toDay Inclusive window end as `Ymd`.
	 * @param string $requestId ExcuseRequest id stamped onto each flipped record.
	 * @param array{0:int,1:int}|null $hours The report's lesson hours, null for whole days (absence-reports-seen-and-ended T3).
	 *
	 * @return int How many records were flipped.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-10
	 * @spec openspec/changes/absence-reports-seen-and-ended/tasks.md#T3
	 */
	private function flipRecordsInWindow(array $records, int $fromDay, int $toDay, string $requestId, ?array $hours=null): int {
		$flippedCount = 0;

		foreach ($records as $raw) {
			$record = $raw;
			if (is_array($raw) === false) {
				$record = $raw->jsonSerialize();
			}

			$markedAt = ($record['markedAt'] ?? '');
			if ($markedAt === '') {
				continue;
			}

			$markedTs = strtotime($markedAt);
			if ($markedTs === false) {
				continue;
			}

			// Only flip records whose markedAt falls within [dateFrom, dateTo].
			$markedDay = (int)date('Ymd', $markedTs);
			if ($markedDay < $fromDay || $markedDay > $toDay) {
				continue;
			}

			// A report for part of a day: only the lessons in its hours follow it.
			if ($hours !== null && $this->inHours(record: $record, hours: $hours) === false) {
				continue;
			}

			$this->objectService->saveObject(
				register: self::LEARNIQ_REGISTER,
				schema: self::ATTENDANCE_RECORD_SCHEMA,
				object: array_merge(
					$record,
					[
						'status' => 'absent-excused',
						'excuseRequestId' => $requestId,
					]
				)
			);

			$flippedCount++;
		}//end foreach

		return $flippedCount;
	}//end flipRecordsInWindow()

	/**
	 * Whether a record's session is one of the report's lesson hours.
	 *
	 * @param array<string, mixed> $record The AttendanceRecord.
	 * @param array{0:int,1:int}   $hours  The report's first and last hour.
	 *
	 * @return bool False when its session cannot be placed: a lesson outside the hours is never excused by guess.
	 *
	 * @spec openspec/changes/absence-reports-seen-and-ended/tasks.md#T3
	 */
	private function inHours(array $record, array $hours): bool {
		$cohortId = (string)($record['cohortId'] ?? '');
		$sessionId = (string)($record['sessionId'] ?? '');
		if ($cohortId === '' || $sessionId === '') {
			return false;
		}

		if (array_key_exists($cohortId, $this->sessionsByCohort) === false) {
			$this->sessionsByCohort[$cohortId] = $this->sessionsOf(cohortId: $cohortId);
		}

		return $this->lessonHours->covers(
			hour: $this->lessonHours->hourOf(sessionId: $sessionId, sessions: $this->sessionsByCohort[$cohortId]),
			range: $hours
		);
	}//end inHours()

	/**
	 * A group's sessions as plain arrays.
	 *
	 * @param string $cohortId The group.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function sessionsOf(string $cohortId): array {
		$rows = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::SESSION_SCHEMA,
					'cohortId' => $cohortId,
				],
				'limit' => 1000,
			]
		);

		$sessions = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				$row = $row->jsonSerialize();
			}

			$sessions[] = $row;
		}

		return $sessions;
	}//end sessionsOf()
}//end class
