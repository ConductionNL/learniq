<?php

/**
 * Learniq Exam Sitting Placement Check
 *
 * Checks every ExamSitting write before it is saved. The rooms together must
 * seat the headcount, or the write is refused with both numbers. Lessons and
 * other exams at the same time in the same rooms or for the same classes are
 * recorded on the sitting as `clashWarnings`: a planner may still place the
 * exam (a class can sit an exam instead of its lesson), but sees what it
 * overlaps. The client never writes that list; it is replaced on every save.
 *
 * Clashes are found by querying sessions per room and per class, and sittings
 * per test week, on declared properties only. The day-bucket filter the
 * TimetableConflictDetector uses names a property the Session schema does not
 * declare, so it cannot serve here.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/changes/timetabling-exam-schedule/specs/exam-schedule/spec.md#requirement-exam-periods-and-sittings
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses a sitting whose rooms are too small and records its clashes.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/timetabling-exam-schedule/specs/exam-schedule/spec.md#requirement-exam-periods-and-sittings
 */
class ExamSittingPlacementCheck implements IEventListener {

	private const REGISTER = 'learniq';
	private const SITTING_SCHEMA = 'exam-sitting';

	/**
	 * Upper bound on rows read per clash query (one room, one class or one test week).
	 */
	private const QUERY_LIMIT = 2000;

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check an ExamSitting create or update.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-exam-schedule/specs/exam-schedule/spec.md#requirement-exam-periods-and-sittings
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$entity = $this->entityOf(event: $event);
		if ($event->isPropagationStopped() === true || $this->isSitting(entity: $entity) === false) {
			return;
		}

		$sitting = array_merge(($entity->getObject() ?? []), $event->getModifiedData());
		if (($sitting['lifecycle'] ?? 'planned') === 'cancelled') {
			$event->setModifiedData(array_merge($event->getModifiedData(), ['clashWarnings' => []]));
			return;
		}

		$start = strtotime((string)($sitting['startsAt'] ?? ''));
		$end = strtotime((string)($sitting['endsAt'] ?? ''));
		if ($start === false || $end === false || $end <= $start) {
			$this->refuse(event: $event, errors: ['reason' => 'exam-times-invalid', 'message' => 'The end of an exam must be after its start.']);
			return;
		}

		try {
			$refusal = $this->capacityRefusal(sitting: $sitting);
			$clashes = [];
			if ($refusal === null) {
				$clashes = $this->clashes(sitting: $sitting, start: $start, end: $end);
			}
		} catch (Throwable $exception) {
			$this->logger->warning('[ExamSittingPlacementCheck] Could not check a placement: {msg}', ['msg' => $exception->getMessage()]);
			$refusal = ['reason' => 'exam-check-failed', 'message' => 'The rooms and clashes for this exam could not be checked. Try again later.'];
		}

