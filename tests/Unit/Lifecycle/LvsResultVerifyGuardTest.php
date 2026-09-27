<?php

/**
 * Learniq LvsResultVerifyGuard unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lvs-import-contract/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\LvsResultVerifyGuard;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for LvsResultVerifyGuard::check() — the LvsResult
 * `imported → verified` transition.
 */
class LvsResultVerifyGuardTest extends TestCase {
	/**
	 * Build a guard whose user/group managers report the given group
	 * membership for a known 'actor-1' user.
	 *
	 * @param string[] $groups Group IDs 'actor-1' belongs to.
	 *
	 * @return LvsResultVerifyGuard
	 */
	private function makeGuard(array $groups): LvsResultVerifyGuard {
		$user = $this->createMock(IUser::class);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			static function (string $uid) use ($user): ?IUser {
				return $uid === 'actor-1' ? $user : null;
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		return new LvsResultVerifyGuard($groupManager, $userManager, new NullLogger());
	}//end makeGuard()

	/**
	 * A coordinator may verify an imported LvsResult.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lvs-import-contract/specs/data-exchange/spec.md#scenario-an-imported-result-is-not-verified-until-a-coordinator-confirms-it
	 */
	public function testCoordinatorIsAllowed(): void {
		$guard = $this->makeGuard(['coordinator']);
		$context = ['object' => ['id' => 'lvs-1'], 'actor' => 'actor-1'];

		self::assertTrue($guard->check($context));

	}//end testCoordinatorIsAllowed()

	/**
	 * An admin may also verify.
	 *
	 * @return void
	 */
	public function testAdminIsAllowed(): void {
		$guard = $this->makeGuard(['admin']);
		$context = ['object' => ['id' => 'lvs-1'], 'actor' => 'actor-1'];

		self::assertTrue($guard->check($context));

	}//end testAdminIsAllowed()

	/**
	 * A learner (no privileged group) is denied.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lvs-import-contract/specs/data-exchange/spec.md#scenario-a-non-admincoordinator-actor-cannot-verify-an-lvsresult
	 */
	public function testUnauthorisedActorIsDenied(): void {
		$guard = $this->makeGuard([]);
		$context = ['object' => ['id' => 'lvs-1'], 'actor' => 'actor-1'];

		self::assertFalse($guard->check($context));

	}//end testUnauthorisedActorIsDenied()

	/**
	 * No actor in the transition context is denied.
	 *
	 * @return void
	 */
	public function testNoActorIsDenied(): void {
		$guard = $this->makeGuard(['coordinator']);
		$context = ['object' => ['id' => 'lvs-1']];

		self::assertFalse($guard->check($context));

	}//end testNoActorIsDenied()

	/**
	 * An unknown actor (not resolvable via IUserManager) is denied.
	 *
	 * @return void
	 */
	public function testUnknownActorIsDenied(): void {
		$guard = $this->makeGuard(['coordinator']);
		$context = ['object' => ['id' => 'lvs-1'], 'actor' => 'ghost-user'];

		self::assertFalse($guard->check($context));

	}//end testUnknownActorIsDenied()
}//end class
