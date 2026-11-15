<?php

/**
 * Learniq Session Move Notice Handler
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Listens for OpenRegister's ObjectUpdatedEvent on a learniq Session. When one
 * lesson moves to another room (`roomId` or `location`) or another time
 * (`startsAt`) outside a change batch, it writes the lesson's affected people
 * (`affectedLearnerIds` from the cohort, `affectedParentIds` from their
 * profiles), a `moveNotice` naming the lesson and its new room or time, and a
 * server-stamped `movedAt`. The Session's declared `lessonMoved` notification
 * fires on `movedAt` changing, so the message reaches those people.
 *
 * A lesson changed by a batch (the update that sets or restores
 * `changeBatchId`) sends nothing here: the batch sends one message for all
 * its lessons. This handler's own write leaves room and time as they are, so
 * it never triggers itself.
 *
 * ADR-031 legitimate exception: a cross-object write bridge (cohort learners
 * and their parents onto the Session) the notification's `kind: field`
 * recipients read, the same shape as SessionChangeNoticeHandler.
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
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/timetabling/spec.md#requirement-a-lesson-moved-to-another-room-or-time-notifies-the-affected-learners-and-parents
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Kernel\Contracts\SessionChangeNoticeHandlerInterface;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Notifies a single lesson's learners and parents of its new room or time.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/timetabling/spec.md#requirement-a-lesson-moved-to-another-room-or-time-notifies-the-affected-learners-and-parents
 */
