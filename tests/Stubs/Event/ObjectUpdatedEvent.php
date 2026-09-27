<?php

/**
 * Test stub for OCA\OpenRegister\Event\ObjectUpdatedEvent.
 *
 * Unlike ObjectCreatedEvent/ObjectTransitionedEvent, the real class exposes no
 * direct getRegister()/getSchema() convenience methods on the event itself —
 * only getObject() and getNewObject() (both the post-update ObjectEntity) and
 * getOldObject() (the pre-update ObjectEntity, nullable when unavailable).
 * Listeners resolve register/schema via `$event->getObject()->getRegister()`/
 * `getSchema()`, mirroring the existing ObjectCreatedEvent-consuming listeners
 * (e.g. LessonProgressHandler).
 *
 * Concrete, with the real constructor and all three accessors (openregister
 * development d611a36, lib/Event/ObjectUpdatedEvent.php), so
 * RegisteredListenersHandleRealEventsTest can build the event OpenRegister
 * dispatches instead of a double. Note the difference from ObjectUpdatingEvent,
 * which has getNewObject()/getOldObject() and NO getObject() (learniq#1046).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Stub for ObjectUpdatedEvent.
 */
class ObjectUpdatedEvent extends Event {

	/**
	 * The object entity after the update.
	 *
	 * @var ObjectEntity
	 */
	private ObjectEntity $newObject;

	/**
	 * The object entity before the update, null when not available.
	 *
	 * @var ObjectEntity|null
	 */
	private ?ObjectEntity $oldObject;

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity      $newObject The object entity after update.
	 * @param ObjectEntity|null $oldObject The object entity before update.
	 */
	public function __construct(ObjectEntity $newObject, ?ObjectEntity $oldObject = null) {
		parent::__construct();
		$this->newObject = $newObject;
		$this->oldObject = $oldObject;
	}//end __construct()

	/**
	 * The updated object entity.
	 *
	 * @return ObjectEntity
	 */
	public function getObject(): ObjectEntity {
		return $this->newObject;
	}//end getObject()

	/**
	 * The updated object entity.
	 *
	 * @return ObjectEntity
	 */
	public function getNewObject(): ObjectEntity {
		return $this->newObject;
	}//end getNewObject()

	/**
	 * The original object entity.
	 *
	 * @return ObjectEntity|null
	 */
	public function getOldObject(): ?ObjectEntity {
		return $this->oldObject;
	}//end getOldObject()
}//end class
