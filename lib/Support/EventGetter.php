<?php

/**
 * EventGetter: a value read from another app's event by getter name.
 *
 * Events dispatched by integriq and the open-material sources are classes
 * learniq does not depend on, so their values are read by getter name: the
 * getter's answer when the event has that method, null when it has not.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\Learniq\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude shared event-reading helper: it carries the event reading of the callers' own specs and adds no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\Learniq\Support;

use OCP\EventDispatcher\Event;

/**
 * Reads one value from an event by getter name.
 *
 * @spec exclude shared event-reading helper: it carries the event reading of the callers' own specs and adds no behaviour of its own
 */
final class EventGetter {

	/**
	 * The getter's answer, or null when the event has no such getter.
	 *
	 * @param Event $event The event.
	 * @param string $getter The getter's method name.
	 *
	 * @return mixed
	 *
	 * @spec exclude shared event-reading helper: it carries the event reading of the callers' own specs and adds no behaviour of its own
	 */
	public function value(Event $event, string $getter): mixed {
		if (method_exists($event, $getter) === false) {
			return null;
		}

		return $event->$getter();
	}//end value()
}//end class
