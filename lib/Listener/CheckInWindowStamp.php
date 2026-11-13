<?php

/**
 * Learniq Check-In Window Stamp
 *
 * Decides, on the server, who opened a self check-in window, which school it
 * belongs to and, when the client leaves them out, its times.
 *
 * A create records the signed-in user as `openedBy` and the lesson's tenant as
 * `tenant_id`, whatever the client sent. A missing `opensAt` becomes now, a
 * missing `closesAt` fifteen minutes later, and a close time past the end of
 * the lesson becomes the end of the lesson. A missing `lateAfterMinutes`
 * becomes five. An update keeps the stored `openedBy` and `tenant_id`.
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
 * @spec openspec/specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Throwable;

/**
 * Stamps `openedBy`, `tenant_id` and the default times of a CheckInWindow.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson
 */
class CheckInWindowStamp implements IEventListener {

	private const REGISTER = 'learniq';

	private const SCHEMA = 'check-in-window';

	private const SESSION_SCHEMA = 'session';

	private const OPEN_MINUTES = 15;

	private const LATE_AFTER_MINUTES = 5;

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param ObjectService          $objectService  Reads the lesson.
	 * @param IUserSession           $userSession    The signed-in user.
	 * @param ITimeFactory           $timeFactory    The server's clock.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ObjectService $objectService,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $timeFactory,
	) {
	}//end __construct()

	/**
	 * Stamp a check-in window on create and keep its owner on update.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson
	 */
	public function handle(Event $event): void {
		if ($this->isWindowWrite(event: $event) === false) {
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			self::keepOwner(event: $event);
			return;
		}

		if ($event instanceof ObjectCreatingEvent === true) {
			$payload = array_merge(($event->getObject()->getObject() ?? []), $event->getModifiedData());
			$event->setModifiedData(array_merge($event->getModifiedData(), $this->createStamp(payload: $payload)));
		}
	}//end handle()

	/**
	 * Whether the event is a create or update of a check-in window that is still going ahead.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return bool
	 */
	private function isWindowWrite(Event $event): bool {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return false;
		}

		return $event->isPropagationStopped() === false && $this->isWindow(event: $event) === true;
	}//end isWindowWrite()

	/**
	 * On an update, keep the stored owner and tenant whatever the client sent.
	 *
	 * @param ObjectUpdatingEvent $event The update.
	 *
	 * @return void
	 */
	private static function keepOwner(ObjectUpdatingEvent $event): void {
		$old = ($event->getOldObject()?->getObject() ?? []);
		$event->setModifiedData(
			array_merge(
				$event->getModifiedData(),
				['openedBy' => ($old['openedBy'] ?? null), 'tenant_id' => ($old['tenant_id'] ?? null)]
			)
		);
	}//end keepOwner()

	/**
	 * What a new window stores for its owner, tenant and times.
	 *
	 * @param array<string, mixed> $payload The window being created.
	 *
	 * @return array<string, mixed>
	 */
	private function createStamp(array $payload): array {
		$session = $this->session(id: self::text(value: ($payload['sessionId'] ?? null)));
		$opens = $this->opensAt(payload: $payload);
		$closes = self::cappedAt(closes: $this->closesAt(payload: $payload, opens: $opens), ends: $this->time(value: ($session['endsAt'] ?? null)));

		return [
			'openedBy' => ($this->userSession->getUser()?->getUID() ?? null),
			'tenant_id' => ($session['tenant_id'] ?? null),
			'opensAt' => $opens->format(DateTimeInterface::ATOM),
			'closesAt' => $closes->format(DateTimeInterface::ATOM),
			'lateAfterMinutes' => self::lateAfter(payload: $payload),
		];
	}//end createStamp()

	/**
	 * When the window opens: the time it names, else now by the server's clock.
	 *
	 * @param array<string, mixed> $payload The window being created.
	 *
	 * @return DateTimeImmutable
	 */
	private function opensAt(array $payload): DateTimeImmutable {
		$opens = $this->time(value: ($payload['opensAt'] ?? null));
		if ($opens === null) {
			$opens = (new DateTimeImmutable())->setTimestamp($this->timeFactory->getTime());
		}

		return $opens;
	}//end opensAt()

	/**
	 * When the window closes: the time it names, else OPEN_MINUTES after it opens.
	 *
	 * @param array<string, mixed> $payload The window being created.
	 * @param DateTimeImmutable    $opens   When it opens.
	 *
	 * @return DateTimeImmutable
	 */
	private function closesAt(array $payload, DateTimeImmutable $opens): DateTimeImmutable {
		$closes = $this->time(value: ($payload['closesAt'] ?? null));
		if ($closes === null) {
			$closes = $opens->modify('+' . self::OPEN_MINUTES . ' minutes');
		}

		return $closes;
	}//end closesAt()

	/**
	 * The close time, never after the end of the lesson.
	 *
	 * @param DateTimeImmutable      $closes The close time.
	 * @param DateTimeImmutable|null $ends   The lesson's end, or null.
	 *
	 * @return DateTimeImmutable
	 */
	private static function cappedAt(DateTimeImmutable $closes, ?DateTimeImmutable $ends): DateTimeImmutable {
		if ($ends !== null && $ends < $closes) {
			return $ends;
		}

		return $closes;
	}//end cappedAt()

	/**
	 * The minutes after opening a check-in counts as late: the window's own
	 * whole, non-negative number, else LATE_AFTER_MINUTES.
	 *
	 * @param array<string, mixed> $payload The window being created.
	 *
	 * @return int
	 */
	private static function lateAfter(array $payload): int {
		$late = ($payload['lateAfterMinutes'] ?? null);
		if (is_int($late) === false || $late < 0) {
			return self::LATE_AFTER_MINUTES;
		}

		return $late;
	}//end lateAfter()

	/**
	 * The lesson the window is for, or an empty array.
	 *
	 * @param string $id The Session uuid.
	 *
	 * @return array<string, mixed>
	 */
	private function session(string $id): array {
		if ($id === '') {
			return [];
		}

		try {
			$session = $this->objectService->find(
				id: $id,
				register: self::REGISTER,
				schema: self::SESSION_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			return [];
		}

		return ($session?->jsonSerialize() ?? []);
	}//end session()

	/**
	 * A date-time string as a moment, or null when it is not one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function time(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $exception) {
			return null;
		}
	}//end time()

	/**
	 * Whether the write is a CheckInWindow. Not knowing the schema is not
	 * knowing it is ours, so another app's writes are never touched.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 *
	 * @return bool
	 */
	private function isWindow(ObjectCreatingEvent|ObjectUpdatingEvent $event): bool {
		$entity = $this->entityOf(event: $event);
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $entity) === self::SCHEMA;
		} catch (Throwable $exception) {
			return false;
		}
	}//end isWindow()

	/**
	 * The object being written: an update carries it in getNewObject(), only
	 * a create has getObject().
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
