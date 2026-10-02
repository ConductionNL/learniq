<?php

/**
 * Learniq conference slot booking stamp
 *
 * A guardian books a free parent-teacher conversation time from the parent
 * portal (learniq's `parent` contribution action `bookConferenceSlot`). The
 * booking is a ConferenceSignup create naming the child (`learnerRef`) and
 * the free time (`slotId`); portaliq stamps the guardian's own learniq
 * reference into `guardianRef` and has already refused a child that is not
 * the guardian's (`crossRefs`).
 *
 * On a portal create that names a slot (no session user, a `guardianRef`,
 * a `learnerRef` and a `slotId`) this listener:
 * - refuses unless the child lists the guardian in `guardianRefs`;
 * - refuses unless the slot's round uses direct booking, is `booking-open`,
 *   invited the child, and its booking window has not closed;
 * - claims the slot through ConferenceSlotClaim, which under a lock checks
 *   that the slot is still free and may be booked for this child, and that
 *   the child has no more bookings in the round than it allows, and sets the
 *   slot to `booked` with the child and the guardian;
 * - stamps the signup with the round, the child's user id, the guardian's
 *   user id, the tenant, the teacher, the time and the lifecycle `booked`.
 *
 * A signup without a `slotId` is the preference flow and is left to
 * ConferenceSignupPortalStamp. Every other write is left alone.
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

use OCA\Learniq\Service\ConferenceBookingMode;
use OCA\Learniq\Service\ConferenceSlotClaim;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks a portal booking of a free time and claims the slot.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
 */
class ConferenceSlotBookingStamp implements IEventListener {

	private const REGISTER = 'learniq';

	private const SIGNUP_SCHEMA = 'conference-signup';

	private const SLOT_SCHEMA = 'conference-slot';

	private const ROUND_SCHEMA = 'conference-round';

