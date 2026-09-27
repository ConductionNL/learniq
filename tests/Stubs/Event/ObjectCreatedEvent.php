<?php

/**
 * Test stub for OCA\OpenRegister\Event\ObjectCreatedEvent.
 *
 * MIRROR, NOT A CONVENIENCE — see tests/Stubs/Service/ObjectService.php for the
 * full rationale. This used to declare `getRegister()` and `getSchema()`, which
 * the real ObjectCreatedEvent does not have: it carries only `getObject()`, and
 * register/schema are read off the entity (that is what ListenerSchemaResolver
 * is for). `createMock(ObjectCreatedEvent::class)->method('getRegister')` was
 * therefore green standalone and `MethodCannotBeConfiguredException` in CI.
 *
 * Its sibling ObjectTransitionedEvent DOES expose getRegister()/getSchema()
 * (plus getAction/getFrom/getTo/getUserId) — the two events genuinely differ,
 * which is exactly why the surface must be mirrored rather than assumed.
 *
 * Concrete, with the real constructor (openregister development d611a36,
 * lib/Event/ObjectCreatedEvent.php), so RegisteredListenersHandleRealEventsTest
 * can build the event OpenRegister dispatches instead of a double.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Stubs\Event
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Mirror of OpenRegister's ObjectCreatedEvent for standalone Learniq unit tests.
 */
class ObjectCreatedEvent extends Event {

	/**
	 * The newly created object entity.
	 *
	 * @var ObjectEntity
	 */
	private ObjectEntity $object;

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The object entity that was created.
	 */
	public function __construct(ObjectEntity $object) {
		parent::__construct();
		$this->object = $object;
	}//end __construct()

	/**
	 * The created object.
	 *
	 * @return ObjectEntity
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()

}//end class
