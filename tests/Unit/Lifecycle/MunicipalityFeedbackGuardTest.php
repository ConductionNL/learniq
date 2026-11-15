<?php

/**
 * Learniq MunicipalityFeedbackGuard unit tests.
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
 * @spec openspec/changes/archive/2026-07-13-verzuim-report-composer/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\MunicipalityFeedbackGuard;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for MunicipalityFeedbackGuard::check() — the AttendanceFlag
 * recordMunicipalityFeedback (reported → reported) self-loop transition
 * (moved off DataExchangeJob by data-exchange-to-integriq).
 */
class MunicipalityFeedbackGuardTest extends TestCase {
	/**
	 * Build a guard whose user/group managers report the given group
	 * membership for a known 'actor-1' user.
	 *
	 * @param string[] $groups Group IDs 'actor-1' belongs to.
	 *
	 * @return MunicipalityFeedbackGuard
	 */
	private function makeGuard(array $groups): MunicipalityFeedbackGuard {
		$user = $this->createMock(IUser::class);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			static function (string $uid) use ($user): ?IUser {
				return $uid === 'actor-1' ? $user : null;
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		return new MunicipalityFeedbackGuard($groupManager, $userManager, new NullLogger());
	}//end makeGuard()

	/**
	 * The flag as the guard would see it: lifecycle stays `reported` (a
	 * self-loop) and the `municipalityFeedback` input is merged in.
	 *
	 * @param string $lifecycle The flag's state.
	 *
	 * @return array<string,mixed>
	 */
	private function job(string $lifecycle = 'reported'): array {
		return [
			'id' => 'flag-1',
			'lifecycle' => $lifecycle,
			'municipalityFeedback' => ['masRoute' => 'jeugdhulp', 'note' => 'Route toegewezen.'],
		];
	}//end job()

	/**
	 * OpenRegister's registry refuses a guard that does not implement its interface.
	 *
	 * @return void
	 */
	public function testImplementsTheOpenRegisterGuardInterface(): void {
		self::assertInstanceOf(LifecycleGuardInterface::class, $this->makeGuard([]));

	}//end testImplementsTheOpenRegisterGuardInterface()

	/**
	 * A coordinator on a leerplicht job is allowed. recordedBy/receivedAt are
	 * MunicipalityFeedbackStampListener's write (learniq#983), see
	 * tests/Unit/Listener/MunicipalityFeedbackStampListenerTest.php.
	 *
	 * @return void
	 */
	public function testCoordinatorOnLeerplichtJobIsAllowed(): void {
		self::assertTrue($this->makeGuard(['coordinators'])->check($this->job(), 'recordMunicipalityFeedback', 'actor-1')->isAllowed());

	}//end testCoordinatorOnLeerplichtJobIsAllowed()

	/**
	 * An admin on a leerplicht job is allowed.
	 *
	 * @return void
	 */
	public function testAdminOnLeerplichtJobIsAllowed(): void {
		self::assertTrue($this->makeGuard(['admin'])->check($this->job(), 'recordMunicipalityFeedback', 'actor-1')->isAllowed());

	}//end testAdminOnLeerplichtJobIsAllowed()

	/**
	 * A user outside admin/coordinators is denied.
	 *
	 * @return void
	 */
	public function testUnauthorisedActorIsDenied(): void {
		$result = $this->makeGuard([])->check($this->job(), 'recordMunicipalityFeedback', 'actor-1');

		self::assertFalse($result->isAllowed());
		self::assertNotSame('', (string)$result->getMessage());

	}//end testUnauthorisedActorIsDenied()

	/**
	 * A flag that is not reported yet is denied.
	 *
	 * @return void
	 */
	public function testNonLeerplichtTargetIsDenied(): void {
		self::assertFalse($this->makeGuard(['coordinators'])->check($this->job('in-handling'), 'recordMunicipalityFeedback', 'actor-1')->isAllowed());

	}//end testNonLeerplichtTargetIsDenied()

	/**
	 * No session user is denied.
	 *
	 * @return void
	 */
	public function testNoActorIsDenied(): void {
		self::assertFalse($this->makeGuard(['coordinators'])->check($this->job(), 'recordMunicipalityFeedback', '')->isAllowed());

	}//end testNoActorIsDenied()
}//end class
