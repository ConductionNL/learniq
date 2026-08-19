<?php

/**
 * Scholiq Excuse Approval Handler
 *
 * Listens for OpenRegister's ObjectTransitionedEvent and flips matching
 * AttendanceRecords from `absent-unexcused` to `absent-excused` when an
 * ExcuseRequest transitions to `approved`.
 *
 * Algorithm:
 * 1. Filter to register=scholiq, schema=excuse-request, to=approved.
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

	private const SCHOLIQ_REGISTER = 'scholiq';
	private const EXCUSE_REQUEST_SCHEMA = 'excuse-request';
	private const ATTENDANCE_RECORD_SCHEMA = 'attendance-record';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
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

		if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
			return;
		}

		if ($event->getSchema() !== self::EXCUSE_REQUEST_SCHEMA
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
		$requestId = $request['id'] ?? '';
		if ($requestId === '') {
			$requestId = $request['uuid'] ?? '';
		}

		$learnerId = $request['learnerId'] ?? '';
		$dateFrom = $request['dateFrom'] ?? '';
		$dateTo = $request['dateTo'] ?? '';

		if ($learnerId === '' || $dateFrom === '' || $dateTo === '') {
			$this->logger->warning(
				'[ExcuseApprovalHandler] ExcuseRequest {id} missing learnerId/dateFrom/dateTo — skipping.',
				['id' => $requestId]
			);
			return;
		}

		$window = $this->resolveExcuseWindow(dateFrom: $dateFrom, dateTo: $dateTo, requestId: (string)$requestId);
		if ($window === null) {
			return;
		}

		// Fetch all absent-unexcused records for this learner.
		$records = $this->objectService->findAll(
			[
				'register' => self::SCHOLIQ_REGISTER,
				'schema' => self::ATTENDANCE_RECORD_SCHEMA,
				'filters' => [
					'learnerId' => $learnerId,
					'status' => 'absent-unexcused',
				],
				'limit' => 1000,
			]
		);

		$flippedCount = $this->flipRecordsInWindow(
			records: $records,
			fromDay: $window['fromDay'],
			toDay: $window['toDay'],
			requestId: (string)$requestId
		);

		$this->logger->info(
			'[ExcuseApprovalHandler] ExcuseRequest {id} approved — flipped {n} AttendanceRecord(s) to absent-excused for learner {learner}.',
			['id' => $requestId, 'n' => $flippedCount, 'learner' => $learnerId]
		);

	}//end flipAttendanceRecords()

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
	 *
	 * @return int How many records were flipped.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-10
	 */
	private function flipRecordsInWindow(array $records, int $fromDay, int $toDay, string $requestId): int {
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

			$this->objectService->saveObject(
				register: self::SCHOLIQ_REGISTER,
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
}//end class
