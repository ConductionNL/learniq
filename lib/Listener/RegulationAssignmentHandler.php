<?php

/**
 * Learniq Regulation Assignment Handler
 *
 * Listens for OpenRegister's ObjectTransitionedEvent and, when a Regulation is
 * published, assigns its mandatory courses to the learners its audience scope
 * covers (learniq#951) through RegulationAssignmentService.
 *
 * ADR-031 legitimate exception: cross-object write bridge.
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
 * @spec openspec/parity/capabilities.json#comp-assign-from-hr-roles
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\RegulationAssignmentService;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Assigns a regulation's mandatory courses to its audience on publish.
 *
 * @implements IEventListener<Event>
 */
class RegulationAssignmentHandler implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param RegulationAssignmentService $assignment Assignment logic.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegulationAssignmentService $assignment,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/parity/capabilities.json#comp-assign-from-hr-roles
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		if ($event->getRegister() !== 'learniq'
			|| $event->getSchema() !== 'regulation'
			|| $event->getTo() !== 'published'
		) {
			return;
		}

		$this->assignment->assign(regulation: $event->getObject()->jsonSerialize());

	}//end handle()
}//end class
