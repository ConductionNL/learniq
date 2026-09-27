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
 * @spec openspec/changes/oso-inbound-contract/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\OsoImportRejectGuard;
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
	 * A coordinator rejecting with a reason is allowed, and reviewedBy/
	 * reviewedAt are stamped server-side.
	 *
	 * @return void
	 */
	public function testCoordinatorWithReasonIsAllowedAndStamped(): void {
		$guard = $this->makeGuard(['coordinator']);
		$context = [
			'object' => ['id' => 'dossier-1'],
			'actor' => 'actor-1',
			'payload' => ['rejectionReason' => 'BRIN does not match any known sending school.'],
		];

		self::assertTrue($guard->check($context));
		self::assertSame('actor-1', $context['payload']['reviewedBy']);
		self::assertNotEmpty($context['payload']['reviewedAt']);

	}//end testCoordinatorWithReasonIsAllowedAndStamped()

	/**
	 * Rejecting without a reason is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/oso-inbound-contract/specs/data-exchange/spec.md#scenario-rejecting-without-a-reason-is-refused
	 */
	public function testEmptyReasonRefused(): void {
		$guard = $this->makeGuard(['coordinator']);
		$context = ['object' => ['id' => 'dossier-1'], 'actor' => 'actor-1', 'payload' => []];

		self::assertFalse($guard->check($context));

	}//end testEmptyReasonRefused()

	/**
	 * A whitespace-only reason is also refused.
	 *
	 * @return void
	 */
	public function testWhitespaceOnlyReasonRefused(): void {
		$guard = $this->makeGuard(['coordinator']);
		$context = ['object' => ['id' => 'dossier-1'], 'actor' => 'actor-1', 'payload' => ['rejectionReason' => '   ']];

		self::assertFalse($guard->check($context));

	}//end testWhitespaceOnlyReasonRefused()

	/**
	 * A learner (no privileged group) is denied even with a valid reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/oso-inbound-contract/specs/data-exchange/spec.md#scenario-a-non-admincoordinator-actor-cannot-accept-or-reject-an-osoimportdossier
	 */
	public function testDeniesNonCoordinator(): void {
		$guard = $this->makeGuard([]);
		$context = ['object' => ['id' => 'dossier-1'], 'actor' => 'actor-1', 'payload' => ['rejectionReason' => 'Not clear.']];

		self::assertFalse($guard->check($context));

	}//end testDeniesNonCoordinator()

	/**
	 * No actor in the transition context is denied.
	 *
	 * @return void
	 */
	public function testNoActorIsDenied(): void {
		$guard = $this->makeGuard(['coordinator']);
		$context = ['object' => ['id' => 'dossier-1'], 'payload' => ['rejectionReason' => 'Not clear.']];

		self::assertFalse($guard->check($context));

	}//end testNoActorIsDenied()
}//end class
