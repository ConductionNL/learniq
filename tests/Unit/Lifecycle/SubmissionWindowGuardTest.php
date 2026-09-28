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
use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * SubmissionWindowGuard judges both hand-in transitions: `submit` (draft to
 * submitted) inside the window, `submitLate` (draft to late) after it. A guard
 * can not redirect the target state (learniq#983), so each transition answers
 * only for its own side of the deadline.
 *
 * @spec openspec/specs/assignments/spec.md#requirement-a-learner-hands-in-their-own-work-and-the-teacher-marks-it
 */
class SubmissionWindowGuardTest extends TestCase {

	use GuardVerdicts;

	/**
	 * Build the guard with one assignment on file.
	 *
	 * @param array<string,mixed>|null $assignment The assignment the lookup returns, or null for none.
	 * @param bool                     $isAdmin    Whether the caller is an administrator.
	 *
	 * @return SubmissionWindowGuard
	 */
	private function makeGuard(?array $assignment, bool $isAdmin = false): SubmissionWindowGuard {
		$objectService = $this->createMock(ObjectService::class);
		$rows = [];
		if ($assignment !== null) {
			$rows = [$assignment];
		}

		$objectService->method('findAll')->willReturn($rows);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		return new SubmissionWindowGuard(
			objectService: $objectService,
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $groups
		);
	}//end makeGuard()

	/**
	 * A draft Submission as OpenRegister hands it to the guard: at its target state.
	 *
	 * @param string $target The target lifecycle state of the transition.
	 *
	 * @return array<string,mixed>
	 */
	private function submission(string $target): array {
		return [
			'id' => 'submission-1',
			'assignmentId' => 'assignment-1',
			'learnerIds' => ['alice'],
			'lifecycle' => $target,
		];
	}//end submission()

	/**
	 * An assignment whose deadline is an hour away or an hour gone.
	 *
	 * @param string    $offset    A relative time for dueAt, or '' for no deadline.
	 * @param bool|null $allowLate The allowLateSubmission flag, or null to leave it out.
	 *
	 * @return array<string,mixed>
	 */
	private function assignment(string $offset, ?bool $allowLate = null): array {
		$assignment = ['id' => 'assignment-1', 'dueAt' => null];
		if ($offset !== '') {
			$assignment['dueAt'] = (new \DateTimeImmutable($offset, new \DateTimeZone('UTC')))->format(DATE_ATOM);
		}

		if ($allowLate !== null) {
			$assignment['allowLateSubmission'] = $allowLate;
		}

		return $assignment;
	}//end assignment()

	/**
	 * OpenRegister can run the guard at all.
	 *
	 * @return void
	 */
	public function testImplementsTheInterfaceOpenRegisterRuns(): void {
		self::assertInstanceOf(LifecycleGuardInterface::class, $this->makeGuard(null));
	}//end testImplementsTheInterfaceOpenRegisterRuns()

	/**
	 * The named learner hands in on time.
	 *
	 * @return void
	 */
	public function testNamedLearnerMaySubmit(): void {
		self::assertAllowed($this->makeGuard($this->assignment(''))->check($this->submission('submitted'), 'submit', 'alice'));
	}//end testNamedLearnerMaySubmit()

	/**
	 * Nobody hands in in another learner's name, on time or late.
	 *
	 * @return void
	 */
	public function testSomeoneElseMayNotSubmitInTheLearnersName(): void {
		self::assertDenied($this->makeGuard($this->assignment(''))->check($this->submission('submitted'), 'submit', 'mallory'));
		self::assertDenied(
			$this->makeGuard($this->assignment('-1 hour', true))->check($this->submission('late'), 'submitLate', 'mallory')
		);
	}//end testSomeoneElseMayNotSubmitInTheLearnersName()

	/**
	 * Administrators and system calls (no caller) are not refused for the learner check.
	 *
	 * @return void
	 */
	public function testAdminAndSystemCallsAreNotRefused(): void {
		self::assertAllowed($this->makeGuard($this->assignment(''), true)->check($this->submission('submitted'), 'submit', 'root'));
		self::assertAllowed($this->makeGuard($this->assignment(''))->check($this->submission('submitted'), 'submit', ''));
	}//end testAdminAndSystemCallsAreNotRefused()

	/**
	 * Inside the window, `submit` passes and `submitLate` is refused.
	 *
	 * @return void
	 */
	public function testInsideTheWindowOnlySubmitPasses(): void {
		$guard = $this->makeGuard($this->assignment('+1 hour', true));

		self::assertAllowed($guard->check($this->submission('submitted'), 'submit', 'alice'));
		self::assertDenied($guard->check($this->submission('late'), 'submitLate', 'alice'));
	}//end testInsideTheWindowOnlySubmitPasses()

	/**
	 * After the window, `submit` is refused and `submitLate` lands the work in `late`.
	 *
	 * @return void
	 */
	public function testAfterTheWindowOnlySubmitLatePasses(): void {
		$guard = $this->makeGuard($this->assignment('-1 hour', true));

		self::assertDenied($guard->check($this->submission('submitted'), 'submit', 'alice'));
		self::assertAllowed($guard->check($this->submission('late'), 'submitLate', 'alice'));
	}//end testAfterTheWindowOnlySubmitLatePasses()

	/**
	 * After the window of an assignment that takes no late work, both are refused.
	 *
	 * @return void
	 */
	public function testNoLateWorkRefusesBothAfterTheWindow(): void {
		$guard = $this->makeGuard($this->assignment('-1 hour', false));

		self::assertDenied($guard->check($this->submission('submitted'), 'submit', 'alice'));
		self::assertDenied($guard->check($this->submission('late'), 'submitLate', 'alice'));
	}//end testNoLateWorkRefusesBothAfterTheWindow()

	/**
	 * An assignment without a deadline has no late hand-in.
	 *
	 * @return void
	 */
	public function testNoDeadlineHasNoLateHandIn(): void {
		self::assertDenied($this->makeGuard($this->assignment(''))->check($this->submission('late'), 'submitLate', 'alice'));
	}//end testNoDeadlineHasNoLateHandIn()

	/**
	 * A missing assignment or a malformed deadline blocks the hand-in.
	 *
	 * @return void
	 */
	public function testMissingAssignmentOrMalformedDeadlineBlocks(): void {
		self::assertDenied($this->makeGuard(null)->check($this->submission('submitted'), 'submit', 'alice'));
		self::assertDenied(
			$this->makeGuard(['id' => 'assignment-1', 'dueAt' => 'not a date'])->check($this->submission('submitted'), 'submit', 'alice')
		);
	}//end testMissingAssignmentOrMalformedDeadlineBlocks()
	/**
	 * A reopened Submission carrying a resubmission date.
	 *
	 * @param string $target The target lifecycle state.
	 * @param string $offset A relative time for resubmissionDueAt.
	 *
	 * @return array<string,mixed>
	 */
	private function resubmission(string $target, string $offset): array {
		return array_merge(
			$this->submission($target),
			['resubmissionDueAt' => (new \DateTimeImmutable($offset, new \DateTimeZone('UTC')))->format(DATE_ATOM)]
		);
	}//end resubmission()

	/**
	 * Work handed in again after the assignment deadline is on time while the
	 * resubmission date has not passed, even when the assignment takes no late work.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-a-requested-resubmission-has-its-own-deadline
	 */
	public function testResubmissionAfterTheAssignmentDeadlineIsOnTime(): void {
		$guard = $this->makeGuard($this->assignment('-3 days', false));

		self::assertAllowed($guard->check($this->resubmission('submitted', '+2 days'), 'submit', 'alice'));
		self::assertDenied($guard->check($this->submission('submitted'), 'submit', 'alice'));
	}//end testResubmissionAfterTheAssignmentDeadlineIsOnTime()

	/**
	 * Once the resubmission date has passed, the late rules apply to it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-a-requested-resubmission-has-its-own-deadline
	 */
	public function testAPassedResubmissionDateFollowsTheLateRules(): void {
		$lateOk = $this->makeGuard($this->assignment('-3 days', true));
		$noLate = $this->makeGuard($this->assignment('-3 days', false));

		self::assertDenied($lateOk->check($this->resubmission('submitted', '-1 hour'), 'submit', 'alice'));
		self::assertAllowed($lateOk->check($this->resubmission('late', '-1 hour'), 'submitLate', 'alice'));
		self::assertDenied($noLate->check($this->resubmission('late', '-1 hour'), 'submitLate', 'alice'));
	}//end testAPassedResubmissionDateFollowsTheLateRules()

	/**
	 * Late hand-in is refused while the resubmission window is still open.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-a-requested-resubmission-has-its-own-deadline
	 */
	public function testSubmitLateIsRefusedInsideTheResubmissionWindow(): void {
		$guard = $this->makeGuard($this->assignment('-3 days', true));

		self::assertDenied($guard->check($this->resubmission('late', '+2 days'), 'submitLate', 'alice'));
	}//end testSubmitLateIsRefusedInsideTheResubmissionWindow()

	/**
	 * A resubmission date does not let someone else hand in the work.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-a-requested-resubmission-has-its-own-deadline
	 */
	public function testAResubmissionDateDoesNotWidenWhoMayHandIn(): void {
		$guard = $this->makeGuard($this->assignment('-3 days', false));

		self::assertDenied($guard->check($this->resubmission('submitted', '+2 days'), 'submit', 'mallory'));
	}//end testAResubmissionDateDoesNotWidenWhoMayHandIn()

	/**
	 * The register gives late hand-in its own guarded transition into `late`.
	 *
	 * @return void
	 */
	public function testRegisterDeclaresSubmitLateIntoLate(): void {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true,
			flags: JSON_THROW_ON_ERROR
		);
		$transitions = $register['components']['schemas']['Submission']['x-openregister-lifecycle']['transitions'];

		self::assertSame('submitted', $transitions['submit']['to']);
		self::assertSame('late', $transitions[SubmissionWindowGuard::LATE_ACTION]['to'] ?? null);
		self::assertSame('draft', $transitions[SubmissionWindowGuard::LATE_ACTION]['from'] ?? null);
		self::assertSame(SubmissionWindowGuard::class, $transitions[SubmissionWindowGuard::LATE_ACTION]['requires'] ?? null);
	}//end testRegisterDeclaresSubmitLateIntoLate()
}//end class
