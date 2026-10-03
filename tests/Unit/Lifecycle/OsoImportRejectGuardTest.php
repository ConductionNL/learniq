<?php

/**
 * Learniq OsoImportRejectGuard unit tests.
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

use OCA\Learniq\Lifecycle\OsoImportRejectGuard;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for OsoImportRejectGuard::check() — the OsoImportDossier
 * `under-review → rejected` transition.
 */
class OsoImportRejectGuardTest extends TestCase {
	/**
	 * Build a guard whose user/group managers report the given group
	 * membership for a known 'actor-1' user.
	 *
	 * @param string[] $groups Group IDs 'actor-1' belongs to.
	 *
	 * @return OsoImportRejectGuard
	 */
	private function makeGuard(array $groups): OsoImportRejectGuard {
		$user = $this->createMock(IUser::class);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			static function (string $uid) use ($user): ?IUser {
				return $uid === 'actor-1' ? $user : null;
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		return new OsoImportRejectGuard($groupManager, $userManager, new NullLogger());
	}//end makeGuard()

	/**
	 * The dossier as the guard sees it on reject: status at `rejected`, the
	 * `rejectionReason` input merged in by TransitionEngine.
	 *
	 * @param mixed $reason The reason the caller sent, or null to leave it out.
	 *
	 * @return array<string,mixed>
	 */
	private function dossier(mixed $reason): array {
		$object = ['id' => 'dossier-1', 'status' => 'rejected'];
		if ($reason !== null) {
			$object['rejectionReason'] = $reason;
		}

		return $object;
	}//end dossier()

	/**
	 * OpenRegister's registry refuses a guard that does not implement its interface.
	 *
	 * @return void
	 */
	public function testImplementsTheOpenRegisterGuardInterface(): void {
		self::assertInstanceOf(LifecycleGuardInterface::class, $this->makeGuard([]));

	}//end testImplementsTheOpenRegisterGuardInterface()

	/**
	 * A coordinator rejecting with a reason is allowed. reviewedBy/reviewedAt
	 * are StampTransitionActorAction's write (learniq#983).
	 *
	 * @return void
	 */
	public function testCoordinatorWithReasonIsAllowed(): void {
		$object = $this->dossier('BRIN does not match any known sending school.');

		self::assertTrue($this->makeGuard(['coordinators'])->check($object, 'reject', 'actor-1')->isAllowed());

	}//end testCoordinatorWithReasonIsAllowed()

	/**
	 * A missing reason is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#scenario-rejecting-without-a-reason-is-refused
	 */
	public function testMissingReasonRefused(): void {
		$result = $this->makeGuard(['coordinators'])->check($this->dossier(null), 'reject', 'actor-1');

		self::assertFalse($result->isAllowed());
		self::assertNotSame('', (string)$result->getMessage());

	}//end testMissingReasonRefused()

	/**
	 * An empty reason is refused.
	 *
	 * @return void
	 */
	public function testEmptyReasonRefused(): void {
		self::assertFalse($this->makeGuard(['coordinators'])->check($this->dossier(''), 'reject', 'actor-1')->isAllowed());

	}//end testEmptyReasonRefused()

	/**
	 * A whitespace-only reason is refused.
	 *
	 * @return void
	 */
	public function testWhitespaceOnlyReasonRefused(): void {
		self::assertFalse($this->makeGuard(['coordinators'])->check($this->dossier('   '), 'reject', 'actor-1')->isAllowed());

	}//end testWhitespaceOnlyReasonRefused()

	/**
	 * A user outside admin/coordinator is denied even with a reason.
	 *
	 * @return void
	 */
	public function testDeniesNonCoordinator(): void {
		self::assertFalse($this->makeGuard(['teacher'])->check($this->dossier('Not clear.'), 'reject', 'actor-1')->isAllowed());

	}//end testDeniesNonCoordinator()

	/**
	 * No session user is denied.
	 *
	 * @return void
	 */
	public function testNoActorIsDenied(): void {
		self::assertFalse($this->makeGuard(['coordinators'])->check($this->dossier('Not clear.'), 'reject', '')->isAllowed());

	}//end testNoActorIsDenied()

	/**
	 * The singular `coordinator` is not a group the register declares, so a
	 * member of a group by that name is refused even with a reason.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-imported-lvs-results-and-transfer-dossiers-are-read-and-written-by-the-groups-that-review-them
	 */
	public function testSingularCoordinatorGroupIsDenied(): void {
		self::assertFalse($this->makeGuard(['coordinator'])->check($this->dossier('Not clear.'), 'reject', 'actor-1')->isAllowed());

	}//end testSingularCoordinatorGroupIsDenied()
}//end class
