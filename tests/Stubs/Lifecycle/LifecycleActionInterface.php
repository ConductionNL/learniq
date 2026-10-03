<?php

/**
 * Test stub for OCA\OpenRegister\Lifecycle\LifecycleActionInterface.
 *
 * Mirrors the contract OpenRegister's LifecycleActionExecutor calls for each
 * entry of a transition's `actions` block: the handler receives the object
 * after the lifecycle field moved to its target, and returns the object to
 * save. Resolved via the `OCA\OpenRegister\ => tests/Stubs/` mapping in
 * tests/bootstrap.php when the real interface is not loaded. The real one
 * lives in openregister/lib/Lifecycle/LifecycleActionInterface.php.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Stubs\Lifecycle
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Lifecycle;

/**
 * Stub for LifecycleActionInterface.
 */
interface LifecycleActionInterface {

	/**
	 * Run the action on a transitioning object.
	 *
	 * The real interface types the arrays as `array<string, mixed>`; the stub
	 * keeps plain `array`, for the same psalm reason the guard stub gives.
	 *
	 * @param array  $objectData   The object after the lifecycle field moved to its target.
	 * @param array  $previousData The object before the transition.
	 * @param array  $parameters   The declared `actionParameters` block.
	 * @param string $actionName   The declared `action` name.
	 *
	 * @return array The object to save, with any self-mutations applied.
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array;
}//end interface
