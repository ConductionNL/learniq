<?php

/**
 * Unit tests for SubmissionWindowGuard.
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
 * @spec openspec/specs/assignments/spec.md#requirement-a-learner-hands-in-their-own-work-and-the-teacher-marks-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\SubmissionWindowGuard;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the submit guard: only a learner named on the submission hands it in.
 */
class SubmissionWindowGuardTest extends TestCase {

	/**
	 * Build a guard whose assignment is open-ended and whose caller is `$uid`.
	 *
	 * @param string|null $uid     The signed-in user, or null for a system call.
	 * @param bool        $isAdmin Whether the caller is an administrator.
	 *
	 * @return SubmissionWindowGuard
	 */
	private function makeGuard(?string $uid, bool $isAdmin = false): SubmissionWindowGuard {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([['id' => 'assignment-1', 'dueAt' => null]]);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		return new SubmissionWindowGuard(
			objectService: $objectService,
			logger: $this->createMock(LoggerInterface::class),
			userSession: $session,
			groupManager: $groups
		);
	}//end makeGuard()

	/**
	 * The submission under test, handed in for learner `alice`.
	 *
	 * @return array<string, mixed>
	 */
	private function context(): array {
		return [
			'object' => ['id' => 'submission-1', 'assignmentId' => 'assignment-1', 'learnerIds' => ['alice']],
			'transition' => 'submit',
			'from' => 'draft',
			'to' => 'submitted',
		];
	}//end context()

	/**
	 * The learner named on the submission hands it in.
	 *
	 * @return void
	 */
	public function testNamedLearnerMaySubmit(): void {
		$context = $this->context();
		$this->assertTrue($this->makeGuard('alice')->check($context));
	}//end testNamedLearnerMaySubmit()

	/**
	 * Anyone may create a submission, so someone who names another learner on
	 * it is refused at submit: a hand-in is never made in someone else's name.
	 *
	 * @return void
	 */
	public function testSomeoneElseMayNotSubmitInTheLearnersName(): void {
		$context = $this->context();
		$this->assertFalse($this->makeGuard('mallory')->check($context));
	}//end testSomeoneElseMayNotSubmitInTheLearnersName()

	/**
	 * An administrator and a system call (no session) are not refused.
	 *
	 * @return void
	 */
	public function testAdminAndSystemCallsAreNotRefused(): void {
		$context = $this->context();
		$this->assertTrue($this->makeGuard('root', true)->check($context));

		$context = $this->context();
		$this->assertTrue($this->makeGuard(null)->check($context));
	}//end testAdminAndSystemCallsAreNotRefused()
}//end class
