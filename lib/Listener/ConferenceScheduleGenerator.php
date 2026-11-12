<?php

/**
 * Learniq Conference Schedule Generator
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * IEventListener for ConferenceRound lifecycle → `scheduled` (the OR
 * ObjectTransitionedEvent with register=learniq, schema=conference-round,
 * to=scheduled — fired by both the `generate` transition, booking-closed →
 * scheduled, and the idempotent `regenerate` self-transition, scheduled →
 * scheduled). Runs the greedy, submission-order, earliest-fit conflict-free
 * slot-assignment algorithm described in
 * openspec/changes/archive/2026-07-13-parent-evening-planner/design.md over submitted/locked
 * TeacherAvailability and submitted/waitlisted ConferenceSignup rows for the
 * round, and writes ConferenceSlot objects via ObjectService::saveObject.
 *
 * Algorithm (design.md):
 *   1. ConferenceSlotSlicer::slice() cuts each teacher's declared free blocks into a
 *      chronologically ordered FIFO queue of slotDurationMinutes candidates,
 *      bufferMinutes apart. Pure function, no side effects.
 *   2. Candidate slots overlapping an already-`confirmed` ConferenceSlot for
 *      that teacher are excluded up front — confirmed appointments are
 *      pinned across a regenerate.
 *   3. Signups (submitted + waitlisted, so a regenerate re-attempts a
 *      previously waitlisted signup) are walked oldest-`createdAt`-first.
 *      For each requestedTeacherIds entry already satisfied by an existing
 *      non-cancelled ConferenceSlot, nothing is re-created (idempotency). For
 *      the rest, the teacher's queue is popped until a slot is found that
 *      does not overlap any interval already assigned to *this signup* in
 *      this pass (across any teacher) — a parent must never receive two
 *      simultaneous conversations. A slot rejected for overlap is discarded
 *      permanently (design.md "Complexity": each slot visited at most once
 *      per pass), not requeued for a later signup.
 *   4. A signup with every requestedTeacherIds entry satisfied (existing or
 *      newly created) is written `scheduled`; a signup with any unmet
 *      teacher-request is written `waitlisted`, with the unmet teacher ids
 *      appended to `notes`.
 *
 * ADR-031 legitimate exception: cross-object write bridge — schedule
 * generation is a genuine algorithm (allocation over two collections), not a
 * declarative schema expression. Matches ExcuseApprovalHandler's shape.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/parent-conferences/spec.md#requirement-schedule-generation-is-a-declared-greedy-solver-triggered-by-a-round-transition-not-a-php-crud-controller
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ConferenceBookingMode;
use OCA\Learniq\Service\ConferenceSignupRequest;
use OCA\Learniq\Service\ConferenceSlotPass;
use OCA\Learniq\Service\ConferenceSlotSlicer;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Generates a conflict-free ConferenceSlot schedule from submitted signups
 * and teacher availability when a ConferenceRound transitions to `scheduled`.
 *
 * @implements IEventListener<Event>
 */
