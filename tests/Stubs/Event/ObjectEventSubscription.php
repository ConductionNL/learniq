<?php

/**
 * Test stub for OCA\OpenRegister\Event\ObjectEventSubscription.
 *
 * Mirrors the part of the real class (openregister development d611a36,
 * lib/Event/ObjectEventSubscription.php) that BootListenerRegistrar calls and
 * that RegisteredListenersHandleRealEventsTest reads back: `subscribe()` with
 * the real named parameters, `subscribedListeners()` and the `reset()` test
 * seam. Without it, a standalone run takes BootListenerRegistrar's fallback,
 * which asks \OCP\Server for a logger that does not exist outside Nextcloud,
 * so the boot-phase listeners could not be walked at all. Where OpenRegister
 * is loaded (CI), the real class wins and this file is never read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\IEventDispatcher;

/**
 * Stub for ObjectEventSubscription.
 */
final class ObjectEventSubscription {

	/**
	 * Recorded listeners, keyed by event class.
	 *
	 * @var array<string, array<int, string>>
	 */
	private static array $subscriptions = [];

	/**
	 * Events whose shared proxy is already on the dispatcher.
	 *
	 * @var array<string, bool>
	 */
	private static array $proxied = [];

	/**
	 * Record a boot-phase listener and put the shared proxy on the dispatcher once per event.
	 *
	 * @param IEventDispatcher       $dispatcher The live event dispatcher.
	 * @param string                 $event      Event class name.
	 * @param string                 $listener   Listener class name.
	 * @param array<int,string>|null $registers  Register slugs the listener reacts to.
	 * @param array<int,string>|null $schemas    Schema slugs the listener reacts to.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) the real class normalises and stores the filters.
	 */
	public static function subscribe(
		IEventDispatcher $dispatcher,
		string $event,
		string $listener,
		?array $registers = null,
		?array $schemas = null,
	): void {
		if (isset(self::$proxied[$event]) === false) {
			$dispatcher->addServiceListener($event, 'OCA\\OpenRegister\\Listener\\ObjectEventProxyListener');
			self::$proxied[$event] = true;
		}

		self::$subscriptions[$event][] = $listener;
	}//end subscribe()

	/**
	 * Every recorded listener, keyed by event class.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function subscribedListeners(): array {
		return self::$subscriptions;
	}//end subscribedListeners()

	/**
	 * Drop all declarations. Test seam only.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$subscriptions = [];
		self::$proxied = [];
	}//end reset()
}//end class
