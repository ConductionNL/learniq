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
 * @spec openspec/changes/oso-inbound-contract/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\OsoImportAcceptGuard;
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
	 * A coordinator accepting a dossier is allowed, and reviewedBy/reviewedAt
	 * are stamped server-side.
	 *
	 * @return void
	 */
	public function testCoordinatorIsAllowedAndStamped(): void {
		$guard = $this->makeGuard(['coordinator']);
		$context = [
			'object' => ['id' => 'dossier-1'],
			'actor' => 'actor-1',
			'payload' => [],
		];

		self::assertTrue($guard->check($context));
		self::assertSame('actor-1', $context['payload']['reviewedBy']);
		self::assertNotEmpty($context['payload']['reviewedAt']);

	}//end testCoordinatorIsAllowedAndStamped()

	/**
	 * An admin may also accept.
	 *
	 * @return void
	 */
	public function testAdminIsAllowed(): void {
		$guard = $this->makeGuard(['admin']);
		$context = ['object' => ['id' => 'dossier-1'], 'actor' => 'actor-1', 'payload' => []];

		self::assertTrue($guard->check($context));

	}//end testAdminIsAllowed()

	/**
	 * A learner (no privileged group) is denied.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/oso-inbound-contract/specs/data-exchange/spec.md#scenario-a-non-admincoordinator-actor-cannot-accept-or-reject-an-osoimportdossier
	 */
	public function testUnauthorisedActorIsDenied(): void {
		$guard = $this->makeGuard([]);
		$context = ['object' => ['id' => 'dossier-1'], 'actor' => 'actor-1', 'payload' => []];

		self::assertFalse($guard->check($context));

	}//end testUnauthorisedActorIsDenied()

	/**
	 * No actor in the transition context is denied.
	 *
	 * @return void
	 */
	public function testNoActorIsDenied(): void {
		$guard = $this->makeGuard(['coordinator']);
		$context = ['object' => ['id' => 'dossier-1'], 'payload' => []];

		self::assertFalse($guard->check($context));

	}//end testNoActorIsDenied()
}//end class
