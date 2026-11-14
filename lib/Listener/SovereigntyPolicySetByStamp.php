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
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

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
	 * @param ListenerSchemaResolverInterface $schemaResolver Entity schema id to slug.
	 * @param IUserSession           $userSession    The signed-in user.
	 * @param ITimeFactory           $timeFactory    The server's clock.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
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
		$write = SchemaWrite::of(event: $event, schemaResolver: $this->schemaResolver);
		if ($write === null || $write->slug !== self::SCHEMA) {
			return;
		}

		$this->stampWrite(write: $write);
	}//end handle()

	/**
	 * Stamp one write of a schema this listener owns.
	 *
	 * @param SchemaWrite $write The write and its schema slug.
	 *
	 * @return void
	 */
	private function stampWrite(SchemaWrite $write): void {
		$event = $write->event;

		$event->setModifiedData(array_merge($event->getModifiedData(), $this->stamp(event: $event)));
	}//end stampWrite()

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
}//end class
