<?php

/**
 * Learniq conference round booking mode stamp
 *
 * A new ConferenceRound without a `bookingMode` gets one: `direct` in a
 * primary school (segment `po`), where the parent picks a free time and the
 * teacher acknowledges it, and `preference` everywhere else, where parents
 * ask and the school plans (the flow secondary schools use).
 *
 * Only creates are stamped. A round stored before `bookingMode` existed keeps
 * no value and so keeps the preference flow it was created for
 * (ConferenceBookingMode reads a missing value as `preference`). A value the
 * creator chose is never changed.
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
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\SegmentService;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fills `bookingMode` on a new conference round.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
 */
class ConferenceRoundBookingModeStamp implements IEventListener {

	private const ROUND_SCHEMA = 'conference-round';

	private const PRIMARY_SEGMENT = 'po';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param SegmentService $segments The kind of school this instance runs as.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly SegmentService $segments,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stamp the booking mode on a round create that has none.
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
		try {
			if ($this->schemaResolver->guardSchemaSlug(entity: $entity) !== self::ROUND_SCHEMA) {
				return;
			}
		} catch (Throwable $exception) {
			return;
		}

		$payload = array_merge(($entity->getObject() ?? []), $event->getModifiedData());
		$chosen = ($payload['bookingMode'] ?? null);
		if (in_array($chosen, [ConferenceBookingMode::DIRECT, ConferenceBookingMode::PREFERENCE], true) === true) {
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), ['bookingMode' => $this->defaultMode()]));
	}//end handle()

	/**
	 * Direct booking in a primary school, preference booking elsewhere. A
	 * segment that cannot be read gives the old flow.
	 *
	 * @return string
	 */
	private function defaultMode(): string {
		try {
			if ($this->segments->currentSegment() === self::PRIMARY_SEGMENT) {
				return ConferenceBookingMode::DIRECT;
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ConferenceRoundBookingModeStamp] The segment could not be read; the round books by preference: {msg}',
				['msg' => $exception->getMessage()]
			);
		}

		return ConferenceBookingMode::PREFERENCE;
	}//end defaultMode()
}//end class
