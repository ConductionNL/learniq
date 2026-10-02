<?php

/**
 * Learniq Conference Listener Registrar
 *
 * Wires the listeners of direct conference booking
 * (direct-conference-booking): the booking mode a new round gets, the free
 * times a direct round's teachers publish, a parent's portal booking that
 * claims one, and what follows when a booked time is acknowledged, declined
 * or cancelled. The preference flow's listeners (ConferenceScheduleGenerator,
 * ConferenceSignupPortalStamp) stay where they were.
 *
 * @category AppInfo
 * @package  OCA\Learniq\AppInfo\Registrar
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

namespace OCA\Learniq\AppInfo\Registrar;

use OCA\Learniq\Listener\ConferenceFreeSlotGenerator;
use OCA\Learniq\Listener\ConferenceRoundBookingModeStamp;
use OCA\Learniq\Listener\ConferenceSlotBookingStamp;
use OCA\Learniq\Listener\ConferenceSlotBookingSync;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Wires the direct conference booking listeners.
 *
 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
 */
class ConferenceListenerRegistrar {
	/**
	 * Register the listeners.
	 *
	 * @param IRegistrationContext $context Nextcloud registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// A new round without a booking mode: direct in a primary school,
		// preference elsewhere. Old rounds keep no value and so the old flow.
		$context->registerEventListener(event: ObjectCreatingEvent::class, listener: ConferenceRoundBookingModeStamp::class);

		// On open-booking and create-free-slots, availability becomes free times.
		$context->registerEventListener(event: ObjectTransitionedEvent::class, listener: ConferenceFreeSlotGenerator::class);

		// A portal booking of a free time claims the slot under a lock.
		$context->registerEventListener(event: ObjectCreatingEvent::class, listener: ConferenceSlotBookingStamp::class);

		// A booked time acknowledged, declined or cancelled: the booking
		// follows, a late portal cancel is refused, a released time is free again.
		$context->registerEventListener(event: ObjectUpdatingEvent::class, listener: ConferenceSlotBookingSync::class);
		$context->registerEventListener(event: ObjectUpdatedEvent::class, listener: ConferenceSlotBookingSync::class);
	}//end register()
}//end class
