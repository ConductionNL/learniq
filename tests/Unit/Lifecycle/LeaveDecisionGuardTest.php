<?php

/**
 * Tests for LeaveDecisionGuard: who decides on leave, which decision the
 * number of school days allows, and the reason a refusal needs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/leave-requests-decided-by-the-school/specs/attendance/spec.md#requirement-a-leave-request-is-asked-in-advance-and-decided-by-the-head-of-the-school
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\LeaveDecisionGuard;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Lifecycle\LeaveDecisionGuard
 */
class LeaveDecisionGuardTest extends TestCase {

	/**
	 * A guard where only `directeur` is a decider.
	 *
	 * @return LeaveDecisionGuard
	 */
	private function guard(): LeaveDecisionGuard {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $userId, string $group): bool => $userId === 'directeur' && $group === LeaveDecisionGuard::DECIDERS
		);

		return new LeaveDecisionGuard(groupManager: $groups);
	}//end guard()

	/**
	 * The verdict as [allowed, message].
	 *
	 * @param array<string, mixed> $object The request.
	 * @param string               $action The transition.
	 * @param string               $userId The acting user.
	 *
	 * @return array{0: bool, 1: string|null}
	 */
	private function verdict(array $object, string $action, string $userId = 'directeur'): array {
		$result = $this->guard()->check(object: $object, action: $action, userId: $userId);

		return [$result->isAllowed(), $result->getMessage()];
	}//end verdict()

	/**
	 * The directeur allows a two-day wedding, and refuses one with a reason.
	 *
	 * @return void
	 */
	public function testTheDirecteurDecidesUpToTenSchoolDays(): void {
		$this->assertSame([true, null], $this->verdict(object: ['schoolDays' => 2], action: 'allow'));
		$this->assertSame([true, null], $this->verdict(object: ['schoolDays' => 10, 'decisionNote' => 'Geen gewichtige omstandigheid.'], action: 'refuse'));
		$this->assertSame([true, null], $this->verdict(object: ['schoolDays' => '3'], action: 'allow'));
	}//end testTheDirecteurDecidesUpToTenSchoolDays()

	/**
	 * A refusal without a reason is refused with the words the decider reads.
	 *
	 * @return void
	 */
	public function testARefusalWithoutAReasonIsNotSaved(): void {
		$this->assertSame([false, LeaveDecisionGuard::NO_REASON], $this->verdict(object: ['schoolDays' => 2], action: 'refuse'));
		$this->assertSame([false, LeaveDecisionGuard::NO_REASON], $this->verdict(object: ['schoolDays' => 2, 'decisionNote' => "  \n"], action: 'refuse'));
		$this->assertSame('Afwijzen zonder reden kan niet: de ouders lezen uw reden in het bericht.', LeaveDecisionGuard::NO_REASON);
	}//end testARefusalWithoutAReasonIsNotSaved()

	/**
	 * Twelve school days can only be forwarded; up to ten cannot be.
	 *
	 * @return void
	 */
	public function testMoreThanTenSchoolDaysIsForwardedOnly(): void {
		$this->assertSame([false, LeaveDecisionGuard::ABOVE_LIMIT], $this->verdict(object: ['schoolDays' => 12], action: 'allow'));
		$this->assertSame([false, LeaveDecisionGuard::ABOVE_LIMIT], $this->verdict(object: ['schoolDays' => 11, 'decisionNote' => 'Te lang.'], action: 'refuse'));
		$this->assertSame([true, null], $this->verdict(object: ['schoolDays' => 12], action: 'forward'));
		$this->assertSame([false, LeaveDecisionGuard::WITHIN_LIMIT], $this->verdict(object: ['schoolDays' => 10], action: 'forward'));
	}//end testMoreThanTenSchoolDaysIsForwardedOnly()

	/**
	 * Only a decider decides, and an unknown number of school days decides nothing.
	 *
	 * @return void
	 */
	public function testOnlyADeciderDecidesAndOnlyOnKnownDays(): void {
		$this->assertSame([false, LeaveDecisionGuard::NOT_A_DECIDER], $this->verdict(object: ['schoolDays' => 2], action: 'allow', userId: 'leerkracht'));
		$this->assertSame([false, LeaveDecisionGuard::NOT_A_DECIDER], $this->verdict(object: ['schoolDays' => 12], action: 'forward', userId: ''));
		$this->assertSame([false, LeaveDecisionGuard::DAYS_UNKNOWN], $this->verdict(object: [], action: 'allow'));
		$this->assertSame([false, LeaveDecisionGuard::DAYS_UNKNOWN], $this->verdict(object: ['schoolDays' => 'twee'], action: 'forward'));
	}//end testOnlyADeciderDecidesAndOnlyOnKnownDays()
}//end class
