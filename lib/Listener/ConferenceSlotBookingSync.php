<?php

/**
 * Learniq conference slot booking sync
 *
 * What follows when a booked conference time changes, in a round with direct
 * booking.
 *
 * Before the write (ObjectUpdatingEvent):
 * - a parent cancels from the portal (no session user, `booked` or
 *   `acknowledged` to `cancelled`): refused once the round's booking window
 *   has closed or the round is no longer `booking-open`;
 * - the teacher acknowledges: `acknowledgedAt` is stamped.
 *
 * After the write (ObjectUpdatedEvent):
 * - the booking (the ConferenceSignup the slot names) follows the slot:
 *   `acknowledged`, `declined` with the teacher's note, or `cancelled`. The
 *   parent sees that status, and a change of it reaches the guardian through
 *   portaliq's change rule on the bookings collection;
 * - a cancelled or declined time is offered again: while the round is
 *   `booking-open`, a new free slot for the same teacher and time is
 *   written, so the old slot keeps its history (who booked, the decline note)
 *   and another family can book the time.
 *
 * Writes in a round with preference booking are left alone, as are writes to
 * any other schema.
 *
 * ADR-031 legitimate exception: cross-object write bridge.
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
use DateTimeInterface;
use OCA\Learniq\Service\ConferenceBookingMode;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps a booking and the free times in step with a booked slot.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
 */
class ConferenceSlotBookingSync implements IEventListener {

	private const REGISTER = 'learniq';

	private const SLOT_SCHEMA = 'conference-slot';

	private const SIGNUP_SCHEMA = 'conference-signup';

	private const ROUND_SCHEMA = 'conference-round';

	/**
	 * Slot states a parent holds.
	 */
	private const HELD = ['booked', 'acknowledged'];

	/**
	 * Slot states that give the time back.
	 */
	private const RELEASED = ['cancelled', 'declined'];

	/**
	 * The refusal a parent reads when cancelling too late.
	 */
	public const LATE_CANCEL = 'The booking window has closed. Contact the school to change this time.';

