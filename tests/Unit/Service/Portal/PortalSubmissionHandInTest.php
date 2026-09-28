<?php

/**
 * Learniq PortalSubmissionHandIn unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Portal
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
 * @spec openspec/changes/portal-assignment-hand-in-endpoint/specs/assignments/spec.md#requirement-a-pupil-hands-in-a-portal-draft-through-learniqs-own-endpoint
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use Exception;
use OCA\Learniq\Lifecycle\SubmissionWindowGuard;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalSubmissionHandIn;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A portal hand-in, with the REAL SubmissionWindowGuard deciding the window
 * and the late rule; OpenRegister is a double that records every transition,
 * and every transition must run inside runAs() for the pupil.
 */
class PortalSubmissionHandInTest extends TestCase {

	/**
	 * The transitions fired: action and whether it ran as the pupil.
	 *
	 * @var array<int, array{action: string, asPupil: bool}>
	 */
	private array $fired = [];

	/**
	 * Whether the current call runs inside runAs() for the pupil.
	 */
	private bool $asPupil = false;

	/**
	 * The pupil.
	 *
	 * @return PortalLearner
	 */
	private function pupil(): PortalLearner {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pupil-lars');

		return new PortalLearner(profileRef: 'lp-lars', ncUserId: 'pupil-lars', tenantId: 'tenant-1', user: $user);
	}//end pupil()

	/**
	 * A submission row.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return array<string, mixed>
	 */
	private function submission(array $overrides = []): array {
		return array_merge(
			[
				'assignmentId' => 'assignment-1',
				'learnerIds' => ['pupil-lars'],
				'learnerRefs' => ['lp-lars'],
				'learnerRef' => 'lp-lars',
				'lifecycle' => 'draft',
				'tenant_id' => 'tenant-1',
			],
			$overrides
		);
	}//end submission()

	/**
	 * The service over an OpenRegister double holding one submission and one
	 * assignment, with the real guard.
	 *
	 * @param array<string, mixed>|null $submission The stored submission, or null.
	 * @param array<string, mixed> $assignment The assignment it belongs to.
	 * @param \Throwable|null $transitionFails What the transition throws, if anything.
	 *
	 * @return PortalSubmissionHandIn
	 */
	private function service(?array $submission, array $assignment, ?\Throwable $transitionFails = null): PortalSubmissionHandIn {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function (int|string $id) use ($submission): ObjectEntity {
				if ($submission === null || $id !== 'sub-1') {
					throw new DoesNotExistException('gone');
				}

				return (new ObjectEntity())->hydrateObject(['@self' => ['uuid' => 'sub-1']] + $submission);
			}
		);
		$objects->method('findAll')->willReturn([$assignment]);
		$objects->method('runAs')->willReturnCallback(
			function (IUser $user, callable $operation): mixed {
				$this->asPupil = $user->getUID() === 'pupil-lars';
				try {
					return $operation();
				} finally {
					$this->asPupil = false;
				}
			}
		);

