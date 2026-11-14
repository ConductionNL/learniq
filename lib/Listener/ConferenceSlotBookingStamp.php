<?php

/**
 * Learniq conference slot booking stamp
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 *   user id, the tenant, the teacher, the time, who booked it and when, and
 *   the lifecycle `booked`.
 *
 * A pupil's own booking (pupil-books-support-lessons-and-mentor-talks)
 * carries her `learnerRef` and no `guardianRef`, and may arrive with her own
 * school-account session; it is taken only in a round with `pupilMayBook`.
 * Any other session user is an app write and left alone.
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
use OCA\Learniq\Kernel\Contracts\ConferenceSlotClaimInterface;
use OCA\Learniq\Kernel\Contracts\LearnerRefResolverInterface;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Service\ConferenceBookingMode;
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
		'signup-learner-unknown' => 'You can only book a conversation for yourself.',
		'slot-pupil-not-allowed' => 'You cannot book a time in this round yourself. Your parent books it.',
	];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Entity schema id to slug.
	 * @param LearnerRefResolverInterface $profiles LearnerProfile by uuid.
	 * @param ObjectService $objectService Reads the slot's round.
	 * @param ConferenceSlotClaimInterface $claim Takes the slot under a lock.
	 * @param IUserSession $userSession Tells a portal write (no session) from an app write.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LearnerRefResolverInterface $profiles,
		private readonly ObjectService $objectService,
		private readonly ConferenceSlotClaimInterface $claim,
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
		if ($this->isPortalBooking(payload: $payload) === true) {
			$this->stampOrRefuse(event: $event, payload: $payload);
		}
	}//end handle()

	/**
	 * Stamp a checked portal booking, or refuse it with its reason.
	 *
	 * @param ObjectCreatingEvent  $event   The create of the signup.
	 * @param array<string, mixed> $payload The signup being created.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
	 */
	private function stampOrRefuse(ObjectCreatingEvent $event, array $payload): void {
		$outcome = $this->checkedOutcome(payload: $payload, entity: $event->getObject());
		if (isset($outcome['refuse']) === true) {
			$event->setErrors(['reason' => $outcome['refuse'], 'message' => self::REFUSALS[$outcome['refuse']]]);
			$event->stopPropagation();
			$this->logger->info('[ConferenceSlotBookingStamp] Refused a portal booking: {reason}', ['reason' => $outcome['refuse']]);
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $outcome['stamp']));
	}//end stampOrRefuse()

	/**
	 * The booking's outcome, or `slot-lookup-failed` (logged) when a lookup throws.
	 *
	 * @param array<string, mixed> $payload The signup being created.
	 * @param ObjectEntity         $entity  The signup entity.
	 *
	 * @return array{stamp?: array<string, mixed>, refuse?: string}
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
	 */
	private function checkedOutcome(array $payload, ObjectEntity $entity): array {
		try {
			return $this->outcomeFor(payload: $payload, signupId: self::signupId(entity: $entity));
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ConferenceSlotBookingStamp] Could not check a portal booking: {msg}',
				['msg' => $exception->getMessage()]
			);
			return ['refuse' => 'slot-lookup-failed'];
		}
	}//end checkedOutcome()

	/**
	 * A portal booking of a free time: no session user, a guardianRef, a
	 * learnerRef and a slotId.
	 *
	 * @param array<string, mixed> $payload The signup being created.
	 *
	 * @return bool
	 */
	private function isPortalBooking(array $payload): bool {
		$learnerRef = self::text(value: ($payload['learnerRef'] ?? null));
		if ($learnerRef === '' || self::text(value: ($payload['slotId'] ?? null)) === '') {
			return false;
		}

		// A guardian's booking carries the guardianRef portaliq stamps from
		// her claim; a pupil's own booking carries her learnerRef and none
		// (pupil-books-support-lessons-and-mentor-talks).
		$user = $this->userSession->getUser();
		if ($user === null) {
			return true;
		}

		// A pupil signs in to her portal with her school account, so her own
		// booking can arrive with her session. Only her own counts: anybody
		// else's session is an app write, left to the app's own rules.
		if (self::text(value: ($payload['guardianRef'] ?? null)) !== '') {
			return false;
		}

		$learner = $this->profiles->byRef(learnerRef: $learnerRef);

		return $learner !== null && self::text(value: ($learner['ncUserId'] ?? null)) === $user->getUID();
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
		$guardianRef = self::text(value: ($payload['guardianRef'] ?? null));
		$child = $this->profiles->byRef(learnerRef: self::text(value: $payload['learnerRef']));
		if ($guardianRef === '' && $child === null) {
			return ['refuse' => 'signup-learner-unknown'];
		}

		if ($guardianRef !== '' && ($child === null || in_array($guardianRef, (array)($child['guardianRefs'] ?? []), true) === false)) {
			return ['refuse' => 'signup-guardian-unknown'];
		}

		$booking = [
			'learnerRef' => (string)$child['id'],
			'learnerId' => (string)($child['ncUserId'] ?? ''),
			'guardianRef' => $guardianRef,
			'signupId' => $signupId,
		];

		return $this->bookSlot(slotId: self::text(value: $payload['slotId']), booking: $booking);
	}//end outcomeFor()

	/**
	 * Claim the slot for the booking when its round takes it, and say what to stamp.
	 *
	 * @param string               $slotId  The slot uuid.
	 * @param array<string, mixed> $booking The child, its NC user, the guardian and the signup.
	 *
	 * @return array{stamp?: array<string, mixed>, refuse?: string}
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
	 */
	private function bookSlot(string $slotId, array $booking): array {
		$round = $this->roundOfSlot(slotId: $slotId);
		$refusal = $this->roundRefusal(round: $round, childRef: $booking['learnerRef'], byPupil: $booking['guardianRef'] === '');
		if ($refusal !== null) {
			return ['refuse' => $refusal];
		}

		$claimed = $this->claim->claim(slotId: $slotId, round: $round, booking: $booking);
		if (isset($claimed['slot']) === false) {
			return ['refuse' => ($claimed['refuse'] ?? 'slot-taken')];
		}

		return ['stamp' => array_merge($this->bookingStamp(round: $round, booking: $booking), $this->slotStamp(slot: $claimed['slot']))];
	}//end bookSlot()

	/**
	 * Why the slot's round does not take this child's booking, or null when it does.
	 *
	 * @param array<string, mixed>|null $round    The slot's round, or null when unknown.
	 * @param string                    $childRef The child's LearnerProfile uuid.
	 * @param bool                      $byPupil  Whether the learner books her own time.
	 *
	 * @return string|null The refusal reason, or null.
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
	 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/parent-conferences/spec.md#requirement-a-conference-round-says-whether-pupils-may-book
	 */
	private function roundRefusal(?array $round, string $childRef, bool $byPupil=false): ?string {
		if ($round === null) {
			return 'slot-unknown';
		}

		// A pupil books her own talk only in a round that says pupils may.
		if ($byPupil === true && ($round['pupilMayBook'] ?? false) !== true) {
			return 'slot-pupil-not-allowed';
		}

		if ($this->isBookable(round: $round, childRef: $childRef) === false) {
			return 'slot-round-closed';
		}

		return null;
	}//end roundRefusal()

	/**
	 * The round, child and guardian half of the stamp.
	 *
	 * @param array<string, mixed> $round   The round.
	 * @param array<string, mixed> $booking The claimed booking.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
	 */
	private function bookingStamp(array $round, array $booking): array {
		$guardian = $this->profiles->byRef(learnerRef: $booking['guardianRef']);
		$bookedBy = 'guardian';
		if ($booking['guardianRef'] === '') {
			$bookedBy = 'pupil';
		}

		return [
			'conferenceRoundId' => (string)$round['id'],
			'learnerId' => $booking['learnerId'],
			'learnerRef' => $booking['learnerRef'],
			'guardianId' => self::text(value: ($guardian['ncUserId'] ?? null)),
			'tenant_id' => self::text(value: ($round['tenant_id'] ?? null)),
			// Who booked it, for the guardian's notice of a pupil's own booking.
			'bookedBy' => $bookedBy,
			'bookedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];
	}//end bookingStamp()

	/**
	 * The time half of the stamp, from the claimed slot, and `booked`.
	 *
	 * @param array<string, mixed> $slot The claimed slot.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
	 */
	private function slotStamp(array $slot): array {
		return [
			'requestedTeacherIds' => [(string)($slot['teacherId'] ?? '')],
			'teacherName' => self::text(value: ($slot['teacherName'] ?? null)),
			'startsAt' => self::text(value: ($slot['startsAt'] ?? null)),
			'endsAt' => self::text(value: ($slot['endsAt'] ?? null)),
			'slotLabel' => self::text(value: ($slot['slotLabel'] ?? null)),
			'lifecycle' => 'booked',
		];
	}//end slotStamp()

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
