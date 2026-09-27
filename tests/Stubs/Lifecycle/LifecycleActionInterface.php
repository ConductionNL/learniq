<?php

/**
 * Test stub for OCA\OpenRegister\Lifecycle\LifecycleActionInterface.
 *
 * Same contract as openregister/lib/Lifecycle/LifecycleActionInterface.php
 * (development 71527ce): OpenRegister's LifecycleActionListener runs a
 * transition's declared `actions` through LifecycleActionExecutor, which
 * resolves each `action` name to a service implementing this interface and
 * merges the returned payload back into the object being saved. Resolved via
 * the `OCA\OpenRegister\ => tests/Stubs/` mapping in tests/bootstrap.php when
 * the real interface is not loaded.
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
	 * keeps plain `array` for the same psalm reason as LifecycleGuardInterface.
	 *
	 * @param array  $objectData   The object payload after the lifecycle field moved to its target.
	 * @param array  $previousData The object payload before the transition.
	 * @param array  $parameters   The declared `actionParameters` block (empty when absent).
	 * @param string $actionName   The declared `action` name that resolved to this handler.
	 *
	 * @return array The object payload, with any self-mutations applied.
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array;
}//end interface
