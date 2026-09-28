<?php

/**
 * Tests for the in-app attempt time limit: the start stays fixed, and after
 * the deadline plus the grace the answers stop changing.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/in-app-test-limits-server-side/specs/assessment/spec.md#requirement-the-in-app-test-screen-enforces-attempts-and-time-on-the-server
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use DateTime;
use OCA\Learniq\AppInfo\Registrar\EventListenerWiring;
use OCA\Learniq\Listener\AssessmentAttemptTimeLimitListener;
use OCA\Learniq\Service\AssessmentAccessPolicy;
use OCA\Learniq\Service\AssessmentAttemptLimits;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\Portal\PortalAttemptClock;
use OCA\Learniq\Service\Portal\PortalAttemptReader;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for AssessmentAttemptTimeLimitListener::handle().
 */
class AssessmentAttemptTimeLimitListenerTest extends TestCase {

	/**
	 * Assessment rows by id; a 30-minute test by default.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $exams = ['a1' => ['id' => 'a1', 'title' => 'Toets', 'timeLimitMinutes' => 30]];

	/**
	 * The learner's ExamAccommodation rows.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $accommodations = [];

	/**
	 * The server's current time.
	 *
	 * @var string
	 */
	private string $now = '2026-09-27T09:10:00+00:00';

	/**
	 * Build the listener for a caller.
	 *
	 * @param string $uid The caller's user id, or '' for no session.
	 * @param bool $isAdmin Whether the caller is an instance admin.
	 *
	 * @return AssessmentAttemptTimeLimitListener
	 */
	private function makeListener(string $uid = 'learner1', bool $isAdmin = false): AssessmentAttemptTimeLimitListener {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn('assessment-result');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($uid === '' ? null : $user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) {
				if ($schema !== 'exam' || isset($this->exams[$id]) === false) {
					throw new DoesNotExistException('not found');
				}

				return OrEntityFactory::make($this->exams[$id], 'exam');
			}
		);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = []): array => (($config['filters']['schema'] ?? '') === 'exam-accommodation') ? $this->accommodations : []
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn (): DateTime => new DateTime($this->now));

		return new AssessmentAttemptTimeLimitListener(
			schemaResolver: $resolver,
			userSession: $session,
			groupManager: $groups,
			limits: new AssessmentAttemptLimits(
				attempts: new PortalAttemptReader(objectService: $objects),
				clock: new PortalAttemptClock(),
				policy: new AssessmentAccessPolicy(),
				timeFactory: $time,
			),
			logger: new NullLogger(),
		);
	}//end makeListener()

	/**
	 * An attempt in progress, started at 09:00, with one answer.
	 *
	 * @return array<string,mixed>
	 */
	private function inProgress(): array {
		return [
			'id' => 'r1',
			'assessmentId' => 'a1',
			'learnerId' => 'learner1',
			'attemptNumber' => 1,
			'tenant_id' => 't1',
			'lifecycle' => 'in-progress',
			'startedAt' => '2026-09-27T09:00:00+00:00',
			'submittedAt' => null,
			'responses' => [['itemId' => 'mc', 'response' => ['value' => 'A'], 'autoScore' => null, 'manualScore' => null]],
		];
	}//end inProgress()

	/**
	 * The attempt with the answer changed and a second one added.
	 *
	 * @param array<string,mixed> $old The stored attempt.
	 *
	 * @return array<string,mixed>
	 */
	private function answeredMore(array $old): array {
		$new = $old;
		$new['responses'] = [
			['itemId' => 'mc', 'response' => ['value' => 'C'], 'autoScore' => null, 'manualScore' => null],
			['itemId' => 'essay', 'response' => ['value' => 'Late text'], 'autoScore' => null, 'manualScore' => null],
		];
		return $new;
	}//end answeredMore()

	/**
	 * Run an update through the listener.
	 *
	 * @param AssessmentAttemptTimeLimitListener $listener The listener.
	 * @param array<string,mixed> $old The stored object.
	 * @param array<string,mixed> $new The object as it would be saved.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function update(AssessmentAttemptTimeLimitListener $listener, array $old, array $new): ObjectUpdatingEvent {
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make($new, 'assessment-result'),
			OrEntityFactory::make($old, 'assessment-result')
		);
		$listener->handle($event);
		return $event;
	}//end update()

	/**
	 * A learner cannot move when their attempt started, or its number.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/in-app-test-limits-server-side/specs/assessment/spec.md#scenario-the-server-starts-the-clock
	 */
	public function testTheLearnerCannotMoveTheStartOrTheNumber(): void {
		$old = $this->inProgress();
		$later = $old;
		$later['startedAt'] = '2026-09-27T09:25:00+00:00';
		$renumbered = $old;
		$renumbered['attemptNumber'] = 5;

		$event = $this->update($this->makeListener(), $old, $later);
		self::assertTrue($event->isPropagationStopped());
		self::assertSame('assessment-result-start-fixed', $event->getErrors()['reason']);
		self::assertTrue($this->update($this->makeListener(), $old, $renumbered)->isPropagationStopped());
	}//end testTheLearnerCannotMoveTheStartOrTheNumber()

	/**
	 * After the deadline plus the grace, answers no longer change: the save
	 * goes through with the stored answers.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/in-app-test-limits-server-side/specs/assessment/spec.md#scenario-answers-after-the-deadline-are-not-saved
	 */
	public function testAnswersAfterTheDeadlineAreNotSaved(): void {
		$old = $this->inProgress();
		$this->now = '2026-09-27T09:30:31+00:00';

		$event = $this->update($this->makeListener(), $old, $this->answeredMore($old));

		self::assertFalse($event->isPropagationStopped());
		self::assertSame($old['responses'], $event->getModifiedData()['responses']);
	}//end testAnswersAfterTheDeadlineAreNotSaved()

	/**
	 * A hand-in after the deadline is accepted with the answers stored in time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/in-app-test-limits-server-side/specs/assessment/spec.md#scenario-a-late-hand-in-keeps-the-answers-given-in-time
	 */
	public function testALateHandInKeepsTheAnswersGivenInTime(): void {
		$old = $this->inProgress();
		$this->now = '2026-09-27T09:40:00+00:00';
		$submit = $this->answeredMore($old);
		$submit['lifecycle'] = 'submitted';
		$submit['submittedAt'] = '2026-09-27T09:40:00+00:00';

		$event = $this->update($this->makeListener(), $old, $submit);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame($old['responses'], $event->getModifiedData()['responses']);
	}//end testALateHandInKeepsTheAnswersGivenInTime()

	/**
	 * The submit save adds auto scores to unchanged answers; after the
	 * deadline (the portal hands a late attempt in after it) the scores stay.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/in-app-test-limits-server-side/specs/assessment/spec.md#scenario-a-late-hand-in-keeps-the-answers-given-in-time
	 */
	public function testScoringALateHandInKeepsTheScores(): void {
		$old = $this->inProgress();
		$this->now = '2026-09-27T09:50:00+00:00';
		$scored = $old;
		$scored['lifecycle'] = 'submitted';
		$scored['responses'][0]['autoScore'] = 1;

		$event = $this->update($this->makeListener(), $old, $scored);

		self::assertFalse($event->isPropagationStopped());
		self::assertArrayNotHasKey('responses', $event->getModifiedData());
	}//end testScoringALateHandInKeepsTheScores()

	/**
	 * Inside the grace, and inside extra time from an approved accommodation,
	 * answers are saved as sent; an untimed test has no deadline.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/in-app-test-limits-server-side/specs/assessment/spec.md#scenario-answers-after-the-deadline-are-not-saved
	 */
	public function testAnswersInsideTheGraceOrExtraTimeAreSaved(): void {
		$old = $this->inProgress();
		$listener = $this->makeListener();

		$this->now = '2026-09-27T09:30:20+00:00';
		self::assertArrayNotHasKey('responses', $this->update($listener, $old, $this->answeredMore($old))->getModifiedData());

		$this->accommodations = [['learnerId' => 'learner1', 'accommodationKind' => 'extra-time-percentage', 'value' => 50, 'lifecycle' => 'approved']];
		$this->now = '2026-09-27T09:44:00+00:00';
		self::assertArrayNotHasKey('responses', $this->update($listener, $old, $this->answeredMore($old))->getModifiedData());

		$this->accommodations = [];
		$this->exams['a1'] = ['id' => 'a1', 'title' => 'Toets'];
		$this->now = '2026-09-28T09:00:00+00:00';
		self::assertArrayNotHasKey('responses', $this->update($listener, $old, $this->answeredMore($old))->getModifiedData());
	}//end testAnswersInsideTheGraceOrExtraTimeAreSaved()

	/**
	 * Admins and system writes are not held to it; another learner's write is
	 * left to the integrity listener, which refuses it.
	 *
	 * @return void
	 */
	public function testAdminsSystemAndOthersAreNotTouched(): void {
		$old = $this->inProgress();
		$this->now = '2026-09-27T10:30:00+00:00';
		$late = $this->answeredMore($old);

		self::assertSame([], $this->update($this->makeListener(uid: 'boss', isAdmin: true), $old, $late)->getModifiedData());
		self::assertSame([], $this->update($this->makeListener(uid: ''), $old, $late)->getModifiedData());
		self::assertSame([], $this->update($this->makeListener(uid: 'learner2'), $old, $late)->getModifiedData());
	}//end testAdminsSystemAndOthersAreNotTouched()

	/**
	 * The listener is registered on the updating event, so a write meets it:
	 * a rule with a full test suite and no registration never runs.
	 *
	 * @return void
	 */
	public function testTheListenerIsRegistered(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		(new EventListenerWiring())->registerAll($context);

		self::assertContains([ObjectUpdatingEvent::class, AssessmentAttemptTimeLimitListener::class], $registered);
	}//end testTheListenerIsRegistered()
}//end class
