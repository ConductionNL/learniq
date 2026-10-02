<?php

/**
 * Learniq conference free slot generator
 *
 * In a ConferenceRound with direct booking, teachers publish free times and a
 * parent picks one. This listener writes those free times: when the round
 * moves to `booking-open` (the `open-booking` transition, and the
 * `create-free-slots` self-transition a teacher runs to add times later), it
 * cuts every submitted or locked TeacherAvailability of the round into
 * `slotDurationMinutes` slots with `bufferMinutes` between them (the same
 * slicing ConferenceScheduleGenerator uses) and writes each as a
 * ConferenceSlot in `free`.
 *
 * Each free slot carries who may book it (`eligibleLearnerRefs`: the invited
 * pupils of the teacher's own groups in the round, or every invited pupil
 * when the teacher has no group in it), the teacher's name and a one-line
 * label in the school's time zone for the portal's time picker.
 *
 * Running it again adds only what is missing: a time that overlaps a slot the
 * teacher already has in the round (in any state but cancelled or declined)
 * is skipped. A round with preference booking is left alone.
 *
 * ADR-031 legitimate exception: cross-object write bridge, the same shape as
 * ConferenceScheduleGenerator.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use OCA\Learniq\Service\ConferenceBookingMode;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IDateTimeZone;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the free conference times of a direct-booking round.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
 */
class ConferenceFreeSlotGenerator implements IEventListener {

	private const REGISTER = 'learniq';

	private const ROUND_SCHEMA = 'conference-round';

	private const AVAILABILITY_SCHEMA = 'teacher-availability';

	private const SLOT_SCHEMA = 'conference-slot';

	private const COHORT_SCHEMA = 'cohort';

	/**
	 * Slot states that no longer hold the teacher's time.
	 */
	private const RELEASED_STATES = ['cancelled', 'declined'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads availability, slots and cohorts; writes slots.
	 * @param ListenerSchemaResolver $schemas Resolves the event's register and schema.
	 * @param LearnerRefResolver $profiles Nextcloud user id to LearnerProfile uuid.
	 * @param IUserManager $users The teacher's display name.
	 * @param IDateTimeZone $timeZone The school's time zone for the label.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ListenerSchemaResolver $schemas,
		private readonly LearnerRefResolver $profiles,
		private readonly IUserManager $users,
		private readonly IDateTimeZone $timeZone,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write free slots when a direct-booking round moves to `booking-open`.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false || $event->getTo() !== 'booking-open') {
			return;
		}

		if ($this->schemas->eventRegister(event: $event) !== self::REGISTER
			|| $this->schemas->eventSchema(event: $event) !== self::ROUND_SCHEMA
		) {
			return;
		}

		$round = $event->getObject()->jsonSerialize();
		if (ConferenceBookingMode::isDirect(round: $round) === false) {
			return;
		}

