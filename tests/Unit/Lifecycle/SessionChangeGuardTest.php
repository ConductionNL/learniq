<?php

/**
 * Learniq SessionChangeGuard unit tests.
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
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-substitution-and-cancellation-require-a-reason-and-are-gated-by-sessionchangeguard
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Lifecycle\SessionChangeGuard;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for SessionChangeGuard::check() — the Session cancel /
 * substitute-teacher / substitute-teacher-in-progress transitions.
 */
class SessionChangeGuardTest extends TestCase {

	use GuardVerdicts;

	/**
	 * Build a guard whose group/user managers report the given group
	 * membership for a known 'actor-1' user, and whose ObjectService returns
	 * the given Cohort row for any cohort query.
	 *
	 * @param string[] $groups Group IDs 'actor-1' belongs to.
	 * @param array<string,mixed>|null $cohort Cohort row to return, or null for "not found".
	 *
	 * @return SessionChangeGuard
	 */
	private function makeGuard(array $groups, ?array $cohort): SessionChangeGuard {
		$user = $this->createMock(IUser::class);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			static function (string $uid) use ($user): ?IUser {
				return $uid === 'actor-1' ? $user : null;
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($cohort): array {
				if (($config['filters']['schema'] ?? '') === 'cohort') {
					return $cohort === null ? [] : [$cohort];
				}
				return [];
			}
		);

		return new SessionChangeGuard($objectService, $groupManager, $userManager, new NullLogger());
	}//end makeGuard()

	/**
	 * A cohort teacher cancels a Session with a reason — allowed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-cohort-teacher-cancels-a-session-with-a-reason
	 */
	public function testCohortTeacherCancelsWithReasonIsAllowed(): void {
		$guard = $this->makeGuard([], ['id' => 'cohort-1', 'teacherIds' => ['actor-1']]);
		$object = ['cohortId' => 'cohort-1', 'tenant_id' => 'tenant-a', 'changeReasonKind' => 'teacher-absence', 'lifecycle' => 'cancelled'];

		self::assertAllowed($guard->check($object, 'cancel', 'actor-1'));

	}//end testCohortTeacherCancelsWithReasonIsAllowed()

	/**
	 * Cancelling without a reason is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-cancelling-without-a-reason-is-refused
	 */
	public function testCancelWithoutReasonIsRefused(): void {
		$guard = $this->makeGuard([], ['id' => 'cohort-1', 'teacherIds' => ['actor-1']]);
		$object = ['cohortId' => 'cohort-1', 'tenant_id' => 'tenant-a', 'lifecycle' => 'cancelled'];

		self::assertDenied($guard->check($object, 'cancel', 'actor-1'));

	}//end testCancelWithoutReasonIsRefused()

	/**
	 * A teacher outside the cohort (and not admin/coordinator) cannot cancel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-teacher-outside-the-cohort-cannot-substitute-or-cancel
	 */
	public function testOutsideTeacherCannotCancel(): void {
		$guard = $this->makeGuard([], ['id' => 'cohort-1', 'teacherIds' => ['someone-else']]);
		$object = ['cohortId' => 'cohort-1', 'tenant_id' => 'tenant-a', 'changeReasonKind' => 'teacher-absence', 'lifecycle' => 'cancelled'];

		self::assertDenied($guard->check($object, 'cancel', 'actor-1'));

	}//end testOutsideTeacherCannotCancel()

	/**
	 * An admin may cancel a Session even without being a cohort teacher.
	 *
	 * @return void
	 */
	public function testAdminMayCancelWithoutCohortMembership(): void {
		$guard = $this->makeGuard(['admin'], ['id' => 'cohort-1', 'teacherIds' => ['someone-else']]);
		$object = ['cohortId' => 'cohort-1', 'tenant_id' => 'tenant-a', 'changeReasonKind' => 'timetable-change', 'lifecycle' => 'cancelled'];

		self::assertAllowed($guard->check($object, 'cancel', 'actor-1'));

	}//end testAdminMayCancelWithoutCohortMembership()

	/**
	 * A coordinator may assign a substitute teacher.
	 *
	 * @return void
	 */
	public function testCoordinatorMaySubstitute(): void {
		$guard = $this->makeGuard(['coordinators'], ['id' => 'cohort-1', 'teacherIds' => []]);
		$object = [
				'cohortId' => 'cohort-1',
				'tenant_id' => 'tenant-a',
				'changeReasonKind' => 'teacher-absence',
				'substituteTeacherId' => 'sub-1',
				'lifecycle' => 'scheduled',
			];

		self::assertAllowed($guard->check($object, 'substitute-teacher', 'actor-1'));

	}//end testCoordinatorMaySubstitute()

	/**
	 * substitute-teacher without substituteTeacherId set is refused.
	 *
	 * @return void
	 */
	public function testSubstituteWithoutTeacherIdIsRefused(): void {
		$guard = $this->makeGuard(['admin'], null);
		$object = ['cohortId' => 'cohort-1', 'tenant_id' => 'tenant-a', 'changeReasonKind' => 'teacher-absence', 'lifecycle' => 'scheduled'];

		self::assertDenied($guard->check($object, 'substitute-teacher', 'actor-1'));

	}//end testSubstituteWithoutTeacherIdIsRefused()

	/**
	 * substitute-teacher-in-progress is gated the same way as substitute-teacher.
	 *
	 * @return void
	 */
	public function testSubstituteInProgressRequiresTeacherId(): void {
		$guard = $this->makeGuard(['admin'], null);
		$object = ['cohortId' => 'cohort-1', 'tenant_id' => 'tenant-a', 'changeReasonKind' => 'teacher-absence', 'lifecycle' => 'in-progress'];

		self::assertDenied($guard->check($object, 'substitute-teacher-in-progress', 'actor-1'));

	}//end testSubstituteInProgressRequiresTeacherId()

	/**
	 * No actor in the transition context is refused.
	 *
	 * @return void
	 */
	public function testNoActorIsRefused(): void {
		$guard = $this->makeGuard(['admin'], null);
		$object = ['cohortId' => 'cohort-1', 'tenant_id' => 'tenant-a', 'changeReasonKind' => 'teacher-absence', 'lifecycle' => 'cancelled'];

		self::assertDenied($guard->check($object, 'cancel', ''));

	}//end testNoActorIsRefused()

	/**
	 * A missing Cohort fails closed even for a changeReasonKind-complete request.
	 *
	 * @return void
	 */
	public function testMissingCohortFailsClosed(): void {
		$guard = $this->makeGuard([], null);
		$object = ['cohortId' => 'cohort-missing', 'tenant_id' => 'tenant-a', 'changeReasonKind' => 'teacher-absence', 'lifecycle' => 'cancelled'];

		self::assertDenied($guard->check($object, 'cancel', 'actor-1'));

	}//end testMissingCohortFailsClosed()
}//end class
