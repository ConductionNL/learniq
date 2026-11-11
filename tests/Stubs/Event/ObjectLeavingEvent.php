<?php

/**
 * Test stub for OCA\OpenRegister\Event\ObjectLeavingEvent.
 *
 * OpenRegister dispatches it before an object moves out of its register or
 * schema; as in OpenRegister, it is a deleting event.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

/**
 * Stub for ObjectLeavingEvent.
 */
class ObjectLeavingEvent extends ObjectDeletingEvent {
}//end class
