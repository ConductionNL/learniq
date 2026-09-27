<?php

/**
 * Unit tests for AssessmentResultIntegrityListener (learniq#948).
 *
 * AssessmentResult was `appendOnly: true`, and OpenRegister refuses EVERY update
 * on such a schema (ObjectService::saveObject throws AppendOnlyException before
 * anything else runs; the lifecycle TransitionEngine saves through the same
 * path). So a learner could not save answers or submit, and a teacher could not
 * write a manualScore or fire `grade`. These tests pin the replacement: the
 * schema is no longer append-only, and this listener keeps a finished attempt
 * immutable except for the one write a teacher needs.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\AssessmentResultIntegrityListener;
use OCA\Learniq\Service\DashboardRoleService;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the AssessmentResult integrity rules.
 */
class AssessmentResultIntegrityListenerTest extends TestCase {

	/**
	 * Build the listener for a caller.
	 *
	 * @param string $uid The caller's user id, or '' for no session.
	 * @param bool $isAdmin Whether the caller is an instance admin.
	 * @param array<int,string> $groups The caller's groups.
	 * @param string $schemaSlug The schema the event's entity resolves to.
	 *
	 * @return AssessmentResultIntegrityListener
	 */
	private function makeListener(
		string $uid,
		bool $isAdmin = false,
		array $groups = [],
		string $schemaSlug = 'assessment-result',
	): AssessmentResultIntegrityListener {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn($schemaSlug);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($uid === '' ? null : $user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $user, string $group): bool => in_array($group, $groups, true)
		);

