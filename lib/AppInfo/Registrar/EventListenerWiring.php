<?php

/**
 * Scholiq Event Listener Wiring
 *
 * The composition root for Scholiq's event-listener registrations. It owns
 * nothing itself: it names the domain-scoped registrars and the phase each one
 * belongs to, so `Application` depends on this one seam instead of on every
 * listener class in the app, and each registrar stays small enough to read in
 * one sitting.
 *
 * Two phases, deliberately kept apart:
 *   - `registerAll()` runs from `Application::register()` — plain, unfiltered
 *     `registerEventListener()` wiring.
 *   - `bootFilteredListeners()` runs from `Application::boot()` — the listeners
 *     that declare a register/schema interest up front, which can only be
 *     narrowed once every app's `register()` has completed. See
 *     {@see BootListenerRegistrar}.
 *
 * @category AppInfo
 * @package  OCA\Scholiq\AppInfo\Registrar
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Scholiq\AppInfo\Registrar;

use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Names every listener registrar and the bootstrap phase it belongs to.
 */
class EventListenerWiring {
	/**
	 * Run every register()-phase listener registrar.
	 *
	 * @param IRegistrationContext $context Nextcloud registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
	 */
	public function registerAll(IRegistrationContext $context): void {
		(new GradingListenerRegistrar())->register(context: $context);
		(new CaseListenerRegistrar())->register(context: $context);
		(new CollaborationListenerRegistrar())->register(context: $context);
		(new SchedulingListenerRegistrar())->register(context: $context);

	}//end registerAll()

	/**
	 * Subscribe every boot()-phase filtered object listener.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 * @param string $appId The Scholiq app id (log context only).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
	 */
	public function bootFilteredListeners(IEventDispatcher $dispatcher, string $appId): void {
		(new BootListenerRegistrar())->register(dispatcher: $dispatcher, appId: $appId);

	}//end bootFilteredListeners()
}//end class
