<?php

/**
 * Test stub for OCA\OpenRegister\Lifecycle\LifecycleGuardInterface.
 *
 * Mirrors the contract OpenRegister's LifecycleValidationListener calls for a
 * transition's `requires` guard: the registry resolves the class and refuses
 * any service that does not implement this interface. Resolved via the
 * `OCA\OpenRegister\ => tests/Stubs/` mapping in tests/bootstrap.php when the
 * real interface is not loaded. The real one lives in
 * openregister/lib/Lifecycle/LifecycleGuardInterface.php.
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
 * Stub for LifecycleGuardInterface.
 */
interface LifecycleGuardInterface {

	/**
	 * Authorise (or deny) a transition.
	 *
	 * The real interface types `$object` as `array<string, mixed>`; the stub
	 * keeps plain `array`, because psalm reads a stub's docblock as the
	 * signature and would otherwise refuse every implementation's `array`.
	 *
	 * @param array  $object The object as it would be saved.
	 * @param string $action The transition action being applied.
	 * @param string $userId The caller's uid, or '' without a session.
	 *
	 * @return GuardResult
	 */
	public function check(array $object, string $action, string $userId): GuardResult;
}//end interface