		try {
			$written = $this->generateForRound(round: $round);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[ConferenceFreeSlotGenerator] Free slots for round {round} could not be written: {msg}',
				['round' => ($round['id'] ?? ''), 'msg' => $exception->getMessage()]
			);
			return;
		}

		$this->logger->info(
			'[ConferenceFreeSlotGenerator] Round {round}: {count} free slot(s) written.',
			['round' => ($round['id'] ?? ''), 'count' => $written]
		);
	}//end handle()

	/**
	 * Write the missing free slots of one round.
	 *
	 * @param array<string, mixed> $round The round.
	 *
	 * @return int How many slots were written.
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
	 */
	public function generateForRound(array $round): int {
		$roundId = (string)($round['id'] ?? '');
		if ($roundId === '') {
			return 0;
		}

		$held = $this->heldIntervals(roundId: $roundId);
		$eligible = [];
		$written = 0;
		foreach ($this->availabilities(roundId: $roundId) as $availability) {
			$teacherId = (string)($availability['teacherId'] ?? '');
			if ($teacherId === '') {
				continue;
			}

			if (isset($eligible[$teacherId]) === false) {
				$eligible[$teacherId] = $this->eligibleRefs(round: $round, teacherId: $teacherId);
			}

			$candidates = ConferenceScheduleGenerator::sliceAvailability(
				blocks: (array)($availability['blocks'] ?? []),
				slotDurationMinutes: (int)($round['slotDurationMinutes'] ?? 10),
				bufferMinutes: (int)($round['bufferMinutes'] ?? 0)
			);
			foreach ($candidates as $candidate) {
				if (self::overlaps(candidate: $candidate, intervals: ($held[$teacherId] ?? [])) === true) {
					continue;
				}

				$this->objectService->saveObject(
					object: $this->freeSlot(round: $round, teacherId: $teacherId, candidate: $candidate, eligible: $eligible[$teacherId]),
					register: self::REGISTER,
					schema: self::SLOT_SCHEMA,
					_rbac: false,
					_multitenancy: false
				);
				$held[$teacherId][] = $candidate;
				$written++;
			}
		}//end foreach

		return $written;
	}//end generateForRound()

	/**
	 * One free slot.
	 *
	 * @param array<string, mixed> $round The round.
	 * @param string $teacherId The teacher's Nextcloud uid.
	 * @param array{startsAt: string, endsAt: string} $candidate The time.
	 * @param array<int, string> $eligible Who may book it.
	 *
	 * @return array<string, mixed>
	 */
	private function freeSlot(array $round, string $teacherId, array $candidate, array $eligible): array {
		$teacherName = $this->teacherName(teacherId: $teacherId);

		return [
			'conferenceRoundId' => (string)$round['id'],
			'teacherId' => $teacherId,
			'teacherName' => $teacherName,
			'startsAt' => $candidate['startsAt'],
			'endsAt' => $candidate['endsAt'],
			'slotLabel' => $this->label(candidate: $candidate, teacherName: $teacherName),
			'eligibleLearnerRefs' => $eligible,
			'location' => null,
			'tenant_id' => (string)($round['tenant_id'] ?? ''),
			'lifecycle' => 'free',
		];
	}//end freeSlot()

	/**
	 * The date, time and teacher in one line, in the school's time zone:
	 * `08-10-2026 18:00-18:10, Anna de Vries`.
	 *
	 * @param array{startsAt: string, endsAt: string} $candidate The time.
	 * @param string $teacherName The teacher's name.
	 *
	 * @return string
	 */
	private function label(array $candidate, string $teacherName): string {
		$zone = $this->timeZone->getDefaultTimeZone();
		try {
			$start = (new DateTimeImmutable($candidate['startsAt']))->setTimezone($zone);
			$end = (new DateTimeImmutable($candidate['endsAt']))->setTimezone($zone);
		} catch (Throwable $exception) {
			return $teacherName;
		}

		return $start->format('d-m-Y H:i') . '-' . $end->format('H:i') . ', ' . $teacherName;
	}//end label()

	/**
	 * The teacher's display name, or the uid when there is none.
	 *
	 * @param string $teacherId The uid.
	 *
	 * @return string
	 */
	private function teacherName(string $teacherId): string {
		$name = $this->users->getDisplayName($teacherId);
		if ($name === null || $name === '') {
			return $teacherId;
		}

		return $name;
	}//end teacherName()

	/**
	 * The pupils whose parents may book this teacher: the invited pupils of
	 * the round's groups the teacher teaches, or every invited pupil when the
	 * teacher teaches none of them.
	 *
	 * @param array<string, mixed> $round The round.
	 * @param string $teacherId The teacher's uid.
	 *
	 * @return array<int, string> LearnerProfile uuids.
	 */
	private function eligibleRefs(array $round, string $teacherId): array {
		$invited = array_values(array_filter((array)($round['invitedLearnerRefs'] ?? []), 'is_string'));
		$tenantId = (string)($round['tenant_id'] ?? '');
		$refs = [];
		foreach ((array)($round['cohortIds'] ?? []) as $cohortId) {
			$cohort = $this->row(id: (string)$cohortId, schema: self::COHORT_SCHEMA);
			if ($cohort === null || in_array($teacherId, (array)($cohort['teacherIds'] ?? []), true) === false) {
				continue;
			}

			foreach ((array)($cohort['learnerIds'] ?? []) as $learnerId) {
				$ref = $this->profiles->resolveInTenant(learnerId: (string)$learnerId, tenantId: $tenantId);
				if ($ref !== null && in_array($ref, $invited, true) === true) {
					$refs[$ref] = true;
				}
			}
		}

		if ($refs === []) {
			return $invited;
		}

		return array_map('strval', array_keys($refs));
	}//end eligibleRefs()

	/**
	 * Submitted and locked availability of the round.
	 *
	 * @param string $roundId The round uuid.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function availabilities(string $roundId): array {
		$rows = [];
		foreach (['submitted', 'locked'] as $lifecycle) {
			$rows = array_merge($rows, $this->rows(schema: self::AVAILABILITY_SCHEMA, filters: ['conferenceRoundId' => $roundId, 'lifecycle' => $lifecycle]));
		}

		return $rows;
	}//end availabilities()

	/**
	 * The intervals each teacher already holds in the round.
	 *
	 * @param string $roundId The round uuid.
	 *
	 * @return array<string, array<int, array{startsAt: string, endsAt: string}>>
	 */
	private function heldIntervals(string $roundId): array {
		$held = [];
		foreach ($this->rows(schema: self::SLOT_SCHEMA, filters: ['conferenceRoundId' => $roundId]) as $slot) {
			if (in_array(($slot['lifecycle'] ?? ''), self::RELEASED_STATES, true) === true) {
				continue;
			}

			$held[(string)($slot['teacherId'] ?? '')][] = [
				'startsAt' => (string)($slot['startsAt'] ?? ''),
				'endsAt' => (string)($slot['endsAt'] ?? ''),
			];
		}

		return $held;
	}//end heldIntervals()

	/**
	 * Whether a candidate overlaps any interval (half open).
	 *
	 * @param array{startsAt: string, endsAt: string} $candidate The candidate.
	 * @param array<int, array{startsAt: string, endsAt: string}> $intervals The held intervals.
	 *
	 * @return bool
	 */
	private static function overlaps(array $candidate, array $intervals): bool {
		$start = strtotime($candidate['startsAt']);
		$end = strtotime($candidate['endsAt']);
		foreach ($intervals as $interval) {
			$otherStart = strtotime($interval['startsAt']);
			$otherEnd = strtotime($interval['endsAt']);
			if ($start === false || $end === false || $otherStart === false || $otherEnd === false) {
				continue;
			}

			if ($start < $otherEnd && $otherStart < $end) {
				return true;
			}
		}

		return false;
	}//end overlaps()

	/**
	 * Rows of a learniq schema as arrays.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, string> $filters Property filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(string $schema, array $filters): array {
		$rows = $this->objectService->findAll(
			[
				'filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters),
				'limit' => 5000,
			],
			_rbac: false,
			_multitenancy: false
		);

		return array_map(
			static fn ($row): array => is_array($row) === true ? $row : $row->jsonSerialize(),
			$rows
		);
	}//end rows()

	/**
	 * One learniq object as an array, or null.
	 *
	 * @param string $id The uuid.
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function row(string $id, string $schema): ?array {
		if ($id === '') {
			return null;
		}

		$object = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false, _multitenancy: false);
		if ($object === null) {
			return null;
		}

		return $object->jsonSerialize();
	}//end row()
}//end class
