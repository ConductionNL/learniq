<?php

/**
 * Learniq ReportCardReopenGuard unit tests.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/report-card/spec.md#scenario-a-mentor-reopens-a-finalised-report-card-to-correct-it-before-publication
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\Learniq\Lifecycle\ReportCardReopenGuard;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ReportCardReopenGuard (finalised -> rapportvergadering-review).
 */
class ReportCardReopenGuardTest extends TestCase {

	use GuardVerdicts;

	/**
	 * Build a guard whose group manager reports the given groups for the actor.
	 *
	 * @param array<string> $actorGroups Group IDs the actor belongs to.
	 * @param bool $actorExists Whether the user manager resolves the actor.
	 *
	 * @return ReportCardReopenGuard
	 */
	private function makeGuard(array $actorGroups, bool $actorExists = true): ReportCardReopenGuard {
		$user = $this->createMock(IUser::class);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($actorExists === true ? $user : null);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($actorGroups);

		return new ReportCardReopenGuard($groupManager, $userManager, $this->createMock(LoggerInterface::class));
	}//end makeGuard()

	/**
	 * An admin/mentor/principal may reopen a finalised report card.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-a-mentor-reopens-a-finalised-report-card-to-correct-it-before-publication
	 */
	public function testOverrideRolesAllowReopen(): void {
		foreach (['admin', 'team-leads', 'administration-managers'] as $role) {
			$guard = $this->makeGuard([$role]);
			$object = ['id' => 'card-1', 'lifecycle' => 'rapportvergadering-review'];

			self::assertAllowed($guard->check($object, 'reopen', 'staff-1'), "role '{$role}' should be allowed to reopen");
		}

	}//end testOverrideRolesAllowReopen()

	/**
	 * A subject teacher (no override role) cannot reopen.
	 *
	 * @return void
	 */
	public function testNonOverrideRoleDeniesReopen(): void {
		$guard = $this->makeGuard(['teacher']);
		$object = ['id' => 'card-1', 'lifecycle' => 'rapportvergadering-review'];

		self::assertDenied($guard->check($object, 'reopen', 'teacher-1'));

	}//end testNonOverrideRoleDeniesReopen()

	/**
	 * No actor in the context denies reopen.
	 *
	 * @return void
	 */
	public function testMissingActorDeniesReopen(): void {
		$guard = $this->makeGuard(['admin']);
		$object = ['id' => 'card-1', 'lifecycle' => 'rapportvergadering-review'];

		self::assertDenied($guard->check($object, 'reopen', ''));

	}//end testMissingActorDeniesReopen()

	/**
	 * An unresolvable actor (not a valid NC user) denies reopen.
	 *
	 * @return void
	 */
	public function testUnresolvableActorDeniesReopen(): void {
		$guard = $this->makeGuard(['admin'], actorExists: false);
		$object = ['id' => 'card-1', 'lifecycle' => 'rapportvergadering-review'];

		self::assertDenied($guard->check($object, 'reopen', 'ghost-1'));

	}//end testUnresolvableActorDeniesReopen()
}//end class