		return new AssessmentResultIntegrityListener(
			schemaResolver: $resolver,
			userSession: $session,
			groupManager: $groupManager,
			roles: new DashboardRoleService(groupManager: $groupManager),
			logger: new NullLogger(),
		);
	}//end makeListener()

	/**
	 * A submitted attempt with one open (essay) answer and one auto-scored answer.
	 *
	 * @param array<string,mixed> $override Fields to override.
	 *
	 * @return array<string,mixed>
	 */
	private function submitted(array $override = []): array {
		return array_merge(
			[
				'id' => 'r1',
				'assessmentId' => 'a1',
				'learnerId' => 'learner1',
				'attemptNumber' => 1,
				'tenant_id' => 't1',
				'lifecycle' => 'submitted',
				'submittedAt' => '2026-09-27T09:00:00+00:00',
				'responses' => [
					['itemId' => 'essay', 'response' => ['value' => 'My essay'], 'autoScore' => null, 'manualScore' => null],
					['itemId' => 'mc', 'response' => ['value' => 'B'], 'autoScore' => 1, 'manualScore' => null],
				],
			],
			$override
		);
	}//end submitted()

	/**
	 * Copy of the submitted attempt with a manual score on the essay.
	 *
	 * @param float|int|null $score The manual score.
	 *
	 * @return array<string,mixed>
	 */
	private function scored($score = 3): array {
		$data = $this->submitted();
		$data['responses'][0]['manualScore'] = $score;
		return $data;
	}//end scored()

	/**
	 * Run an update through the listener.
	 *
	 * @param AssessmentResultIntegrityListener $listener The listener.
	 * @param array<string,mixed> $old The stored object.
	 * @param array<string,mixed> $new The object as it would be saved.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function update(AssessmentResultIntegrityListener $listener, array $old, array $new): ObjectUpdatingEvent {
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make($new, 'assessment-result'),
			OrEntityFactory::make($old, 'assessment-result')
		);
		$listener->handle($event);
		return $event;
	}//end update()

	/**
	 * The register no longer marks AssessmentResult append-only, because
	 * OpenRegister refuses every update (answers, submit, manualScore, grade)
	 * on an append-only schema.
	 *
	 * @return void
	 */
	public function testAssessmentResultIsNotAppendOnlySoItsLifecycleCanWrite(): void {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true
		);
		$schema = $register['components']['schemas']['AssessmentResult'];

		self::assertNotTrue($schema['appendOnly'] ?? false);
		self::assertSame('submitted', $schema['x-openregister-lifecycle']['transitions']['grade']['from']);
	}//end testAssessmentResultIsNotAppendOnlySoItsLifecycleCanWrite()

	/**
	 * A teacher writes a manual score on a submitted attempt.
	 *
	 * @return void
	 */
	public function testTeacherMayWriteAManualScoreOnASubmittedAttempt(): void {
		$event = $this->update($this->makeListener('teacher1', groups: ['instructors']), $this->submitted(), $this->scored(3));

		self::assertFalse($event->isPropagationStopped(), json_encode($event->getErrors()));
	}//end testTeacherMayWriteAManualScoreOnASubmittedAttempt()

	/**
	 * A teacher fires `grade` on a fully scored attempt.
	 *
	 * @return void
	 */
	public function testTeacherMayGradeAScoredAttempt(): void {
		$graded = $this->scored(3);
		$graded['lifecycle'] = 'graded';

		$event = $this->update($this->makeListener('teacher1', groups: ['instructors']), $this->scored(3), $graded);

		self::assertFalse($event->isPropagationStopped(), json_encode($event->getErrors()));
	}//end testTeacherMayGradeAScoredAttempt()

	/**
	 * The learner cannot score their own attempt.
	 *
	 * @return void
	 */
	public function testLearnerMayNotScoreTheirOwnSubmittedAttempt(): void {
		$event = $this->update($this->makeListener('learner1'), $this->submitted(), $this->scored(10));

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('assessment-result-finished', $event->getErrors()['reason']);
	}//end testLearnerMayNotScoreTheirOwnSubmittedAttempt()

	/**
	 * A teacher cannot change what the learner answered.
	 *
	 * @return void
	 */
	public function testTeacherMayNotChangeTheAnswers(): void {
		$tampered = $this->scored(3);
		$tampered['responses'][0]['response'] = ['value' => 'A better essay'];

		$event = $this->update($this->makeListener('teacher1', groups: ['instructors']), $this->submitted(), $tampered);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('assessment-result-answers-frozen', $event->getErrors()['reason']);
	}//end testTeacherMayNotChangeTheAnswers()

	/**
	 * A teacher cannot change an auto score.
	 *
	 * @return void
	 */
	public function testTeacherMayNotChangeAnAutoScore(): void {
		$tampered = $this->submitted();
		$tampered['responses'][1]['autoScore'] = 0;

		$event = $this->update($this->makeListener('teacher1', groups: ['instructors']), $this->submitted(), $tampered);

		self::assertTrue($event->isPropagationStopped());
	}//end testTeacherMayNotChangeAnAutoScore()

	/**
	 * A manual score is a non-negative number.
	 *
	 * @return void
	 */
	public function testANegativeManualScoreIsRefused(): void {
		$event = $this->update($this->makeListener('teacher1', groups: ['instructors']), $this->submitted(), $this->scored(-1));

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('assessment-result-invalid-score', $event->getErrors()['reason']);
	}//end testANegativeManualScoreIsRefused()

	/**
	 * A graded attempt is final: its scores no longer change.
	 *
	 * @return void
	 */
	public function testAGradedAttemptIsFinal(): void {
		$graded = $this->scored(3);
		$graded['lifecycle'] = 'graded';
		$rescored = $graded;
		$rescored['responses'][0]['manualScore'] = 5;

		$event = $this->update($this->makeListener('teacher1', groups: ['instructors']), $graded, $rescored);

		self::assertTrue($event->isPropagationStopped());
	}//end testAGradedAttemptIsFinal()

	/**
	 * GradeRollupHandler back-links the GradeEntry onto a graded attempt.
	 *
	 * @return void
	 */
	public function testTheGradeEntryBackLinkIsAllowedOnAGradedAttempt(): void {
		$graded = $this->scored(3);
		$graded['lifecycle'] = 'graded';
		$linked = $graded;
		$linked['gradeEntryId'] = 'g1';

		$event = $this->update($this->makeListener('teacher1', groups: ['instructors']), $graded, $linked);

		self::assertFalse($event->isPropagationStopped(), json_encode($event->getErrors()));
	}//end testTheGradeEntryBackLinkIsAllowedOnAGradedAttempt()

	/**
	 * The learner saves answers and submits their own in-progress attempt.
	 *
	 * @return void
	 */
	public function testLearnerMaySaveAndSubmitTheirOwnAttempt(): void {
		$inProgress = $this->submitted(['lifecycle' => 'in-progress', 'responses' => []]);
		$answered = $this->submitted(['lifecycle' => 'in-progress']);
		$answered['responses'][1]['autoScore'] = null;
		$listener = $this->makeListener('learner1');

		self::assertFalse($this->update($listener, $inProgress, $answered)->isPropagationStopped());
		// The submit transition: AssessmentScoringHandler sets autoScores in the same save.
		self::assertFalse($this->update($listener, $answered, $this->submitted())->isPropagationStopped());
	}//end testLearnerMaySaveAndSubmitTheirOwnAttempt()

	/**
	 * Another user cannot write into someone's in-progress attempt, and the
	 * learner cannot pre-score an answer while it is in progress.
	 *
	 * @return void
	 */
	public function testOthersAndSelfScoringAreRefusedWhileInProgress(): void {
		$inProgress = $this->submitted(['lifecycle' => 'in-progress', 'responses' => []]);
		$answered = $this->submitted(['lifecycle' => 'in-progress']);
		$answered['responses'][1]['autoScore'] = null;
		$selfScored = $answered;
		$selfScored['responses'][0]['autoScore'] = 10;

		self::assertTrue($this->update($this->makeListener('learner2'), $inProgress, $answered)->isPropagationStopped());
		self::assertTrue($this->update($this->makeListener('learner1'), $inProgress, $selfScored)->isPropagationStopped());
	}//end testOthersAndSelfScoringAreRefusedWhileInProgress()

	/**
	 * Admins and background jobs (no session) are not policed.
	 *
	 * @return void
	 */
	public function testAdminsAndSystemWritesBypass(): void {
		$rescored = $this->submitted();
		$rescored['responses'][1]['autoScore'] = 0;

		self::assertFalse($this->update($this->makeListener('root', isAdmin: true), $this->submitted(), $rescored)->isPropagationStopped());
		self::assertFalse($this->update($this->makeListener(''), $this->submitted(), $rescored)->isPropagationStopped());
	}//end testAdminsAndSystemWritesBypass()

	/**
	 * A finished attempt cannot be deleted by anyone but an admin, which keeps
	 * the protection appendOnly gave.
	 *
	 * @return void
	 */
	public function testDeleteIsRefusedForNonAdmins(): void {
		$event = new ObjectDeletingEvent(OrEntityFactory::make($this->submitted(), 'assessment-result'));
		$this->makeListener('learner1')->handle($event);
		self::assertTrue($event->isPropagationStopped());

		$event = new ObjectDeletingEvent(OrEntityFactory::make($this->submitted(), 'assessment-result'));
		$this->makeListener('root', isAdmin: true)->handle($event);
		self::assertFalse($event->isPropagationStopped());
	}//end testDeleteIsRefusedForNonAdmins()

	/**
	 * Other schemas are left alone.
	 *
	 * @return void
	 */
	public function testOtherSchemasAreIgnored(): void {
		$event = $this->update(
			$this->makeListener('learner1', schemaSlug: 'submission'),
			$this->submitted(),
			$this->scored(10)
		);

		self::assertFalse($event->isPropagationStopped());
	}//end testOtherSchemasAreIgnored()
}//end class
