<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/specs/exam-board/spec.md#requirement-the-committee-establishes-the-results-of-a-sitting
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\ExamSittingEstablishGuard;
use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Learniq\Lifecycle\ExamSittingEstablishGuard
 * @uses   \OCA\Learniq\Lifecycle\ActorGroups
 */
class ExamSittingEstablishGuardTest extends TestCase {

	use GuardVerdicts;

	/**
	 * A member of the exam committee establishes.
	 *
	 * @return void
	 */
	public function testTheCommitteeMayEstablish(): void {
		self::assertAllowed($this->guard(groups: ['compliance-officers'])->check(['id' => 's-1'], 'establish', 'actor-1'));
		self::assertAllowed($this->guard(groups: ['admin'])->check(['id' => 's-1'], 'establish', 'actor-1'));
	}//end testTheCommitteeMayEstablish()

	/**
	 * A teacher outside the committee is refused in words.
	 *
	 * @return void
	 */
	public function testATeacherIsRefused(): void {
		$result = $this->guard(groups: ['instructors'])->check(['id' => 's-1'], 'establish', 'actor-1');

		self::assertDenied($result);
		self::assertSame(expected: 'Only the exam committee can establish the results of a sitting.', actual: $result->getMessage());
	}//end testATeacherIsRefused()

	/**
	 * No acting user, or an unknown one, is refused.
	 *
	 * @return void
	 */
	public function testNoOrAnUnknownActorIsRefused(): void {
		self::assertDenied($this->guard(groups: ['compliance-officers'])->check(['id' => 's-1'], 'establish', ''));
		self::assertDenied($this->guard(groups: ['compliance-officers'])->check(['id' => 's-1'], 'establish', 'someone-else'));
	}//end testNoOrAnUnknownActorIsRefused()

	/**
	 * The guard over an actor in the given groups.
	 *
	 * @param list<string> $groups The actor's groups.
	 *
	 * @return ExamSittingEstablishGuard
	 */
	private function guard(array $groups): ExamSittingEstablishGuard {
		$user        = $this->createMock(originalClassName: IUser::class);
		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			static fn (string $uid): ?IUser => $uid === 'actor-1' ? $user : null
		);
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		return new ExamSittingEstablishGuard(groupManager: $groupManager, userManager: $userManager, logger: new NullLogger());
	}//end guard()
}//end class