		$engine = $this->createMock(TransitionEngine::class);
		$engine->method('transition')->willReturnCallback(
			function (string $objectId, string $action) use ($submission, $transitionFails): ObjectEntity {
				$this->fired[] = ['action' => $action, 'asPupil' => $this->asPupil];
				if ($transitionFails !== null) {
					throw $transitionFails;
				}

				$lifecycle = ($action === 'submitLate') ? 'late' : 'submitted';
				return (new ObjectEntity())->hydrateObject(['@self' => ['uuid' => $objectId], 'lifecycle' => $lifecycle] + ($submission ?? []));
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$guard = new SubmissionWindowGuard($objects, new NullLogger(), $groups);

		return new PortalSubmissionHandIn($objects, $engine, $guard, new NullLogger());
	}//end service()

	/**
	 * An assignment due at an offset from now.
	 *
	 * @param string $offset A relative time, such as `+1 day`.
	 * @param bool $late Whether it accepts late work.
	 *
	 * @return array<string, mixed>
	 */
	private function assignment(string $offset, bool $late = false): array {
		return ['id' => 'assignment-1', 'dueAt' => gmdate('c', strtotime($offset)), 'allowLateSubmission' => $late, 'tenant_id' => 'tenant-1'];
	}//end assignment()

	/**
	 * Inside the window: `submit`, as the pupil.
	 *
	 * @return void
	 */
	public function testADraftInsideTheWindowIsSubmittedAsThePupil(): void {
		$outcome = $this->service($this->submission(), $this->assignment('+1 day'))->handIn($this->pupil(), 'sub-1');

		self::assertSame(200, $outcome->status);
		self::assertSame(['submissionId' => 'sub-1', 'lifecycle' => 'submitted'], $outcome->body);
		self::assertSame([['action' => 'submit', 'asPupil' => true]], $this->fired);
	}//end testADraftInsideTheWindowIsSubmittedAsThePupil()

	/**
	 * After the deadline, on an assignment that takes late work: `submitLate`.
	 *
	 * @return void
	 */
	public function testAfterTheDeadlineLateWorkIsHandedInLate(): void {
		$outcome = $this->service($this->submission(), $this->assignment('-1 day', late: true))->handIn($this->pupil(), 'sub-1');

		self::assertSame(200, $outcome->status);
		self::assertSame('late', $outcome->body['lifecycle']);
		self::assertSame([['action' => 'submitLate', 'asPupil' => true]], $this->fired);
	}//end testAfterTheDeadlineLateWorkIsHandedInLate()

	/**
	 * After the deadline, on an assignment that takes no late work: 422 with
	 * the late reason, and no transition fires.
	 *
	 * @return void
	 */
	public function testAfterTheDeadlineWithoutLateWorkNothingIsWritten(): void {
		$outcome = $this->service($this->submission(), $this->assignment('-1 day'))->handIn($this->pupil(), 'sub-1');

		self::assertSame(422, $outcome->status);
		self::assertSame(['error' => 'late_not_accepted'], $outcome->body);
		self::assertSame('late-not-accepted', $outcome->reason);
		self::assertSame([], $this->fired);
	}//end testAfterTheDeadlineWithoutLateWorkNothingIsWritten()

	/**
	 * A teacher's resubmission date wins over the passed assignment deadline,
	 * because the guard, not the service, reads the window.
	 *
	 * @return void
	 */
	public function testARequestedResubmissionDateReopensTheWindow(): void {
		$submission = $this->submission(['resubmissionDueAt' => gmdate('c', strtotime('+2 days'))]);
		$outcome = $this->service($submission, $this->assignment('-3 days'))->handIn($this->pupil(), 'sub-1');

		self::assertSame(200, $outcome->status);
		self::assertSame([['action' => 'submit', 'asPupil' => true]], $this->fired);
	}//end testARequestedResubmissionDateReopensTheWindow()

	/**
	 * Another pupil's submission, and an id that does not exist, get the same
	 * 404; nothing fires.
	 *
	 * @return void
	 */
	public function testAnotherPupilsSubmissionIs404(): void {
		$foreign = $this->submission(['learnerIds' => ['pupil-sem'], 'learnerRefs' => ['lp-sem'], 'learnerRef' => 'lp-sem']);

		$other = $this->service($foreign, $this->assignment('+1 day'))->handIn($this->pupil(), 'sub-1');
		$missing = $this->service(null, $this->assignment('+1 day'))->handIn($this->pupil(), 'sub-1');
		$empty = $this->service($this->submission(), $this->assignment('+1 day'))->handIn($this->pupil(), '');

		foreach ([$other, $missing, $empty] as $outcome) {
			self::assertSame(404, $outcome->status);
			self::assertSame(['error' => 'not_found'], $outcome->body);
			self::assertSame('submission-not-found', $outcome->reason);
		}

		self::assertSame([], $this->fired);
	}//end testAnotherPupilsSubmissionIs404()

	/**
	 * Group work listing the pupil in learnerIds under another learnerRef is
	 * still theirs to hand in.
	 *
	 * @return void
	 */
	public function testGroupWorkListingThePupilCanBeHandedIn(): void {
		$group = $this->submission(['learnerIds' => ['pupil-sem', 'pupil-lars'], 'learnerRef' => 'lp-sem']);

		self::assertSame(200, $this->service($group, $this->assignment('+1 day'))->handIn($this->pupil(), 'sub-1')->status);
	}//end testGroupWorkListingThePupilCanBeHandedIn()

	/**
	 * A submission already handed in is 409; nothing fires.
	 *
	 * @return void
	 */
	public function testASubmissionThatIsNotADraftIs409(): void {
		foreach (['submitted', 'late', 'returned'] as $lifecycle) {
			$outcome = $this->service($this->submission(['lifecycle' => $lifecycle]), $this->assignment('+1 day'))->handIn($this->pupil(), 'sub-1');

			self::assertSame(409, $outcome->status, $lifecycle);
			self::assertSame(['error' => 'already_handed_in'], $outcome->body);
		}

		self::assertSame([], $this->fired);
	}//end testASubmissionThatIsNotADraftIs409()

	/**
	 * The guard refusing on the write (the deadline passed in between) is the
	 * same 422; any other failure propagates for the controller's 502.
	 *
	 * @return void
	 */
	public function testARefusalOnTheWriteIsAnsweredAndOtherFailuresPropagate(): void {
		$denied = new class('rejected') extends Exception {
			/**
			 * @return array<string, string>
			 */
			public function getErrors(): array {
				return ['code' => 'lifecycle-guard-denied', 'message' => SubmissionWindowGuard::DENY_ONLY_LATE];
			}
		};

		$outcome = $this->service($this->submission(), $this->assignment('+1 day'), $denied)->handIn($this->pupil(), 'sub-1');
		self::assertSame(422, $outcome->status);
		self::assertSame(['error' => 'hand_in_refused'], $outcome->body);

		$this->expectException(RuntimeException::class);
		$this->service($this->submission(), $this->assignment('+1 day'), new RuntimeException('database gone'))->handIn($this->pupil(), 'sub-1');
	}//end testARefusalOnTheWriteIsAnsweredAndOtherFailuresPropagate()
}//end class