class ConferenceScheduleGenerator implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const CONFERENCE_ROUND_SCHEMA = 'conference-round';
	private const TEACHER_AVAILABILITY_SCHEMA = 'teacher-availability';
	private const CONFERENCE_SIGNUP_SCHEMA = 'conference-signup';
	private const CONFERENCE_SLOT_SCHEMA = 'conference-slot';

	/**
	 * Non-cancelled ConferenceSlot lifecycle states — a slot in one of these
	 * states already occupies the teacher's/signup's time and must not be
	 * duplicated or re-assigned.
	 *
	 * @var string[]
	 */
	private const ACTIVE_SLOT_STATES = ['proposed', 'confirmed', 'completed'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param LoggerInterface $logger PSR logger.
	 * @param ListenerSchemaResolver $schemas Resolves the transition event's register and schema ids to slugs.
	 * @param IUserManager|null $users The teacher's display name for the parent portal (direct-conference-booking).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly ListenerSchemaResolver $schemas,
		private readonly ?IUserManager $users=null,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/parent-conferences/spec.md#requirement-schedule-generation-is-a-declared-greedy-solver-triggered-by-a-round-transition-not-a-php-crud-controller
	 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		if ($this->schemas->eventRegister(event: $event) !== self::LEARNIQ_REGISTER) {
			return;
		}

		if ($this->schemas->eventSchema(event: $event) !== self::CONFERENCE_ROUND_SCHEMA || $event->getTo() !== 'scheduled') {
			return;
		}

		$round = $event->getObject()->jsonSerialize();
		// A round with direct booking has no preferences to plan: parents
		// picked their own free times (ConferenceSlotBookingStamp).
		if ((new ConferenceBookingMode())->isDirect(round: $round) === true) {
			return;
		}

		$this->generateForRound(round: $round);

	}//end handle()

	/**
	 * Run the greedy conflict-free generation pass for one ConferenceRound.
	 *
	 * @param array<string,mixed> $round The ConferenceRound property array.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/parent-conferences/spec.md#scenario-conflict-free-generation-from-sign-ups-and-availability
	 * @spec openspec/specs/parent-conferences/spec.md#scenario-republish-after-a-last-minute-cancellation-does-not-disturb-confirmed-slots
	 */
	private function generateForRound(array $round): void {
		$roundId = $round['id'] ?? ($round['uuid'] ?? '');
		if ($roundId === '') {
			$this->logger->warning('[ConferenceScheduleGenerator] ConferenceRound has no id; aborting generation.');
			return;
		}

		$tenantId = $round['tenant_id'] ?? '';
		$availabilities = array_merge(
			$this->fetchByRoundAndLifecycle(schema: self::TEACHER_AVAILABILITY_SCHEMA, roundId: $roundId, lifecycle: 'submitted'),
			$this->fetchByRoundAndLifecycle(schema: self::TEACHER_AVAILABILITY_SCHEMA, roundId: $roundId, lifecycle: 'locked'),
		);
		$pinned = $this->pinnedSlots(roundId: $roundId);

		// Step 1 — slice availability into a per-teacher slot queue, excluding
		// minutes already consumed by a confirmed slot for that teacher.
		$queues = $this->buildTeacherQueues(
			availabilities: $availabilities,
			slotDurationMinutes: (int)($round['slotDurationMinutes'] ?? 10),
			bufferMinutes: (int)($round['bufferMinutes'] ?? 0),
			confirmedByTeacher: $pinned['confirmedByTeacher']
		);

		$outcome = $this->assignSignups(
			signups: $this->pendingSignups(roundId: $roundId),
			pass: new ConferenceSlotPass(roundId: (string)$roundId, tenantId: (string)$tenantId, queues: $queues, activeSlotKeys: $pinned['activeSlotKeys']),
			intervalsBySignup: $pinned['intervalsBySignup']
		);

		$this->writeOutcome(roundId: $roundId, outcome: $outcome);

	}//end generateForRound()

	/**
	 * The round's existing slots indexed for regeneration, with the slots of cancelled sign-ups freed.
	 *
	 * @param string $roundId The ConferenceRound id.
	 *
	 * @return array<string,mixed> indexExistingSlots() result.
	 */
	private function pinnedSlots(string $roundId): array {
		$existingSlots = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::CONFERENCE_SLOT_SCHEMA,
					'conferenceRoundId' => $roundId,
				],
				'limit' => 5000,
			]
		);
		$existingSlots = array_map([$this, 'normalise'], $existingSlots);

		// Step 4 (regenerate) — a cancelled signup frees its ConferenceSlots'
		// minutes back to the teacher's queue: cancel any still-active slot
		// belonging to a cancelled signup so its interval is not re-treated as
		// consumed below. A confirmed slot belonging to a *still-live* signup
		// is untouched (design.md "regenerate MUST NOT re-shuffle confirmed
		// ConferenceSlots").
		$cancelledSignupIds = array_map(
			function ($signup): string {
				$signup = $this->normalise(row: $signup);
				return (string)($signup['id'] ?? ($signup['uuid'] ?? ''));
			},
			$this->fetchByRoundAndLifecycle(schema: self::CONFERENCE_SIGNUP_SCHEMA, roundId: $roundId, lifecycle: 'cancelled')
		);
		$cancelledSignupIds = array_flip(array_filter($cancelledSignupIds, static fn (string $id): bool => $id !== ''));

		return $this->indexExistingSlots(existingSlots: $existingSlots, cancelledSignupIds: $cancelledSignupIds);
	}//end pinnedSlots()

	/**
	 * Submitted and waitlisted sign-ups of the round, in submission order.
	 *
	 * @param string $roundId The ConferenceRound id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function pendingSignups(string $roundId): array {
		// Step 2 — walk submitted + waitlisted signups in submission order.
		$signups = array_merge(
			$this->fetchByRoundAndLifecycle(schema: self::CONFERENCE_SIGNUP_SCHEMA, roundId: $roundId, lifecycle: 'submitted'),
			$this->fetchByRoundAndLifecycle(schema: self::CONFERENCE_SIGNUP_SCHEMA, roundId: $roundId, lifecycle: 'waitlisted'),
		);
		$signups = array_map([$this, 'normalise'], $signups);
		usort($signups, static fn (array $a, array $b): int => strcmp((string)($a['createdAt'] ?? ''), (string)($b['createdAt'] ?? '')));

		return $signups;
	}//end pendingSignups()

	/**
	 * Save the new slots and the changed sign-ups, and log the counts.
	 *
	 * @param string              $roundId The ConferenceRound id.
	 * @param array<string,mixed> $outcome assignSignups() result.
	 *
	 * @return void
	 */
	private function writeOutcome(string $roundId, array $outcome): void {
		foreach ($outcome['newSlots'] as $slot) {
			$this->objectService->saveObject(
				object: $slot,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: self::LEARNIQ_REGISTER, schema: self::CONFERENCE_SLOT_SCHEMA)
				)
			);
		}

		foreach ($outcome['signupSaves'] as $signup) {
			$this->objectService->saveObject(
				object: $signup,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: self::LEARNIQ_REGISTER, schema: self::CONFERENCE_SIGNUP_SCHEMA)
				)
			);
		}

		$this->logger->info(
			'[ConferenceScheduleGenerator] Round {round}: {slots} slot(s) written, {scheduled} signup(s) scheduled, {waitlisted} waitlisted.',
			[
				'round' => $roundId,
				'slots' => count($outcome['newSlots']),
				'scheduled' => $outcome['scheduledCount'],
				'waitlisted' => $outcome['waitlistedCount'],
			]
		);
	}//end writeOutcome()

	/**
	 * Index the round's existing ConferenceSlots into the three lookups the
	 * generation pass needs, cancelling any slot whose signup has been cancelled.
	 *
	 * Step 4 (regenerate): a cancelled signup frees its slots' minutes back to
	 * the teacher's queue, so those slots are cancelled here rather than being
	 * re-treated as consumed. A confirmed slot belonging to a still-live signup
	 * is untouched (design.md "regenerate MUST NOT re-shuffle confirmed
	 * ConferenceSlots").
	 *
	 * @param array<int,array<string,mixed>> $existingSlots Normalised existing slots for the round.
	 * @param array<string,int> $cancelledSignupIds Set of cancelled signup ids (keys).
	 *
	 * @return array{confirmedByTeacher: array<string,array<int,array<string,mixed>>>,
	 *               activeSlotKeys: array<string,true>,
	 *               intervalsBySignup: array<string,array<int,array<string,mixed>>>}
	 *
	 * @spec openspec/specs/parent-conferences/spec.md#scenario-republish-after-a-last-minute-cancellation-does-not-disturb-confirmed-slots
	 */
	private function indexExistingSlots(array $existingSlots, array $cancelledSignupIds): array {
		// Confirmed slots are pinned — their minutes are excluded from re-slicing.
		$confirmedByTeacher = [];
		// Any non-cancelled slot for a (signupId, teacherId) pair means that
		// teacher-request is already satisfied — never duplicate it.
		$activeSlotKeys = [];
		// Every non-cancelled interval already assigned to a signup (any
		// teacher) — the overlap guard for newly assigned slots in this pass.
		$intervalsBySignup = [];

		foreach ($existingSlots as $slot) {
			$status = ($slot['lifecycle'] ?? '');
			$teacherId = ($slot['teacherId'] ?? '');
			$signupId = ($slot['signupId'] ?? '');

			if (in_array($status, self::ACTIVE_SLOT_STATES, true) === false) {
				continue;
			}

			if ($signupId !== '' && isset($cancelledSignupIds[$signupId]) === true) {
				$freedSlot = $slot;
				$freedSlot['lifecycle'] = 'cancelled';
				$this->objectService->saveObject(
					object: $freedSlot,
					options: new ObjectSaveOptions(
						target: new ObjectFindTarget(register: self::LEARNIQ_REGISTER, schema: self::CONFERENCE_SLOT_SCHEMA)
					)
				);
				continue;
			}

			$interval = [
				'startsAt' => ($slot['startsAt'] ?? ''),
				'endsAt' => ($slot['endsAt'] ?? ''),
			];

			if ($status === 'confirmed') {
				$confirmedByTeacher[$teacherId][] = $interval;
			}

			if ($signupId !== '') {
				$activeSlotKeys[$signupId . '|' . $teacherId] = true;
				$intervalsBySignup[$signupId][] = $interval;
			}
		}//end foreach

		return [
			'confirmedByTeacher' => $confirmedByTeacher,
			'activeSlotKeys' => $activeSlotKeys,
			'intervalsBySignup' => $intervalsBySignup,
		];

	}//end indexExistingSlots()

	/**
	 * Step 1 — build the per-teacher queue of free candidate slots, in
	 * chronological order, excluding minutes a confirmed slot already consumes.
	 *
	 * @param array<int,mixed> $availabilities Submitted + locked TeacherAvailability rows.
	 * @param int $slotDurationMinutes Length of one slot in minutes.
	 * @param int $bufferMinutes Gap between consecutive slots in minutes.
	 * @param array<string,array<int,array<string,mixed>>> $confirmedByTeacher Pinned confirmed intervals per teacher.
	 *
	 * @return array<string,array<int,array<string,mixed>>> Map of teacherId => ordered free slots.
	 *
	 * @spec openspec/specs/parent-conferences/spec.md#scenario-conflict-free-generation-from-sign-ups-and-availability
	 */
	private function buildTeacherQueues(
		array $availabilities,
		int $slotDurationMinutes,
		int $bufferMinutes,
		array $confirmedByTeacher,
	): array {
		$queues = [];

		foreach ($availabilities as $availability) {
			$availability = $this->normalise(row: $availability);
			$teacherId = ($availability['teacherId'] ?? '');

			$sliced = (new ConferenceSlotSlicer())->slice(
				blocks: ($availability['blocks'] ?? []),
				slotDurationMinutes: $slotDurationMinutes,
				bufferMinutes: $bufferMinutes
			);

			$confirmed = ($confirmedByTeacher[$teacherId] ?? []);
			$free = array_values(
				array_filter(
					$sliced,
					fn (array $candidate): bool => $this->overlapsAny(candidate: $candidate, intervals: $confirmed) === false
				)
			);

			// Cast the key: this map is declared
			// array<string,array<int,array<string,mixed>>>, and an
			// un-narrowed key would widen it.
			$queues[(string)$teacherId] = array_merge(($queues[(string)$teacherId] ?? []), $free);
		}//end foreach

		foreach ($queues as $teacherId => $queue) {
			usort($queue, static fn (array $a, array $b): int => strcmp((string)$a['startsAt'], (string)$b['startsAt']));
			$queues[$teacherId] = $queue;
		}

		return $queues;
	}//end buildTeacherQueues()

	/**
	 * Step 2 — walk the signups in submission order, taking slots off the teacher
	 * queues, and decide each signup's resulting lifecycle.
	 *
	 * A signup whose every requested teacher was met becomes `scheduled`; one
	 * with any unmet request becomes `waitlisted` with the unmet teachers named
	 * in its notes, so the reason is never left implicit.
	 *
	 * @param array<int,array<string,mixed>>               $signups           Signups in submission order.
	 * @param ConferenceSlotPass                           $pass              The pass: round, tenant, queues and satisfied requests.
	 * @param array<string,array<int,array<string,mixed>>> $intervalsBySignup Intervals already assigned per signup.
	 *
	 * @return array{newSlots: array<int,array<string,mixed>>, signupSaves: array<int,array<string,mixed>>, scheduledCount: int, waitlistedCount: int}
	 *
	 * @spec openspec/specs/parent-conferences/spec.md#scenario-conflict-free-generation-from-sign-ups-and-availability
	 */
	private function assignSignups(array $signups, ConferenceSlotPass $pass, array $intervalsBySignup): array {
		$newSlots = [];
		$signupSaves = [];
		$scheduledCount = 0;
		$waitlistedCount = 0;

		foreach ($signups as $signup) {
			$signupId = ($signup['id'] ?? ($signup['uuid'] ?? ''));

			$assigned = $this->assignSlotsForSignup(
				request: new ConferenceSignupRequest(
					signup: $signup,
					signupId: (string)$signupId,
					assignedIntervals: ($intervalsBySignup[$signupId] ?? [])
				),
				pass: $pass
			);

			$newSlots = array_merge($newSlots, $assigned['newSlots']);

			if (count($assigned['unmetTeacherIds']) === 0) {
				$signup['lifecycle'] = 'scheduled';
				$scheduledCount++;
				$signupSaves[] = $signup;
				continue;
			}

			// Waitlisted: name the unmet teacher-requests in the notes so the
			// reason is never left implicit. array_filter drops the existing
			// notes when they are empty, so the ' | ' separator only appears
			// when there is something to separate.
			$reason = 'Unmet teacher-request(s): ' . implode(', ', $assigned['unmetTeacherIds']);

			$signup['lifecycle'] = 'waitlisted';
			$signup['notes'] = implode(' | ', array_filter([(string)($signup['notes'] ?? ''), $reason]));

			$signupSaves[] = $signup;
			$waitlistedCount++;
		}//end foreach

		return [
			'newSlots' => $newSlots,
			'signupSaves' => $signupSaves,
			'scheduledCount' => $scheduledCount,
			'waitlistedCount' => $waitlistedCount,
		];

	}//end assignSignups()

	/**
	 * Take one slot off each requested teacher's queue for a single signup.
	 *
	 * A teacher-request already satisfied by an existing non-cancelled slot is an
	 * idempotent no-op, which is what makes regeneration safe to re-run.
	 *
	 * @param ConferenceSignupRequest $request The signup, its id and the intervals it already occupies.
	 * @param ConferenceSlotPass      $pass    The pass (mutated: slots are consumed, satisfied keys added).
	 *
	 * @return array{newSlots: array<int,array<string,mixed>>, unmetTeacherIds: array<int,string>}
	 *
	 * @spec openspec/specs/parent-conferences/spec.md#scenario-conflict-free-generation-from-sign-ups-and-availability
	 */
	private function assignSlotsForSignup(ConferenceSignupRequest $request, ConferenceSlotPass $pass): array {
		$signup = $request->signup;
		$signupId = $request->signupId;
		$assignedIntervals = $request->assignedIntervals;
		$newSlots = [];
		$unmetTeacherIds = [];

		foreach (($signup['requestedTeacherIds'] ?? []) as $teacherId) {
			if (isset($pass->activeSlotKeys[$signupId . '|' . $teacherId]) === true) {
				// Already satisfied by an existing non-cancelled slot — idempotent no-op.
				continue;
			}

			// Cast the key: the queues are declared
			// array<string,array<int,array<string,mixed>>>, and writing with
			// an un-narrowed $teacherId would widen them.
			$queue = ($pass->queues[(string)$teacherId] ?? []);
			$slot = $this->popNextNonOverlapping(queue: $queue, blocked: $assignedIntervals);
			$pass->queues[(string)$teacherId] = $queue;

			if ($slot === null) {
				$unmetTeacherIds[] = $teacherId;
				continue;
			}

			$newSlots[] = [
				'conferenceRoundId' => $pass->roundId,
				'teacherId' => $teacherId,
				'teacherName' => ($this->users?->getDisplayName((string)$teacherId) ?? (string)$teacherId),
				'learnerId' => ($signup['learnerId'] ?? ''),
				'learnerRef' => ($signup['learnerRef'] ?? null),
				'signupId' => $signupId,
				'startsAt' => $slot['startsAt'],
				'endsAt' => $slot['endsAt'],
				'location' => null,
				'tenant_id' => $pass->tenantId,
				'lifecycle' => 'proposed',
			];

			$assignedIntervals[] = $slot;
			$pass->activeSlotKeys[$signupId . '|' . $teacherId] = true;
		}//end foreach

		return [
			'newSlots' => $newSlots,
			'unmetTeacherIds' => $unmetTeacherIds,
		];

	}//end assignSlotsForSignup()

	/**
	 * Pop candidate slots from the front of a teacher's queue until one is
	 * found that does not overlap any interval in `$blocked`, or the queue is
	 * exhausted. A rejected-for-overlap candidate is discarded permanently
	 * (design.md "Complexity" — visited at most once per pass), not requeued.
	 *
	 * @param array<int,array{startsAt:string,endsAt:string}> $queue The teacher's candidate queue (mutated: consumed from the front).
	 * @param array<int,array{startsAt:string,endsAt:string}> $blocked Intervals already assigned to the current signup.
	 *
	 * @return array{startsAt:string,endsAt:string}|null The first non-overlapping slot, or null if the queue is exhausted.
	 */
	private function popNextNonOverlapping(array &$queue, array $blocked): ?array {
		$remaining = count($queue);

		while ($remaining > 0) {
			$candidate = array_shift($queue);
			$remaining = count($queue);
			if ($this->overlapsAny(candidate: $candidate, intervals: $blocked) === false) {
				return $candidate;
			}
		}

		return null;
	}//end popNextNonOverlapping()

	/**
	 * Whether a candidate interval overlaps any interval in a set.
	 *
	 * Half-open interval overlap: [a.start, a.end) intersects [b.start, b.end)
	 * iff a.start < b.end AND b.start < a.end.
	 *
	 * @param array{startsAt:string,endsAt:string} $candidate The candidate interval.
	 * @param array<int,array{startsAt:string,endsAt:string}> $intervals The intervals to check against.
	 *
	 * @return bool True if the candidate overlaps at least one interval.
	 */
	private function overlapsAny(array $candidate, array $intervals): bool {
		$candidateStart = strtotime((string)$candidate['startsAt']);
		$candidateEnd = strtotime((string)$candidate['endsAt']);

		foreach ($intervals as $interval) {
			$intervalStart = strtotime((string)$interval['startsAt']);
			$intervalEnd = strtotime((string)$interval['endsAt']);

			if ($candidateStart === false || $candidateEnd === false || $intervalStart === false || $intervalEnd === false) {
				continue;
			}

			if ($candidateStart < $intervalEnd && $intervalStart < $candidateEnd) {
				return true;
			}
		}

		return false;
	}//end overlapsAny()

	/**
	 * Fetch OR objects for a schema filtered to a ConferenceRound + a single
	 * lifecycle state.
	 *
	 * @param string $schema OR schema slug.
	 * @param string $roundId ConferenceRound UUID.
	 * @param string $lifecycle Lifecycle state to filter on.
	 *
	 * @return array<int,mixed> Raw rows as returned by ObjectService::findAll().
	 */
	private function fetchByRoundAndLifecycle(string $schema, string $roundId, string $lifecycle): array {
		return $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => $schema,
					'conferenceRoundId' => $roundId,
					'lifecycle' => $lifecycle,
				],
				'limit' => 2000,
			]
		);

	}//end fetchByRoundAndLifecycle()

	/**
	 * Normalise an ObjectService row to a plain array, whether it was
	 * returned as an array already or as an object exposing jsonSerialize().
	 *
	 * @param mixed $row Raw row from ObjectService::findAll().
	 *
	 * @return array<string,mixed>
	 */
	private function normalise(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		return $row->jsonSerialize();
	}//end normalise()
}//end class