		if ($refusal !== null) {
			$this->refuse(event: $event, errors: $refusal);
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), ['clashWarnings' => $clashes]));
	}//end handle()

	/**
	 * Why the rooms cannot take this exam, or null when they can.
	 *
	 * @param array<string, mixed> $sitting The sitting being written.
	 *
	 * @return array<string, mixed>|null
	 */
	private function capacityRefusal(array $sitting): ?array {
		$capacity = 0;
		foreach ($this->ids(value: ($sitting['roomIds'] ?? [])) as $roomId) {
			$room = $this->objectService->find(id: $roomId, register: self::REGISTER, schema: 'room');
			if ($room === null) {
				return ['reason' => 'exam-room-unknown', 'message' => 'One of the rooms for this exam does not exist.'];
			}

			$capacity += (int)(($room->getObject() ?? [])['capacity'] ?? 0);
		}

		$headcount = (int)($sitting['headcount'] ?? 0);
		if ($capacity >= $headcount) {
			return null;
		}

		return [
			'reason' => 'exam-room-too-small',
			'message' => sprintf('The rooms seat %d learners, and %d learners sit this exam. Add a room or pick a larger one.', $capacity, $headcount),
			'capacity' => $capacity,
			'headcount' => $headcount,
		];
	}//end capacityRefusal()

	/**
	 * Lessons and other exams that overlap this sitting in its rooms or classes.
	 *
	 * @param array<string, mixed> $sitting The sitting being written.
	 * @param int $start Its start, as a Unix time.
	 * @param int $end Its end, as a Unix time.
	 *
	 * @return array<int, string> One line per clash: the title and the times.
	 */
	private function clashes(array $sitting, int $start, int $end): array {
		$rooms = $this->ids(value: ($sitting['roomIds'] ?? []));
		$cohorts = $this->ids(value: ($sitting['cohortIds'] ?? []));
		$found = [];

		$sessions = [];
		foreach ($rooms as $roomId) {
			$sessions = array_merge($sessions, $this->rows(schema: 'session', filters: ['roomId' => $roomId]));
		}

		foreach ($cohorts as $cohortId) {
			$sessions = array_merge($sessions, $this->rows(schema: 'session', filters: ['cohortId' => $cohortId]));
		}

		foreach ($sessions as $session) {
			if (($session['lifecycle'] ?? '') !== 'cancelled' && $this->overlaps(row: $session, start: $start, end: $end) === true) {
				$found[(string)($session['id'] ?? '')] = $this->line(title: (string)($session['title'] ?? ''), row: $session);
			}
		}

		$period = (string)($sitting['examPeriodId'] ?? '');
		$self = (string)($sitting['id'] ?? '');
		foreach ($this->rows(schema: self::SITTING_SCHEMA, filters: ['examPeriodId' => $period]) as $other) {
			$otherId = (string)($other['id'] ?? '');
			if ($otherId === $self || ($other['lifecycle'] ?? 'planned') === 'cancelled' || $this->overlaps(row: $other, start: $start, end: $end) === false) {
				continue;
			}

			$shared = array_intersect($rooms, $this->ids(value: ($other['roomIds'] ?? [])))
				+ array_intersect($cohorts, $this->ids(value: ($other['cohortIds'] ?? [])));
			if (count($shared) > 0) {
				$found[$otherId] = $this->line(title: $this->examTitle(sitting: $other), row: $other);
			}
		}

		return array_values($found);
	}//end clashes()

	/**
	 * Rows of one schema matching the filters, as arrays.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, string> $filters Property filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(string $schema, array $filters): array {
		$rows = $this->objectService->findAll(
			[
				'filters' => array_merge($filters, ['register' => self::REGISTER, 'schema' => $schema]),
				'limit' => self::QUERY_LIMIT,
			]
		);

		return array_map(
			static fn (mixed $row): array => ($row instanceof ObjectEntity ? ($row->getObject() ?? []) : (array)$row),
			$rows
		);
	}//end rows()

	/**
	 * The title of the exam a sitting holds, or a neutral label.
	 *
	 * @param array<string, mixed> $sitting The other sitting.
	 *
	 * @return string
	 */
	private function examTitle(array $sitting): string {
		$assessmentId = (string)($sitting['assessmentId'] ?? '');
		if ($assessmentId === '') {
			return 'Exam';
		}

		$assessment = $this->objectService->find(id: $assessmentId, register: self::REGISTER, schema: 'exam');

		return (string)(($assessment?->getObject() ?? [])['title'] ?? 'Exam');
	}//end examTitle()

	/**
	 * One clash line: the title and the start and end as stored.
	 *
	 * @param string $title What overlaps.
	 * @param array<string, mixed> $row The overlapping row.
	 *
	 * @return string
	 */
	private function line(string $title, array $row): string {
		return sprintf('%s (%s - %s)', $title, (string)($row['startsAt'] ?? ''), (string)($row['endsAt'] ?? ''));
	}//end line()

	/**
	 * Whether a row's startsAt and endsAt overlap the window.
	 *
	 * @param array<string, mixed> $row A session or sitting.
	 * @param int $start Window start.
	 * @param int $end Window end.
	 *
	 * @return bool
	 */
	private function overlaps(array $row, int $start, int $end): bool {
		$rowStart = strtotime((string)($row['startsAt'] ?? ''));
		$rowEnd = strtotime((string)($row['endsAt'] ?? ''));

		return $rowStart !== false && $rowEnd !== false && $rowStart < $end && $start < $rowEnd;
	}//end overlaps()

	/**
	 * A list of non-empty string ids.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<int, string>
	 */
	private function ids(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter(array_map('strval', $value), static fn (string $id): bool => $id !== ''));
	}//end ids()

	/**
	 * Whether the entity is an ExamSitting of this app.
	 *
	 * @param ObjectEntity $entity The object being written.
	 *
	 * @return bool
	 */
	private function isSitting(ObjectEntity $entity): bool {
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $entity) === self::SITTING_SCHEMA;
		} catch (Throwable $exception) {
			return false;
		}
	}//end isSitting()

	/**
	 * The object being written: the new state on an update.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 *
	 * @return ObjectEntity
	 */
	private function entityOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectUpdatingEvent === true) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end entityOf()

	/**
	 * Stop the write with a reason the caller can show.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 * @param array<string, mixed> $errors Reason, message and any numbers.
	 *
	 * @return void
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $errors): void {
		$event->setErrors($errors);
		$event->stopPropagation();
		$this->logger->info('[ExamSittingPlacementCheck] Refused an exam placement: {reason}', ['reason' => $errors['reason']]);
	}//end refuse()
}//end class
