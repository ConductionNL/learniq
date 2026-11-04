<?php

/**
 * Learniq Sovereignty Policy Set-By Stamp
 *
 * Decides, on the server, who last set the school's AI locality policy and
 * when. Every create and update records the signed-in user as `setBy` and the
 * server's time as `setAt`, whatever the client sent. A write without a
 * signed-in user keeps the stored values (a create gets none).
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
 * @spec openspec/specs/ai-locality-guarantee/spec.md#scenario-a-school-sets-its-locality-policy
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Throwable;

/**
 * Stamps `setBy` and `setAt` on every write of a SovereigntyPolicy.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/ai-locality-guarantee/spec.md#scenario-a-school-sets-its-locality-policy
 */
class SovereigntyPolicySetByStamp implements IEventListener {

	private const SCHEMA = 'sovereignty-policy';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param IUserSession           $userSession    The signed-in user.
	 * @param ITimeFactory           $timeFactory    The server's clock.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $timeFactory,
	) {
	}//end __construct()

	/**
	 * Stamp who set the policy and when.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/ai-locality-guarantee/spec.md#scenario-a-school-sets-its-locality-policy
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		if ($event->isPropagationStopped() === true) {
			return;
		}

		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $this->entityOf(event: $event));
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours: never touch
			// another app's writes.
			return;
		}

		if ($slug !== self::SCHEMA) {
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $this->stamp(event: $event)));
	}//end handle()

	/**
	 * The `setBy` and `setAt` this write stores.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 *
	 * @return array{setBy: mixed, setAt: mixed}
	 */
	private function stamp(ObjectCreatingEvent|ObjectUpdatingEvent $event): array {
		$userId = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($userId !== '') {
			$now = (new DateTimeImmutable())->setTimestamp($this->timeFactory->getTime());

			return ['setBy' => $userId, 'setAt' => $now->format(DateTimeInterface::ATOM)];
		}

		$old = [];
		if ($event instanceof ObjectUpdatingEvent === true) {
			$old = ($event->getOldObject()?->getObject() ?? []);
		}

		return ['setBy' => ($old['setBy'] ?? null), 'setAt' => ($old['setAt'] ?? null)];
	}//end stamp()

	/**
	 * The object being written: an update carries it in getNewObject(), only
	 * a create has getObject().
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 *
	 * @return ObjectEntity
	 */
	private function entityOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectUpdatingEvent === true) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end entityOf()
}//end class
