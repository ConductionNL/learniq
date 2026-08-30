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
 * Mirror of OpenRegister's TransitionEngine for standalone Learniq unit tests.
 */
abstract class TransitionEngine {

	/**
	 * Run a lifecycle transition and return the resulting entity.
	 *
	 * Mirrors openregister `lib/Service/Lifecycle/TransitionEngine.php:257`:
	 *   `public function transition(string $objectId, string $action, array $data = []): ObjectEntity`
	 *
	 * The third parameter arrived upstream on 2026-08-21 (openregister
	 * `113f0520`). CI installs openregister from `development` at run time, so
	 * this contract moves WITHOUT a commit here: pipelinq, carrying the same
	 * stale two-parameter double, had all six PHPUnit legs die with a
	 * Declaration-compatibility fatal before test 1 once the change landed.
	 *
	 * A stub NARROWER than the real class hides nothing, but one that is out
	 * of date fails only where the real class is loaded — i.e. in CI and not
	 * in a bare unit run, so the mode that reports "fine" is the one that
	 * cannot tell.
	 *
	 * @param string $objectId Object uuid.
	 * @param string $action Transition action name.
	 * @param array  $data Declared transition inputs.
	 *
	 * @return ObjectEntity
	 */
	abstract public function transition(string $objectId, string $action, array $data = []): ObjectEntity;

}//end class
