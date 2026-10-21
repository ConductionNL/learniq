<?php

/**
 * Learniq Learning Record Created Transition
 *
 * Moves a learner's new export or share on in the request that created it:
 * an export in `requested` is generated, a share in `draft` is granted. The
 * screen then reads the row back and tells the learner when it did not move,
 * instead of firing the transition itself and never reading its answer.
 *
 * Only the learner's own new row moves. A seed import (no session), an
 * administrator's write and a row already past its first state are left
 * alone. A failed transition is logged and leaves the row in its first state.
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
 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-learner-creates-a-share-with-a-mandatory-expiry
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generates a new LearningRecordExport and grants a new LearningRecordShare.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-learner-creates-a-share-with-a-mandatory-expiry
 */
class LearningRecordCreatedTransition implements IEventListener {

	/**
	 * Per schema: the state a new row starts in and the transition that moves it on.
	 */
	private const MOVES = [
		'learning-record-export' => ['from' => 'requested', 'action' => 'generate'],
		'learning-record-share'  => ['from' => 'draft', 'action' => 'grant'],
	];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver   Entity schema id to slug.
	 * @param TransitionEngine       $transitionEngine Runs the transition as the signed-in learner.
	 * @param IGroupManager          $groupManager     Whether the caller is an administrator.
	 * @param IUserSession           $userSession      The signed-in user.
	 * @param LoggerInterface        $logger           Records a transition that failed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly TransitionEngine $transitionEngine,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Move the learner's new row on.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-learner-creates-a-share-with-a-mandatory-expiry
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false) {
			return;
		}

		$entity = $event->getObject();
		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $entity);
		} catch (Throwable $exception) {
			return;
		}

		$move = (self::MOVES[$slug] ?? null);
		if ($move === null) {
			return;
		}

		$userId = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($userId === '' || $this->groupManager->isAdmin($userId) === true) {
			return;
		}

		$row = $entity->getObject();
		if (($row['learnerId'] ?? null) !== $userId || ($row['lifecycle'] ?? $move['from']) !== $move['from']) {
			return;
		}

		$objectId = (string)($entity->getUuid() ?? ($row['id'] ?? ''));
		try {
			$this->transitionEngine->transition($objectId, $move['action']);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[LearningRecordCreatedTransition] {action} of {schema} {id} failed: {message}',
				['action' => $move['action'], 'schema' => $slug, 'id' => $objectId, 'message' => $exception->getMessage()]
			);
		}
	}//end handle()
}//end class
