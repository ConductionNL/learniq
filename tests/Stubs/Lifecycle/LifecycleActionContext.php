<?php

/**
 * Test stub for OCA\OpenRegister\Lifecycle\LifecycleActionContext.
 *
 * What a lifecycle action runs on: the object payload after and before the
 * transition, the declared action parameters and the declared action name.
 * Resolved via the `OCA\OpenRegister\ => tests/Stubs/` mapping in
 * tests/bootstrap.php when the real class is not loaded. The real one lives in
 * openregister/lib/Lifecycle/LifecycleActionContext.php.
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
 * Stub for LifecycleActionContext.
 */
final class LifecycleActionContext {

	/**
	 * Constructor.
	 *
	 * @param array  $objectData   The object after the lifecycle field moved to its target.
	 * @param array  $previousData The object before the transition.
	 * @param array  $parameters   The declared `actionParameters` block.
	 * @param string $actionName   The declared `action` name.
	 */
	public function __construct(
		public readonly array $objectData,
		public readonly array $previousData,
		public readonly array $parameters,
		public readonly string $actionName,
	) {
	}//end __construct()
}//end class
