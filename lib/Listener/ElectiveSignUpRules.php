<?php

/**
 * Learniq Elective Sign-Up Rules
 *
 * The rules of an optional lesson sign-up, enforced on every create and
 * update of `elective-sign-up` whoever writes it: the learner's endpoint, a
 * coordinator's screen or another system through OpenRegister's object API.
 * OpenRegister runs no lifecycle guard on create, so this is a pre-write
 * veto listener, the pattern EnrolmentPrerequisiteListener set.
 *
 * 1. The lesson is one of the offer's lessons and the offer is open.
 * 2. The learner is in one of the offer's eligible groups, when it names any.
 * 3. Inside the window, unless staff place a learner (`placed`).
 * 4. The lesson's places are not all taken, for every caller.
 * 5. One sign-up per learner per lesson.
 *
 * A withdrawal is allowed for the learner inside the window and for staff and
 * integrations until the lesson starts. The listener stamps `madeBy` and
 * `madeVia` on create from the caller's groups. A write with no user (the
 * system itself) or by an admin (break-glass, and how example sets and demo
 * data are imported) is not checked.
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
 * @spec openspec/specs/enrolment/spec.md#requirement-every-sign-up-obeys-the-same-rules-whoever-writes-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use OCA\Learniq\Service\ElectiveService;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Eligibility, window, capacity and one sign-up per lesson, for every writer.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/enrolment/spec.md#requirement-every-sign-up-obeys-the-same-rules-whoever-writes-it
 */
class ElectiveSignUpRules implements IEventListener {

	public const FULL = 'This lesson is full.';
	public const CLOSED = 'Sign-up for this lesson is closed.';
	public const NOT_ELIGIBLE = 'This learner is not in one of the groups this offer is for.';
	public const DOUBLE = 'This learner is already signed up for this lesson.';
	public const NOT_IN_OFFER = 'This lesson is not part of the offer.';
	public const OFFER_NOT_OPEN = 'This offer is not open for sign-up.';
	public const ONLY_STAFF_PLACE = 'Only staff can place a learner.';
	public const OWN_NAME = 'You can only sign up or withdraw yourself.';
	public const STARTED = 'This lesson has already started.';
	public const UNCHECKED = 'This sign-up could not be checked. Try again later.';

	/**
	 * Constructor.
	 *
	 * @param ElectiveService        $electives      Offers, lessons and places.
	 * @param ListenerSchemaResolver $schemaResolver Which schema an event is about.
	 * @param IUserSession           $userSession    The writer.
	 * @param IGroupManager          $groupManager   The writer's groups.
	 * @param LoggerInterface        $logger         Logger.
	 */
	public function __construct(
		private readonly ElectiveService $electives,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check a sign-up before it is written.
	 *
	 * @param Event $event The creating or updating event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-every-sign-up-obeys-the-same-rules-whoever-writes-it
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$entity = $this->signUpEntity(event: $event);
		$user = $this->userSession->getUser();
		if ($entity === null || $user === null) {
			return;
		}

		$caller = $this->electives->caller(uid: $user->getUID(), groups: $this->groupManager->getUserGroupIds($user));
		$signUp = (array)$entity->jsonSerialize();
		$signUp['id'] = (string)($signUp['id'] ?? ($entity->getUuid() ?? ''));

		try {
			$refusal = $this->refusal(signUp: $signUp, caller: $caller, now: new DateTimeImmutable('now'));
		} catch (Throwable $exception) {
			$this->logger->warning('[ElectiveSignUpRules] Check failed: {msg}', ['msg' => $exception->getMessage()]);
			$refusal = self::UNCHECKED;
		}

		if ($refusal !== null) {
			$event->setErrors(['message' => $refusal]);
			$event->stopPropagation();
			return;
		}

		if ($event instanceof ObjectCreatingEvent) {
			$event->setModifiedData(['madeBy' => $caller['uid'], 'madeVia' => $caller['via']]);
		}
	}//end handle()

	/**
	 * The sign-up an event is about, or null for any other object.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The event.
	 *
	 * @return object|null The entity.
	 */
	private function signUpEntity(ObjectCreatingEvent|ObjectUpdatingEvent $event): ?object {
		$entity = $this->entityOf(event: $event);
		if ($this->schemaResolver->registerSlug(entity: $entity) !== ElectiveService::REGISTER
			|| $this->schemaResolver->schemaSlug(entity: $entity) !== ElectiveService::SIGN_UP_SCHEMA
		) {
			return null;
		}

		return $entity;
	}//end signUpEntity()

