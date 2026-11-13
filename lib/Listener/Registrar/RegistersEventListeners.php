<?php

/**
 * Learniq event listener table registration.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener\Registrar
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

namespace OCA\Learniq\Listener\Registrar;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers a table of event and listener class pairs on the registration context.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
 */
trait RegistersEventListeners {

	/**
	 * Register each event and listener pair, in order.
	 *
	 * @param IRegistrationContext           $context   Nextcloud registration context.
	 * @param array<int,array{0:string,1:string}> $listeners Event class and listener class pairs.
	 *
	 * @return void
	 */
	private function registerEventListeners(IRegistrationContext $context, array $listeners): void {
		foreach ($listeners as [$event, $listener]) {
			$context->registerEventListener(event: $event, listener: $listener);
		}
	}//end registerEventListeners()
}//end trait
