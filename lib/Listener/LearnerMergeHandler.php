<?php

/**
 * Learniq Learner Merge Handler
 *
 * Listens for OpenRegister's ObjectTransitionedEvent and, when a LearnerProfile
 * transitions to `merged`, moves the merged account's enrolments, grades,
 * attendance, credentials, portfolio entries and other learner-owned records
 * to the surviving profile named in `mergedInto` (learniq#950). The profiles
 * themselves are left as they are, so `mergedInto` stays set as the audit link
 * and the old Nextcloud user is not touched.
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
 * @spec openspec/parity/capabilities.json#gov-merge-duplicate-accounts
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\LearnerMergeService;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Moves a merged learner's records to the surviving LearnerProfile.
 *
 * @implements IEventListener<Event>
 */
class LearnerMergeHandler implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const PROFILE_SCHEMA = 'learner-profile';

	/**
	 * Constructor.
	 *
	 * @param LearnerMergeService $mergeService Record mover.
	 * @param LoggerInterface     $logger       PSR logger.
	 * @param ListenerSchemaResolver $schemas Resolves the transition event's register and schema ids to slugs.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LearnerMergeService $mergeService,
		private readonly LoggerInterface $logger,
		private readonly ListenerSchemaResolver $schemas,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/parity/capabilities.json#gov-merge-duplicate-accounts
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		if ($this->schemas->eventRegister(event: $event) !== self::LEARNIQ_REGISTER
			|| $this->schemas->eventSchema(event: $event) !== self::PROFILE_SCHEMA
			|| $event->getTo() !== 'merged'
		) {
			return;
		}

		$profile = $event->getObject()->jsonSerialize();
		if ((string)($profile['mergedInto'] ?? '') === '') {
			$this->logger->warning('[LearnerMergeHandler] Merged profile has no mergedInto; nothing moved.');
			return;
		}

		$this->mergeService->moveRecords(merged: $profile);

	}//end handle()
}//end class