	/**
	 * The fields a replacement free slot copies from the released one.
	 */
	private const FREE_COPY = ['conferenceRoundId', 'teacherId', 'teacherName', 'startsAt', 'endsAt', 'slotLabel', 'eligibleLearnerRefs', 'location', 'tenant_id'];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param ObjectService $objectService Reads the round and the booking; writes the booking and the free slot.
	 * @param IUserSession $userSession Tells a portal write (no session) from an app write.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ObjectService $objectService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a slot update, before or after the write.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent) {
			if ($event->isPropagationStopped() === false) {
				$this->beforeWrite(event: $event);
			}

			return;
		}

		if ($event instanceof ObjectUpdatedEvent) {
			$this->afterWrite(event: $event);
		}
	}//end handle()

	/**
	 * Refuse a late portal cancel; stamp when the teacher acknowledged.
	 *
	 * @param ObjectUpdatingEvent $event The event.
	 *
	 * @return void
	 */
	private function beforeWrite(ObjectUpdatingEvent $event): void {
		$move = $this->move(new: $event->getNewObject(), old: $event->getOldObject());
		if ($move === null) {
			return;
		}

		[$from, $to, $slot] = $move;
		if ($to === 'acknowledged' && $from === 'booked') {
			$event->setModifiedData(
				array_merge($event->getModifiedData(), ['acknowledgedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM)])
			);
			return;
		}

		if ($to !== 'cancelled' || in_array($from, self::HELD, true) === false || $this->userSession->getUser() !== null) {
			return;
		}

		$round = $this->row(id: (string)($slot['conferenceRoundId'] ?? ''), schema: self::ROUND_SCHEMA);
		if ($round !== null && $this->isCancellable(round: $round) === true) {
			return;
		}

		$event->setErrors(['reason' => 'cancel-window-closed', 'message' => self::LATE_CANCEL]);
		$event->stopPropagation();
	}//end beforeWrite()

	/**
	 * Mirror the booking and offer a released time again.
	 *
	 * @param ObjectUpdatedEvent $event The event.
	 *
	 * @return void
	 */
	private function afterWrite(ObjectUpdatedEvent $event): void {
		$move = $this->move(new: $event->getNewObject(), old: $event->getOldObject());
		if ($move === null) {
			return;
		}

		[$from, $to, $slot] = $move;
		if (in_array($from, self::HELD, true) === false || in_array($to, array_merge(['acknowledged'], self::RELEASED), true) === false) {
			return;
		}

		try {
			$round = $this->row(id: (string)($slot['conferenceRoundId'] ?? ''), schema: self::ROUND_SCHEMA);
			if ($round === null || ConferenceBookingMode::isDirect(round: $round) === false) {
				return;
			}

			$this->mirrorBooking(slot: $slot, to: $to);
			if (in_array($to, self::RELEASED, true) === true && ($round['lifecycle'] ?? '') === 'booking-open') {
				$this->offerAgain(slot: $slot);
			}
		} catch (Throwable $exception) {
			$this->logger->error(
				'[ConferenceSlotBookingSync] Slot {slot} moved to {to}, but the follow-up failed: {msg}',
				['slot' => ($slot['id'] ?? ''), 'to' => $to, 'msg' => $exception->getMessage()]
			);
		}
	}//end afterWrite()

	/**
	 * The booking follows the slot.
	 *
	 * @param array<string, mixed> $slot The slot after the write.
	 * @param string $to The slot's new state.
	 *
	 * @return void
	 */
	private function mirrorBooking(array $slot, string $to): void {
		$signupId = (string)($slot['signupId'] ?? '');
		$signup = $this->row(id: $signupId, schema: self::SIGNUP_SCHEMA);
		if ($signup === null || ($signup['lifecycle'] ?? '') === $to) {
			return;
		}

		$signup['lifecycle'] = $to;
		if ($to === 'declined') {
			$signup['declineNote'] = ($slot['declineNote'] ?? null);
		}

		unset($signup['@self'], $signup['id']);
		$this->objectService->saveObject(
			object: $signup,
			register: self::REGISTER,
			schema: self::SIGNUP_SCHEMA,
			uuid: $signupId,
			_rbac: false,
			_multitenancy: false
		);
	}//end mirrorBooking()

	/**
	 * Write a new free slot for the released time.
	 *
	 * @param array<string, mixed> $slot The released slot.
	 *
	 * @return void
	 */
	private function offerAgain(array $slot): void {
		$free = array_intersect_key($slot, array_flip(self::FREE_COPY));
		$free['lifecycle'] = 'free';
		$this->objectService->saveObject(
			object: $free,
			register: self::REGISTER,
			schema: self::SLOT_SCHEMA,
			_rbac: false,
			_multitenancy: false
		);
	}//end offerAgain()

	/**
	 * Whether a parent may still cancel: the round is `booking-open` and its
	 * window has not closed.
	 *
	 * @param array<string, mixed> $round The round.
	 *
	 * @return bool
	 */
	private function isCancellable(array $round): bool {
		if (($round['lifecycle'] ?? '') !== 'booking-open') {
			return false;
		}

		$closes = strtotime((string)($round['bookingClosesAt'] ?? ''));

		return $closes === false || $closes > time();
	}//end isCancellable()

	/**
	 * The lifecycle move of a conference slot write, or null for anything
	 * else: another schema, no old version, or no change of state.
	 *
	 * @param ObjectEntity $new The object after the write.
	 * @param ObjectEntity|null $old The object before the write.
	 *
	 * @return array{0: string, 1: string, 2: array<string, mixed>}|null From, to and the new slot.
	 */
	private function move(ObjectEntity $new, ?ObjectEntity $old): ?array {
		if ($old === null) {
			return null;
		}

		try {
			if ($this->schemaResolver->guardSchemaSlug(entity: $new) !== self::SLOT_SCHEMA) {
				return null;
			}
		} catch (Throwable $exception) {
			return null;
		}

		$slot = $new->jsonSerialize();
		$from = (string)(($old->getObject() ?? [])['lifecycle'] ?? '');
		$to = (string)($slot['lifecycle'] ?? '');
		if ($from === $to) {
			return null;
		}

		return [$from, $to, $slot];
	}//end move()

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
