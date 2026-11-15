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
	 * @param LifecycleActionContext $context The payload after and before the transition, the declared
	 *                                        `actionParameters` block and the declared action name.
	 *
	 * @return array The object to save, with any self-mutations applied.
	 */
	public function execute(LifecycleActionContext $context): array;
}//end interface
