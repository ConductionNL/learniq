<?php

/**
 * Test stub for OCA\OpenRegister\Exception\NotAuthorizedException.
 *
 * OpenRegister throws it when RBAC refuses an object read or write; learniq
 * catches it to answer 403 instead of 500.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Stubs\Exception
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * Mirror of OpenRegister's RBAC refusal for standalone Learniq unit tests.
 */
class NotAuthorizedException extends Exception {
}//end class
