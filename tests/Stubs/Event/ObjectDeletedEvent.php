<?php

/**
 * Test stub for OCA\OpenRegister\Event\ObjectDeletedEvent.
 *
 * MIRROR, NOT A CONVENIENCE: the real class carries only `getObject()`, the
 * ObjectEntity as it was before the delete (openregister
 * lib/Event/ObjectDeletedEvent.php). Register and schema are read off the
 * entity, through ListenerSchemaResolver.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Stubs\Event
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Mirror of OpenRegister's ObjectDeletedEvent for standalone Learniq unit tests.
 */
class ObjectDeletedEvent extends Event {

	/**
	 * The deleted object, as it was before the delete.
	 *
	 * @var ObjectEntity
	 */
	private ObjectEntity $object;

	/**
	 * Built the way OpenRegister builds it: the deleted entity only.
	 *
	 * @param ObjectEntity $object The deleted object.
	 */
	public function __construct(ObjectEntity $object) {
		parent::__construct();
		$this->object = $object;
	}//end __construct()

	/**
	 * The deleted object.
	 *
	 * @return ObjectEntity
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()
}//end class