	/**
	 * The object an event carries: the new object of an update.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The event.
	 *
	 * @return object
	 */
	private function entityOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): object {
		if ($event instanceof ObjectUpdatingEvent) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end entityOf()

	/**
	 * Why a sign-up may not be written, or null when it may.
	 *
	 * @param array<string, mixed>                                                     $signUp The sign-up as it would be written.
	 * @param array{uid: string, admin: bool, staff: bool, integration: bool, via: string} $caller The writer.
	 * @param DateTimeImmutable                                                        $now    Now.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-every-sign-up-obeys-the-same-rules-whoever-writes-it
	 */
	public function refusal(array $signUp, array $caller, DateTimeImmutable $now): ?string {
		// Admins may always write (break-glass, and how an example set or
		// demo data is imported); the rules are for everyone else.
		if ($caller['admin'] === true) {
			return null;
		}

		$offer = $this->electives->offer(offerId: (string)($signUp['offerId'] ?? ''));
		$key = $this->electives->lessonKey(row: $signUp);
		$lesson = $this->lessonOf(offer: $offer, key: $key);
		if ($offer === null || $lesson === null) {
			return self::NOT_IN_OFFER;
		}

		$learnerId = (string)($signUp['learnerId'] ?? '');
		if ($caller['staff'] === false && $caller['integration'] === false && $learnerId !== $caller['uid']) {
			return self::OWN_NAME;
		}

		$window = $this->electives->window(offer: $offer, startsAt: $lesson['startsAt']);
		if (($signUp['status'] ?? 'signed-up') === ElectiveService::WITHDRAWN) {
			return $this->withdrawalRefusal(lessonStart: $lesson['startsAt'], window: $window, caller: $caller, now: $now);
		}

		$entry = $this->entryRefusal(offer: $offer, signUp: $signUp, window: $window, caller: $caller, now: $now);
		if ($entry !== null) {
			return $entry;
		}

		return $this->placesRefusal(offer: $offer, signUp: $signUp, key: $key);
	}//end refusal()

	/**
	 * The offer's lesson with this key, or null.
	 *
	 * @param array<string, mixed>|null $offer The offer.
	 * @param string                    $key   The lesson key.
	 *
	 * @return array<string, mixed>|null
	 */
	private function lessonOf(?array $offer, string $key): ?array {
		if ($offer === null) {
			return null;
		}

		return ($this->electives->lessons(offer: $offer)[$key] ?? null);
	}//end lessonOf()

	/**
	 * Status, offer state, eligibility and window.
	 *
	 * @param array<string, mixed>                                                     $offer  The offer.
	 * @param array<string, mixed>                                                     $signUp The sign-up.
	 * @param array{opensAt: ?DateTimeImmutable, closesAt: ?DateTimeImmutable}           $window The lesson's window.
	 * @param array{uid: string, admin: bool, staff: bool, integration: bool, via: string} $caller The writer.
	 * @param DateTimeImmutable                                                        $now    Now.
	 *
	 * @return string|null
	 */
	private function entryRefusal(array $offer, array $signUp, array $window, array $caller, DateTimeImmutable $now): ?string {
		$placing = (($signUp['status'] ?? 'signed-up') === 'placed');
		if ($placing === true && $caller['staff'] === false) {
			return self::ONLY_STAFF_PLACE;
		}

		if (($offer['lifecycle'] ?? 'draft') !== 'open') {
			return self::OFFER_NOT_OPEN;
		}

		$eligible = $this->electives->eligibleLearners(offer: $offer);
		if ($eligible !== null && in_array((string)($signUp['learnerId'] ?? ''), $eligible, true) === false) {
			return self::NOT_ELIGIBLE;
		}

		return $this->windowRefusal(mayIgnoreWindow: $placing, window: $window, now: $now);
	}//end entryRefusal()

	/**
	 * The window, unless the writer may ignore it.
	 *
	 * @param bool                                                           $mayIgnoreWindow Whether staff place a learner.
	 * @param array{opensAt: ?DateTimeImmutable, closesAt: ?DateTimeImmutable} $window          The lesson's window.
	 * @param DateTimeImmutable                                              $now             Now.
	 *
	 * @return string|null
	 */
	private function windowRefusal(bool $mayIgnoreWindow, array $window, DateTimeImmutable $now): ?string {
		if ($mayIgnoreWindow === true || $this->electives->isOpen(window: $window, now: $now) === true) {
			return null;
		}

		return self::CLOSED;
	}//end windowRefusal()

	/**
	 * One sign-up per learner per lesson, and never more than the places.
	 *
	 * @param array<string, mixed> $offer  The offer.
	 * @param array<string, mixed> $signUp The sign-up.
	 * @param string               $key    The lesson key.
	 *
	 * @return string|null
	 */
	private function placesRefusal(array $offer, array $signUp, string $key): ?string {
		$others = array_values(
			array_filter(
				$this->electives->activeSignUps(offerId: (string)$signUp['offerId'], key: $key),
				static fn (array $row): bool => (string)($row['id'] ?? '') !== $signUp['id'] || $signUp['id'] === ''
			)
		);

		foreach ($others as $row) {
			if ((string)($row['learnerId'] ?? '') === (string)($signUp['learnerId'] ?? '')) {
				return self::DOUBLE;
			}
		}

		if (count($others) >= (int)($offer['capacityPerLesson'] ?? 0)) {
			return self::FULL;
		}

		return null;
	}//end placesRefusal()

	/**
	 * A learner withdraws inside the window; staff and integrations until the lesson starts.
	 *
	 * @param string                                                                   $lessonStart The lesson's start.
	 * @param array{opensAt: ?DateTimeImmutable, closesAt: ?DateTimeImmutable}           $window      The lesson's window.
	 * @param array{uid: string, admin: bool, staff: bool, integration: bool, via: string} $caller      The writer.
	 * @param DateTimeImmutable                                                        $now         Now.
	 *
	 * @return string|null
	 */
	private function withdrawalRefusal(string $lessonStart, array $window, array $caller, DateTimeImmutable $now): ?string {
		$start = strtotime($lessonStart);
		if ($start !== false && $now->getTimestamp() >= $start) {
			return self::STARTED;
		}

		if ($caller['staff'] === false && $caller['integration'] === false && $this->electives->isOpen(window: $window, now: $now) === false) {
			return self::CLOSED;
		}

		return null;
	}//end withdrawalRefusal()
}//end class
