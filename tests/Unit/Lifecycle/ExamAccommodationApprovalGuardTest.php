<?php

/**
 * Learniq ExamAccommodationApprovalGuard unit tests.
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
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-exam-accommodations-are-recorded-as-approved-evidence-backed-entitlements
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\ExamAccommodationApprovalGuard;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for ExamAccommodationApprovalGuard::check() — the `approve` transition.
 *
 * approvedBy is StampTransitionActorAction's write (learniq#983), see
 * tests/Unit/Lifecycle/Action/StampTransitionActorActionTest.php.
 */
class ExamAccommodationApprovalGuardTest extends TestCase {

	/**
	 * The accommodation as the guard sees it on the approve transition.
	 *
	 * @return array<string,mixed>
	 */
	private function accommodation(): array {
		return [
			'id' => 'accommodation-1',
			'learnerId' => 'learner-1',
			'accommodationKind' => 'extra-time',
			'tenant_id' => 'tenant-a',
			'lifecycle' => 'approved',
		];
	}//end accommodation()

	/**
	 * Build a guard whose group/user managers report the given group
	 * membership for a known 'actor-1' user.
	 *
	 * @param string[] $groups Group IDs 'actor-1' belongs to.
	 *
	 * @return ExamAccommodationApprovalGuard
	 */
	private function makeGuard(array $groups): ExamAccommodationApprovalGuard {
		$user = $this->createMock(IUser::class);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			static function (string $uid) use ($user): ?IUser {
				return $uid === 'actor-1' ? $user : null;
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		return new ExamAccommodationApprovalGuard($groupManager, $userManager, new NullLogger());
	}//end makeGuard()

	/**
	 * OpenRegister's registry refuses a guard that does not implement its interface.
	 *
	 * @return void
	 */
	public function testImplementsTheOpenRegisterGuardInterface(): void {
		self::assertInstanceOf(LifecycleGuardInterface::class, $this->makeGuard([]));

	}//end testImplementsTheOpenRegisterGuardInterface()

	/**
	 * A mentor may approve.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-learner-requests-an-accommodation-and-a-mentor-approves-it
	 */
	public function testMentorApprovalIsAllowed(): void {
		self::assertTrue($this->makeGuard(['team-leads'])->check($this->accommodation(), 'approve', 'actor-1')->isAllowed());

	}//end testMentorApprovalIsAllowed()

	/**
	 * An admin may approve.
	 *
	 * @return void
	 */
	public function testAdminApprovalIsAllowed(): void {
		self::assertTrue($this->makeGuard(['admin'])->check($this->accommodation(), 'approve', 'actor-1')->isAllowed());

	}//end testAdminApprovalIsAllowed()

	/**
	 * A compliance-officer may approve.
	 *
	 * @return void
	 */
	public function testComplianceOfficerApprovalIsAllowed(): void {
		self::assertTrue($this->makeGuard(['compliance-officers'])->check($this->accommodation(), 'approve', 'actor-1')->isAllowed());

	}//end testComplianceOfficerApprovalIsAllowed()

	/**
	 * A learner (no privileged group) cannot self-approve.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-learner-cannot-self-approve-their-own-accommodation
	 */
	public function testLearnerCannotSelfApprove(): void {
		$result = $this->makeGuard([])->check($this->accommodation(), 'approve', 'actor-1');

		self::assertFalse($result->isAllowed());
		self::assertNotSame('', (string)$result->getMessage());

	}//end testLearnerCannotSelfApprove()

	/**
	 * An unknown user is denied.
	 *
	 * @return void
	 */
	public function testUnknownUserIsDenied(): void {
		self::assertFalse($this->makeGuard(['admin'])->check($this->accommodation(), 'approve', 'ghost')->isAllowed());

	}//end testUnknownUserIsDenied()

	/**
	 * No session user is denied.
	 *
	 * @return void
	 */
	public function testNoActorIsDenied(): void {
		self::assertFalse($this->makeGuard(['admin'])->check($this->accommodation(), 'approve', '')->isAllowed());

	}//end testNoActorIsDenied()
}//end class