class SessionMoveNoticeHandler implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const SESSION_SCHEMA = 'session';
	private const ROOM_SCHEMA = 'room';

	/**
	 * Constructor.
	 *
	 * @param ObjectService                       $objectService OpenRegister object access.
	 * @param SessionChangeNoticeHandlerInterface $notices       Who a change to a lesson affects.
	 * @param ListenerSchemaResolverInterface     $schemas       Resolves the entity's register and schema slugs.
	 * @param LoggerInterface                     $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly SessionChangeNoticeHandlerInterface $notices,
		private readonly ListenerSchemaResolverInterface $schemas,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectUpdatedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/timetabling/spec.md#scenario-a-single-lesson-moves-to-another-room
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/timetabling/spec.md#scenario-a-lesson-moves-to-another-time
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false || $event->getOldObject() === null) {
			return;
		}

		$entity = $event->getNewObject();
		if ($this->schemas->registerSlug(entity: $entity) !== self::LEARNIQ_REGISTER
			|| $this->schemas->schemaSlug(entity: $entity) !== self::SESSION_SCHEMA
		) {
			return;
		}

		$new = $entity->jsonSerialize();
		$old = $event->getOldObject()->jsonSerialize();
		$move = $this->moveOf(old: $old, new: $new);
		if ($move === null) {
			return;
		}

		try {
			$this->writeNotice(session: $new, move: $move);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[SessionMoveNoticeHandler] Could not write the move notice of Session {id}: {msg}',
				['id' => ($new['id'] ?? ''), 'msg' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * What moved: 'room', 'time' or 'room-and-time'; null when neither moved,
	 * the lesson is not scheduled, or the update belongs to a batch.
	 *
	 * @param array<string,mixed> $old The Session before the update.
	 * @param array<string,mixed> $new The Session after the update.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/timetabling/spec.md#requirement-a-lesson-moved-to-another-room-or-time-notifies-the-affected-learners-and-parents
	 */
	public function moveOf(array $old, array $new): ?string {
		if (($new['changeBatchId'] ?? null) !== ($old['changeBatchId'] ?? null)) {
			return null;
		}

		if (($new['lifecycle'] ?? 'scheduled') === 'cancelled') {
			return null;
		}

		$roomMoved = ($old['roomId'] ?? null) !== ($new['roomId'] ?? null)
			|| ($old['location'] ?? null) !== ($new['location'] ?? null);
		$timeMoved = $this->sameInstant(left: ($old['startsAt'] ?? null), right: ($new['startsAt'] ?? null)) === false;

		if ($roomMoved === true && $timeMoved === true) {
			return 'room-and-time';
		}

		if ($roomMoved === true) {
			return 'room';
		}

		if ($timeMoved === true) {
			return 'time';
		}

		return null;
	}//end moveOf()

	/**
	 * The notice text: the lesson and its new room and/or time.
	 *
	 * @param array<string,mixed> $session The Session after the update.
	 * @param string              $move    What moved.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/timetabling/spec.md#scenario-a-single-lesson-moves-to-another-room
	 */
	public function noticeText(array $session, string $move): string {
		$parts = [];
		$title = trim((string)($session['title'] ?? ''));
		if ($title !== '') {
			$parts[] = $title;
		}

		$time = $this->displayTime(value: ($session['startsAt'] ?? null));
		if ($time !== '') {
			$parts[] = $time;
		}

		$room = $this->roomName(session: $session);
		if ($move !== 'time' && $room !== '') {
			$parts[] = $room;
		}

		return implode(', ', $parts);
	}//end noticeText()

	/**
	 * Write the affected people, the notice and movedAt onto the lesson.
	 *
	 * @param array<string,mixed> $session The Session after the update.
	 * @param string              $move    What moved.
	 *
	 * @return void
	 */
	private function writeNotice(array $session, string $move): void {
		$people = $this->notices->affectedPeople(session: $session);

		$session['affectedLearnerIds'] = $people['learnerIds'];
		$session['affectedParentIds'] = $people['parentIds'];
		$session['moveNotice'] = $this->noticeText(session: $session, move: $move);
		$session['movedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::SESSION_SCHEMA,
			object: $session
		);

		$this->logger->info(
			'[SessionMoveNoticeHandler] Session {id} moved ({move}): {l} learner(s), {p} parent(s) notified.',
			['id' => ($session['id'] ?? ''), 'move' => $move, 'l' => count($people['learnerIds']), 'p' => count($people['parentIds'])]
		);
	}//end writeNotice()

	/**
	 * The room's name (else code), else the session's location label, else ''.
	 *
	 * @param array<string,mixed> $session The Session.
	 *
	 * @return string
	 */
	private function roomName(array $session): string {
		$roomId = $session['roomId'] ?? null;
		if (is_string($roomId) === true && $roomId !== '') {
			$room = $this->objectService->find(id: $roomId, register: self::LEARNIQ_REGISTER, schema: self::ROOM_SCHEMA);
			if ($room !== null) {
				$data = $room->jsonSerialize();
				foreach (['name', 'code'] as $field) {
					if (is_string($data[$field] ?? null) === true && trim($data[$field]) !== '') {
						return trim($data[$field]);
					}
				}
			}
		}

		$location = $session['location'] ?? null;
		if (is_string($location) === true) {
			return trim($location);
		}

		return '';
	}//end roomName()

	/**
	 * A start time as "dd-mm-yyyy HH:MM" in its own offset, or '' when unreadable.
	 *
	 * @param mixed $value The ISO 8601 time.
	 *
	 * @return string
	 */
	private function displayTime($value): string {
		$time = $this->instant(value: $value);
		if ($time === null) {
			return '';
		}

		return $time->format('d-m-Y H:i');
	}//end displayTime()

	/**
	 * Whether two ISO 8601 times are the same moment (both unreadable counts as the same).
	 *
	 * @param mixed $left  One time.
	 * @param mixed $right The other.
	 *
	 * @return bool
	 */
	private function sameInstant($left, $right): bool {
		$a = $this->instant(value: $left);
		$b = $this->instant(value: $right);
		if ($a === null || $b === null) {
			return $a === $b;
		}

		return $a->getTimestamp() === $b->getTimestamp();
	}//end sameInstant()

	/**
	 * An ISO 8601 time, or null.
	 *
	 * @param mixed $value The time.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function instant($value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Exception) {
			return null;
		}
	}//end instant()
}//end class
