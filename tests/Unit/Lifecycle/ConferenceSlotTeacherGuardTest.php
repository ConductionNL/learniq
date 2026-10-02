<?php

/**
 * ConferenceSlotTeacherGuard test.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\ConferenceSlotTeacherGuard;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * Only the slot's teacher, or staff who may stand in, answers a booking.
 */
class ConferenceSlotTeacherGuardTest extends TestCase {

	/**
	 * The slot's own teacher may acknowledge and decline.
	 *
	 * @return void
	 */
	public function testTheSlotsTeacherAnswers(): void {
		$this->assertTrue($this->guard([])->check(['teacherId' => 'po-leerkracht-09'], 'acknowledge', 'po-leerkracht-09')->isAllowed());
		$this->assertTrue($this->guard([])->check(['teacherId' => 'po-leerkracht-09'], 'decline', 'po-leerkracht-09')->isAllowed());
	}//end testTheSlotsTeacherAnswers()

	/**
	 * Another teacher, or a call without a user, is refused.
	 *
	 * @return void
	 */
	public function testAnotherTeacherOrNoUserIsRefused(): void {
		$other = $this->guard([])->check(['teacherId' => 'po-leerkracht-09'], 'decline', 'po-leerkracht-10');
		$this->assertFalse($other->isAllowed());
		$this->assertNotSame('', (string)$other->getMessage());
		$this->assertFalse($this->guard([])->check(['teacherId' => 'po-leerkracht-09'], 'acknowledge', '')->isAllowed());
	}//end testAnotherTeacherOrNoUserIsRefused()

	/**
	 * A coordinator may answer for an absent colleague.
	 *
	 * @return void
	 */
	public function testACoordinatorMayStandIn(): void {
		$this->assertTrue($this->guard(['po-ib-01' => ['coordinators']])->check(['teacherId' => 'po-leerkracht-09'], 'acknowledge', 'po-ib-01')->isAllowed());
	}//end testACoordinatorMayStandIn()

	/**
	 * The guard over a group membership map.
	 *
	 * @param array<string, array<int, string>> $memberships Groups per uid.
	 *
	 * @return ConferenceSlotTeacherGuard
	 */
	private function guard(array $memberships): ConferenceSlotTeacherGuard {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => in_array($group, ($memberships[$uid] ?? []), true)
		);

		return new ConferenceSlotTeacherGuard($groups);
	}//end guard()
}//end class
