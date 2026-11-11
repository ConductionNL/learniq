<?php

/**
 * Learniq write of a resolved schema.
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
 * @spec exclude Listener plumbing, not a behavioural requirement: it answers
 *       "is this a write of an object whose schema we know", so each stamp
 *       listener can bail out on every other event.
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use Throwable;

/**
 * A create or update no earlier listener stopped, with the slug of the
 * schema of the object being written.
 *
 * @spec exclude Listener plumbing, not a behavioural requirement.
 */
final class SchemaWrite {

	/**
	 * Constructor.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 * @param string                                  $slug  The schema slug of the object being written.
	 */
	public function __construct(
		public readonly ObjectCreatingEvent|ObjectUpdatingEvent $event,
		public readonly string $slug,
	) {
	}//end __construct()

	/**
	 * The write behind an event, or null for any other event, a stopped
	 * write, or a write whose schema can not be resolved.
	 *
	 * @param Event                  $event          The dispatched event.
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 *
	 * @return self|null
	 *
	 * @spec exclude Listener plumbing, not a behavioural requirement.
	 */
	public static function of(Event $event, ListenerSchemaResolver $schemaResolver): ?self {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return null;
		}

		if ($event->isPropagationStopped() === true) {
			return null;
		}

		try {
			$slug = $schemaResolver->guardSchemaSlug(entity: self::entityOf(event: $event));
		} catch (Throwable) {
			// Not knowing the schema is not knowing it is ours: never touch
			// another app's writes.
			return null;
		}

		return new self(event: $event, slug: $slug);
	}//end of()

	/**
	 * The object being written: the new state on an update.
	 *
	 * @return ObjectEntity
	 *
	 * @spec exclude Listener plumbing, not a behavioural requirement.
	 */
	public function entity(): ObjectEntity {
		return self::entityOf(event: $this->event);
	}//end entity()

	/**
	 * The object a write event carries: getNewObject() on an update, only a
	 * create has getObject().
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 *
	 * @return ObjectEntity
	 */
	private static function entityOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectUpdatingEvent === true) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end entityOf()
}//end class
