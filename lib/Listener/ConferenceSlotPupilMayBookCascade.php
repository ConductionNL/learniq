<?php

/**
 * Learniq conference slot pupil-may-book cascade
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A free time carries a copy of its round's `pupilMayBook`, so the pupil's
 * portal lists only the times she may book herself (studentConferenceTimes)
 * without reading the round. ConferenceFreeSlotGenerator writes the copy on
 * a new time; this listener follows a round whose setting changes later and
 * writes the new value onto every time of that round that differs.
 *
 * A failure is logged and never blocks the round's own update. The booking
 * guard reads the round itself, so a stale copy can only show a time, never
 * book one.
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
 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-may-book-her-own-mentor-talk-when-the-round-allows-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies a round's changed `pupilMayBook` onto its times.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-may-book-her-own-mentor-talk-when-the-round-allows-it
 */
class ConferenceSlotPupilMayBookCascade implements IEventListener {

	private const REGISTER = 'learniq';

	private const ROUND_SCHEMA = 'conference-round';

	private const SLOT_SCHEMA = 'conference-slot';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemas Entity schema id to slug.
	 * @param ObjectService                   $objects Reads and saves the times.
	 * @param LoggerInterface                 $logger  PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemas,
		private readonly ObjectService $objects,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Follow a round whose `pupilMayBook` changed.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-may-book-her-own-mentor-talk-when-the-round-allows-it
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		$new = $event->getNewObject();
		try {
			if ($this->schemas->guardSchemaSlug(entity: $new) !== self::ROUND_SCHEMA) {
				return;
			}
		} catch (Throwable $exception) {
			return;
		}

		$allowed = ((($new->getObject() ?? [])['pupilMayBook'] ?? false) === true);
		$before = ((($event->getOldObject()?->getObject() ?? [])['pupilMayBook'] ?? false) === true);
		$roundId = (string)($new->getUuid() ?? '');
		if ($allowed === $before || $roundId === '') {
			return;
		}

		try {
			$this->copyOntoTimes(roundId: $roundId, allowed: $allowed);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ConferenceSlotPupilMayBookCascade] The times of round {round} could not follow: {msg}',
				['round' => $roundId, 'msg' => $exception->getMessage()]
			);
		}
	}//end handle()

	/**
	 * Write the setting onto every time of the round that differs.
	 *
	 * @param string $roundId The round.
	 * @param bool   $allowed Whether pupils may book now.
	 *
	 * @return void
	 */
	private function copyOntoTimes(string $roundId, bool $allowed): void {
		$slots = $this->objects->findAll(
			['filters' => ['register' => self::REGISTER, 'schema' => self::SLOT_SCHEMA, 'conferenceRoundId' => $roundId]],
			_rbac: false,
			_multitenancy: false
		);
		foreach ($slots as $slot) {
			if (is_object($slot) === true && method_exists($slot, 'jsonSerialize') === true) {
				$slot = (array)$slot->jsonSerialize();
			}

			if (is_array($slot) === false || (string)($slot['conferenceRoundId'] ?? '') !== $roundId) {
				continue;
			}

			if ((($slot['pupilMayBook'] ?? false) === true) === $allowed) {
				continue;
			}

			$this->objects->saveObject(
				object: array_merge($slot, ['pupilMayBook' => $allowed]),
				register: self::REGISTER,
				schema: self::SLOT_SCHEMA,
				uuid: (string)($slot['id'] ?? ''),
				_rbac: false
			);
		}
	}//end copyOntoTimes()
}//end class
