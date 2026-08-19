<?php

/**
 * Test stub for OCA\OpenRegister\Service\Lifecycle\TransitionEngine.
 *
 * MIRROR, NOT A CONVENIENCE — see tests/Stubs/Service/ObjectService.php for the
 * full rationale. This declared `transition(): void` while the real engine
 * declares `: ObjectEntity`, so any test whose `transition` callback returned
 * nothing was green standalone and threw
 * `TypeError: Return value must be of type ObjectEntity, null returned`
 * against the real class in CI. Worse, two handlers
 * (RejectionMappingHandler::attemptTransition, SupportRequestSubmitHandler)
 * wrap the call in `catch (\Throwable)`, so their tests passed in CI while
 * silently swallowing that TypeError — green for the wrong reason.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Stubs\Service\Lifecycle
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Mirror of OpenRegister's TransitionEngine for standalone Scholiq unit tests.
 */
abstract class TransitionEngine {

	/**
	 * Run a lifecycle transition and return the resulting entity.
	 *
	 * @param string $objectId Object uuid.
	 * @param string $action Transition action name.
	 *
	 * @return ObjectEntity
	 */
	abstract public function transition(string $objectId, string $action): ObjectEntity;

}//end class
