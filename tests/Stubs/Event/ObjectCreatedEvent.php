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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Scholiq\Tests\Stubs\Event
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Mirror of OpenRegister's ObjectCreatedEvent for standalone Scholiq unit tests.
 */
abstract class ObjectCreatedEvent extends Event
{


    /**
     * The created object.
     *
     * @return ObjectEntity
     */
    abstract public function getObject(): ObjectEntity;


}//end class
