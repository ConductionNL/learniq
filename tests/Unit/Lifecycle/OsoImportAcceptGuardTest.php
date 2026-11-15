<?php

/**
 * Learniq OsoImportAcceptGuard unit tests.
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
 * @spec openspec/changes/archive/2026-09-28-oso-inbound-contract/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\OsoImportAcceptGuard;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for OsoImportAcceptGuard::check() — the OsoImportDossier
 * `under-review → accepted` transition.
 */
class OsoImportAcceptGuardTest extends TestCase {
	/**
	 * Build a guard whose user/group managers report the given group
	 * membership for a known 'actor-1' user.
	 *
	 * @param string[] $groups Group IDs 'actor-1' belongs to.
	 *
	 * @return OsoImportAcceptGuard
	 */
	private function makeGuard(array $groups): OsoImportAcceptGuard {
		$user = $this->createMock(IUser::class);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			static function (string $uid) use ($user): ?IUser {
				return $uid === 'actor-1' ? $user : null;
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		return new OsoImportAcceptGuard($groupManager, $userManager, new NullLogger());
	}//end makeGuard()

	/**
	 * The dossier as the guard sees it on accept.
	 *
	 * @var array<string,mixed>
	 */
	private const DOSSIER = ['id' => 'dossier-1', 'status' => 'accepted'];

	/**
	 * OpenRegister's registry refuses a guard that does not implement its interface.
	 *
	 * @return void
	 */
	public function testImplementsTheOpenRegisterGuardInterface(): void {
		self::assertInstanceOf(LifecycleGuardInterface::class, $this->makeGuard([]));

	}//end testImplementsTheOpenRegisterGuardInterface()

	/**
	 * A coordinator may accept. reviewedBy/reviewedAt are
	 * StampTransitionActorAction's write (learniq#983).
	 *
	 * @return void
	 */
	public function testCoordinatorIsAllowed(): void {
		self::assertTrue($this->makeGuard(['coordinators'])->check(self::DOSSIER, 'accept', 'actor-1')->isAllowed());

	}//end testCoordinatorIsAllowed()

	/**
	 * An admin may accept.
	 *
	 * @return void
	 */
	public function testAdminIsAllowed(): void {
		self::assertTrue($this->makeGuard(['admin'])->check(self::DOSSIER, 'accept', 'actor-1')->isAllowed());

	}//end testAdminIsAllowed()

	/**
	 * A user outside admin/coordinator is denied.
	 *
	 * @return void
	 */
	public function testUnauthorisedActorIsDenied(): void {
		$result = $this->makeGuard([])->check(self::DOSSIER, 'accept', 'actor-1');

		self::assertFalse($result->isAllowed());
		self::assertNotSame('', (string)$result->getMessage());

	}//end testUnauthorisedActorIsDenied()

	/**
	 * No session user is denied.
	 *
	 * @return void
	 */
	public function testNoActorIsDenied(): void {
		self::assertFalse($this->makeGuard(['coordinators'])->check(self::DOSSIER, 'accept', '')->isAllowed());

	}//end testNoActorIsDenied()

	/**
	 * The singular `coordinator` is not a group the register declares, so a
	 * member of a group by that name is refused like anyone else.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#scenario-the-singular-coordinator-group-accepts-nothing
	 */
	public function testSingularCoordinatorGroupIsDenied(): void {
		self::assertFalse($this->makeGuard(['coordinator'])->check(self::DOSSIER, 'accept', 'actor-1')->isAllowed());

	}//end testSingularCoordinatorGroupIsDenied()
}//end class