	/**
	 * The refusals this listener can give, keyed by reason. The parent reads
	 * the message in the portal.
	 */
	public const REFUSALS = [
		'signup-guardian-unknown' => 'You can only book a conversation for your own child.',
		'slot-unknown' => 'This time cannot be booked for your child. Pick one of the free times.',
		'slot-round-closed' => 'Booking for this round is not open, or your child is not invited to it.',
		'slot-taken' => 'Someone else just booked this time. Pick another free time.',
		'child-already-booked' => 'Your child already has a conversation in this round. Cancel it first to pick another time.',
		'slot-lookup-failed' => 'The booking could not be checked. Try again later.',
	];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param LearnerRefResolver $profiles LearnerProfile by uuid.
	 * @param ObjectService $objectService Reads the slot's round.
	 * @param ConferenceSlotClaim $claim Takes the slot under a lock.
	 * @param IUserSession $userSession Tells a portal write (no session) from an app write.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LearnerRefResolver $profiles,
		private readonly ObjectService $objectService,
		private readonly ConferenceSlotClaim $claim,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check a portal booking, claim its slot and stamp the signup.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false || $event->isPropagationStopped() === true) {
			return;
		}

		$entity = $event->getObject();
		if ($this->isSignup(entity: $entity) === false) {
			return;
		}

		$payload = array_merge(($entity->getObject() ?? []), $event->getModifiedData());
		if ($this->isPortalBooking(payload: $payload) === false) {
			return;
		}

		try {
			$outcome = $this->outcomeFor(payload: $payload, signupId: self::signupId(entity: $entity));
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ConferenceSlotBookingStamp] Could not check a portal booking: {msg}',
				['msg' => $exception->getMessage()]
			);
			$outcome = ['refuse' => 'slot-lookup-failed'];
		}

		if (isset($outcome['refuse']) === true) {
			$event->setErrors(['reason' => $outcome['refuse'], 'message' => self::REFUSALS[$outcome['refuse']]]);
			$event->stopPropagation();
			$this->logger->info('[ConferenceSlotBookingStamp] Refused a portal booking: {reason}', ['reason' => $outcome['refuse']]);
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $outcome['stamp']));
	}//end handle()

	/**
	 * A portal booking of a free time: no session user, a guardianRef, a
	 * learnerRef and a slotId.
	 *
	 * @param array<string, mixed> $payload The signup being created.
	 *
	 * @return bool
	 */
	private function isPortalBooking(array $payload): bool {
		if ($this->userSession->getUser() !== null) {
			return false;
		}

		return self::text(value: ($payload['guardianRef'] ?? null)) !== ''
			&& self::text(value: ($payload['learnerRef'] ?? null)) !== ''
			&& self::text(value: ($payload['slotId'] ?? null)) !== '';
	}//end isPortalBooking()

	/**
	 * What to stamp on the signup, or why to refuse it.
	 *
	 * @param array<string, mixed> $payload The signup being created.
	 * @param string $signupId The signup's uuid.
	 *
	 * @return array{stamp?: array<string, mixed>, refuse?: string}
	 */
	private function outcomeFor(array $payload, string $signupId): array {
		$guardianRef = self::text(value: $payload['guardianRef']);
		$child = $this->profiles->byRef(learnerRef: self::text(value: $payload['learnerRef']));
		if ($child === null || in_array($guardianRef, (array)($child['guardianRefs'] ?? []), true) === false) {
			return ['refuse' => 'signup-guardian-unknown'];
		}

		$childRef = (string)$child['id'];
		$round = $this->roundOfSlot(slotId: self::text(value: $payload['slotId']));
		if ($round === null) {
			return ['refuse' => 'slot-unknown'];
		}

		if ($this->isBookable(round: $round, childRef: $childRef) === false) {
			return ['refuse' => 'slot-round-closed'];
		}

		$claimed = $this->claim->claim(
			slotId: self::text(value: $payload['slotId']),
			round: $round,
			booking: [
				'learnerRef' => $childRef,
				'learnerId' => (string)($child['ncUserId'] ?? ''),
				'guardianRef' => $guardianRef,
				'signupId' => $signupId,
			]
		);
		if (isset($claimed['slot']) === false) {
			return ['refuse' => ($claimed['refuse'] ?? 'slot-taken')];
		}

		$slot = $claimed['slot'];
		$guardian = $this->profiles->byRef(learnerRef: $guardianRef);

		return [
			'stamp' => [
				'conferenceRoundId' => (string)$round['id'],
				'learnerId' => (string)($child['ncUserId'] ?? ''),
				'learnerRef' => $childRef,
				'guardianId' => self::text(value: ($guardian['ncUserId'] ?? null)),
				'tenant_id' => self::text(value: ($round['tenant_id'] ?? null)),
				'requestedTeacherIds' => [(string)($slot['teacherId'] ?? '')],
				'teacherName' => self::text(value: ($slot['teacherName'] ?? null)),
				'startsAt' => self::text(value: ($slot['startsAt'] ?? null)),
				'endsAt' => self::text(value: ($slot['endsAt'] ?? null)),
				'slotLabel' => self::text(value: ($slot['slotLabel'] ?? null)),
				'lifecycle' => 'booked',
			],
		];
	}//end outcomeFor()

	/**
	 * Whether the round takes direct bookings for this child now: direct
	 * mode, `booking-open`, the child invited, and the window not closed.
	 *
	 * @param array<string, mixed> $round The round.
	 * @param string $childRef The child's LearnerProfile uuid.
	 *
	 * @return bool
	 */
	private function isBookable(array $round, string $childRef): bool {
		if ((new ConferenceBookingMode())->isDirect(round: $round) === false
			|| ($round['lifecycle'] ?? '') !== 'booking-open'
			|| in_array($childRef, (array)($round['invitedLearnerRefs'] ?? []), true) === false
		) {
			return false;
		}

		$closes = strtotime(self::text(value: ($round['bookingClosesAt'] ?? null)));

		return $closes === false || $closes > time();
	}//end isBookable()

	/**
	 * The round a slot belongs to. Only the round id is taken from this read;
	 * the slot's state is read again by the claim, under its lock.
	 *
	 * @param string $slotId The slot uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function roundOfSlot(string $slotId): ?array {
		$found = $this->objectService->find(id: $slotId, register: self::REGISTER, schema: self::SLOT_SCHEMA, _rbac: false, _multitenancy: false);
		if ($found === null) {
			return null;
		}

		$slot = $found->jsonSerialize();
		$roundId = self::text(value: ($slot['conferenceRoundId'] ?? null));
		if ($roundId === '') {
			return null;
		}

		$round = $this->objectService->find(id: $roundId, register: self::REGISTER, schema: self::ROUND_SCHEMA, _rbac: false, _multitenancy: false);
		if ($round === null) {
			return null;
		}

		return $round->jsonSerialize();
	}//end roundOfSlot()

	/**
	 * The signup's uuid, set now when the create has none yet, so the slot
	 * can point at the signup before it is stored.
	 *
	 * @param ObjectEntity $entity The signup being created.
	 *
	 * @return string
	 */
	private static function signupId(ObjectEntity $entity): string {
		$uuid = $entity->getUuid();
		if (is_string($uuid) === true && $uuid !== '') {
			return $uuid;
		}

		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		$uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
		$entity->setUuid($uuid);

		return $uuid;
	}//end signupId()

	/**
	 * Whether the entity is a ConferenceSignup.
	 *
	 * @param ObjectEntity $entity The object being written.
	 *
	 * @return bool
	 */
	private function isSignup(ObjectEntity $entity): bool {
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $entity) === self::SIGNUP_SCHEMA;
		} catch (Throwable $exception) {
			return false;
		}
	}//end isSignup()

	/**
	 * A string value, or '' for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end text()
}//end class
