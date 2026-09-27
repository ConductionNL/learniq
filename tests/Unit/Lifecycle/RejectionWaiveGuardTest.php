<?php

/**
 * Learniq RejectionWaiveGuard unit tests.
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
 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-4.4
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\RejectionWaiveGuard;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for RejectionWaiveGuard::check() — the ExchangeRejection
 * `open|corrected → waived` transition.
 */
class RejectionWaiveGuardTest extends TestCase {

	/**
	 * Build a guard whose user/group managers report the given group
	 * membership for a known 'actor-1' user.
	 *
	 * @param string[] $groups Group IDs 'actor-1' belongs to.
	 *
	 * @return RejectionWaiveGuard
	 */
	private function makeGuard(array $groups): RejectionWaiveGuard {
		$user = $this->createMock(IUser::class);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			static function (string $uid) use ($user): ?IUser {
				return $uid === 'actor-1' ? $user : null;
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		return new RejectionWaiveGuard($groupManager, $userManager, new NullLogger());
	}//end makeGuard()


	/**
	 * The rejection as the guard sees it on waive: status at `waived`, the
	 * `waiveReason` input merged in by TransitionEngine.
	 *
	 * @param mixed $waiveReason The reason the caller sent, or null to leave it out.
	 *
	 * @return array<string,mixed>
	 */
	private function rejection(mixed $waiveReason): array {
		$object = ['id' => 'rej-1', 'sourceKind' => 'enrolment', 'status' => 'waived'];
		if ($waiveReason !== null) {
			$object['waiveReason'] = $waiveReason;
		}

		return $object;
	}//end rejection()

	/**
	 * OpenRegister's registry refuses a guard that does not implement its interface.
	 *
	 * @return void
	 */
	public function testImplementsTheOpenRegisterGuardInterface(): void {
		self::assertInstanceOf(LifecycleGuardInterface::class, $this->makeGuard([]));

	}//end testImplementsTheOpenRegisterGuardInterface()

	/**
	 * A coordinator waiving with a non-empty reason is allowed. waivedBy and
	 * waivedAt are StampTransitionActorAction's write (learniq#983).
	 *
	 * @return void
	 */
	public function testCoordinatorWithReasonIsAllowed(): void {
		$object = $this->rejection('DUO-fout is een bekend platformprobleem, geen actie nodig.');

		self::assertTrue($this->makeGuard(['coordinators'])->check($object, 'waive', 'actor-1')->isAllowed());

	}//end testCoordinatorWithReasonIsAllowed()

	/**
	 * An admin waiving with a reason is also allowed.
	 *
	 * @return void
	 */
	public function testAdminWithReasonIsAllowed(): void {
		self::assertTrue($this->makeGuard(['admin'])->check($this->rejection('Dubbele melding.'), 'waive', 'actor-1')->isAllowed());

	}//end testAdminWithReasonIsAllowed()

	/**
	 * An empty reason is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-waiving-without-a-reason-is-refused
	 */
	public function testEmptyReasonRefused(): void {
		$result = $this->makeGuard(['coordinators'])->check($this->rejection(''), 'waive', 'actor-1');

		self::assertFalse($result->isAllowed());
		self::assertNotSame('', (string)$result->getMessage());

	}//end testEmptyReasonRefused()

	/**
	 * A whitespace-only reason is refused.
	 *
	 * @return void
	 */
	public function testWhitespaceOnlyReasonRefused(): void {
		self::assertFalse($this->makeGuard(['coordinators'])->check($this->rejection("  \n\t "), 'waive', 'actor-1')->isAllowed());

	}//end testWhitespaceOnlyReasonRefused()

	/**
	 * A missing reason is refused.
	 *
	 * @return void
	 */
	public function testMissingReasonRefused(): void {
		self::assertFalse($this->makeGuard(['coordinators'])->check($this->rejection(null), 'waive', 'actor-1')->isAllowed());

	}//end testMissingReasonRefused()

	/**
	 * A non-string reason is refused.
	 *
	 * @return void
	 */
	public function testNonStringReasonRefused(): void {
		self::assertFalse($this->makeGuard(['coordinators'])->check($this->rejection(['x']), 'waive', 'actor-1')->isAllowed());

	}//end testNonStringReasonRefused()

	/**
	 * A user outside admin/coordinators is denied even with a reason.
	 *
	 * @return void
	 */
	public function testUnauthorisedActorIsDenied(): void {
		self::assertFalse($this->makeGuard(['teachers'])->check($this->rejection('Reden.'), 'waive', 'actor-1')->isAllowed());

	}//end testUnauthorisedActorIsDenied()

	/**
	 * No session user is denied.
	 *
	 * @return void
	 */
	public function testNoActorIsDenied(): void {
		self::assertFalse($this->makeGuard(['admin'])->check($this->rejection('Reden.'), 'waive', '')->isAllowed());

	}//end testNoActorIsDenied()
}//end class
