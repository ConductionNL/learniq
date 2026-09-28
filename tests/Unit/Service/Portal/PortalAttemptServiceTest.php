<?php

/**
 * Learniq PortalAttemptService unit tests.
 *
 * Runs the real portal services (catalogue, closer, payload, answer rules,
 * reader, writer, clock, presenter) over an in-memory register that answers
 * like OpenRegister, with a fixed clock. One test per rule of the spec's
 * "A portal attempt follows every test rule inside the endpoints".
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
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

require_once __DIR__ . '/../../../Support/PortalFakeRegister.php';

use DateTime;
use OCA\Learniq\Service\AssessmentAccessPolicy;
use OCA\Learniq\Service\LessonReleaseEvaluator;
use OCA\Learniq\Service\Portal\PortalAnswerRules;
use OCA\Learniq\Service\Portal\PortalAnswerShape;
use OCA\Learniq\Service\Portal\PortalAssessmentCatalogue;
use OCA\Learniq\Service\Portal\PortalAttemptClock;
use OCA\Learniq\Service\Portal\PortalAttemptCloser;
use OCA\Learniq\Service\Portal\PortalAttemptPayload;
use OCA\Learniq\Service\Portal\PortalAttemptReader;
use OCA\Learniq\Service\Portal\PortalAttemptService;
use OCA\Learniq\Service\Portal\PortalAttemptWriter;
use OCA\Learniq\Service\Portal\PortalItemPresenter;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Tests\Support\PortalFakeRegister;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for PortalAttemptService.
 */
class PortalAttemptServiceTest extends TestCase {

	private const TENANT = '11111111-1111-4111-8111-111111111111';
	private const CHOICE_QTI = '<assessmentItem><responseDeclaration><correctResponse><value>SECRET_B</value></correctResponse></responseDeclaration>'
		. '<itemBody><choiceInteraction><prompt>Pick B</prompt><simpleChoice identifier="A">a</simpleChoice>'
		. '<simpleChoice identifier="SECRET_B">b</simpleChoice></choiceInteraction></itemBody></assessmentItem>';

	private PortalFakeRegister $register;

	private string $now = '2026-10-01T09:00:00+02:00';

	private bool $releaseAvailable = true;

	/**
	 * Seed one pupil, one course, one open test with two items.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new PortalFakeRegister();
		$this->register->put('enrolment', 'en-1', ['learnerId' => 'pupil-1', 'courseId' => 'course-1', 'lifecycle' => 'active']);
		$this->register->put('item', 'item-1', ['title' => 'Q1', 'interactionType' => 'choice', 'qtiBody' => self::CHOICE_QTI, 'correctResponse' => 'SECRET_B', 'maxScore' => 1]);
		$this->register->put('item', 'item-2', ['title' => 'Explain', 'interactionType' => 'extendedText', 'qtiBody' => '<itemBody><p>Explain.</p></itemBody>', 'maxScore' => 4]);
		$this->register->put('exam', 'exam-open', $this->exam());
	}//end setUp()

	/**
	 * An open, published, timed test of course-1.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function exam(array $overrides = []): array {
		return array_merge(
			[
				'title' => 'Toets hoofdstuk 3',
				'courseId' => 'course-1',
				'lifecycle' => 'published',
				'tenant_id' => self::TENANT,
				'timeLimitMinutes' => 30,
				'maxAttempts' => 1,
				'itemRefs' => [['itemId' => 'item-1', 'points' => 1], ['itemId' => 'item-2', 'points' => 4]],
			],
			$overrides
		);
	}//end exam()

	/**
	 * The pupil the requests act for.
	 *
	 * @param string $uid Nextcloud user id.
	 *
	 * @return PortalLearner
	 */
	private function learner(string $uid = 'pupil-1'): PortalLearner {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return new PortalLearner(profileRef: 'lp-1', ncUserId: $uid, tenantId: self::TENANT, user: $user);
	}//end learner()

	/**
	 * The service over the fake register and the fixed clock.
	 *
	 * @return PortalAttemptService
	 */
	private function service(): PortalAttemptService {
		$objects = $this->register->objectService($this);
		$reader = new PortalAttemptReader(objectService: $objects);
		$writer = new PortalAttemptWriter(objectService: $objects, transitionEngine: $this->register->transitionEngine($this));

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn (): DateTime => new DateTime($this->now));

		$release = $this->createMock(LessonReleaseEvaluator::class);
		$release->method('evaluate')->willReturnCallback(
			fn (): array => ['available' => $this->releaseAvailable, 'reason' => null, 'availableAt' => null]
		);

		$policy = new AssessmentAccessPolicy();
		$clock = new PortalAttemptClock();
		$presenter = new PortalItemPresenter();

		return new PortalAttemptService(
			reader: $reader,
			writer: $writer,
			catalogue: new PortalAssessmentCatalogue(reader: $reader, release: $release, policy: $policy),
			closer: new PortalAttemptCloser(reader: $reader, writer: $writer, clock: $clock, time: $time, logger: new NullLogger()),
			payload: new PortalAttemptPayload(reader: $reader, presenter: $presenter, clock: $clock, policy: $policy, logger: new NullLogger()),
			answerRules: new PortalAnswerRules(reader: $reader, presenter: $presenter, shape: new PortalAnswerShape()),
			policy: $policy
		);
	}//end service()

	/**
	 * Store an attempt by pupil-1 at exam-open.
	 *
	 * @param string $id Attempt id.
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return void
	 */
	private function attempt(string $id, array $overrides = []): void {
		$this->register->put(
			'assessment-result',
			$id,
			array_merge(
				[
					'assessmentId' => 'exam-open',
					'learnerId' => 'pupil-1',
					'lifecycle' => 'in-progress',
					'startedAt' => '2026-10-01T09:00:00+02:00',
					'responses' => [['itemId' => 'item-2', 'response' => ['value' => 'Draft'], 'autoScore' => null, 'manualScore' => null]],
					'drawnItemRefs' => [['itemId' => 'item-1', 'points' => 1], ['itemId' => 'item-2', 'points' => 4]],
				],
				$overrides
			)
		);
	}//end attempt()

	/**
	 * Only open tests and the attempt in progress are listed: not a draft, a
	 * proctored test, another school's, a closed one, one whose attempts are
	 * used, or one of a course the pupil is not in.
	 *
	 * @return void
	 */
	public function testAvailableListsOnlyWhatThePupilMayStartOrContinue(): void {
		$this->register->put('exam', 'exam-draft', $this->exam(['lifecycle' => 'draft']));
		$this->register->put('exam', 'exam-proctored', $this->exam(['proctoring' => ['nativeTestMode' => true]]));
		$this->register->put('exam', 'exam-other-school', $this->exam(['tenant_id' => '22222222-2222-4222-8222-222222222222']));
		$this->register->put('exam', 'exam-closed', $this->exam(['availableUntil' => '2026-09-30T00:00:00+02:00']));
		$this->register->put('exam', 'exam-used', $this->exam());
		$this->register->put('exam', 'exam-busy', $this->exam());
		$this->register->put('exam', 'exam-other-course', $this->exam(['courseId' => 'course-9']));
		$this->attempt('used-1', ['assessmentId' => 'exam-used', 'lifecycle' => 'submitted']);
		$this->attempt('busy-1', ['assessmentId' => 'exam-busy']);

		$outcome = $this->service()->available(learner: $this->learner());

		self::assertSame(200, $outcome->status);
		$byId = array_column($outcome->body['tasks'], null, 'taskId');
		self::assertSame(['exam-busy', 'exam-open'], $this->sorted(array_keys($byId)));
		self::assertSame('available', $byId['exam-open']['state']);
		self::assertSame('in-progress', $byId['exam-busy']['state']);
		self::assertSame('busy-1', $byId['exam-busy']['attemptId']);
		self::assertSame(30, $byId['exam-open']['timeLimitMinutes']);
		self::assertFalse($byId['exam-open']['needsAccessCode']);
	}//end testAvailableListsOnlyWhatThePupilMayStartOrContinue()

	/**
	 * A test outside its window cannot be started, and nothing is written.
	 *
	 * @return void
	 */
	public function testATestOutsideItsWindowIsNotAvailable(): void {
		$this->register->put('exam', 'exam-future', $this->exam(['availableFrom' => '2026-10-02T09:00:00+02:00']));

		$outcome = $this->service()->start(learner: $this->learner(), taskId: 'exam-future', accessCode: null);

		self::assertSame(403, $outcome->status);
		self::assertSame('not_available', $outcome->body['error']);
		self::assertSame(PortalAssessmentCatalogue::NOT_OPEN, $outcome->reason);
		self::assertSame([], $this->register->writes);
	}//end testATestOutsideItsWindowIsNotAvailable()

	/**
	 * Drip and release conditions (LessonReleaseEvaluator) also shut a test.
	 *
	 * @return void
	 */
	public function testUnmetReleaseConditionsShutATest(): void {
		$this->releaseAvailable = false;

		$outcome = $this->service()->start(learner: $this->learner(), taskId: 'exam-open', accessCode: null);

		self::assertSame(403, $outcome->status);
		self::assertSame(PortalAssessmentCatalogue::NOT_AVAILABLE, $outcome->reason);
	}//end testUnmetReleaseConditionsShutATest()

	/**
	 * An unknown, draft, proctored or not-enrolled test cannot be started.
	 *
	 * @return void
	 */
	public function testTestsThePupilMayNotTakeAreRefused(): void {
		$this->register->put('exam', 'exam-draft', $this->exam(['lifecycle' => 'draft']));
		$this->register->put('exam', 'exam-proctored', $this->exam(['proctoring' => ['provider' => 'x']]));
		$this->register->put('exam', 'exam-other-course', $this->exam(['courseId' => 'course-9']));
		$service = $this->service();

		self::assertSame(PortalAssessmentCatalogue::NOT_AVAILABLE, $service->start(learner: $this->learner(), taskId: 'exam-none', accessCode: null)->reason);
		self::assertSame(PortalAssessmentCatalogue::NOT_AVAILABLE, $service->start(learner: $this->learner(), taskId: 'exam-draft', accessCode: null)->reason);
		self::assertSame(PortalAssessmentCatalogue::PROCTORED, $service->start(learner: $this->learner(), taskId: 'exam-proctored', accessCode: null)->reason);
		self::assertSame(PortalAssessmentCatalogue::NOT_AVAILABLE, $service->start(learner: $this->learner(), taskId: 'exam-other-course', accessCode: null)->reason);
		self::assertSame([], $this->register->writes);
	}//end testTestsThePupilMayNotTakeAreRefused()

	/**
	 * The access code: missing and wrong are refused; the right one creates the
	 * attempt as the pupil, passing the code on for the attempt gate.
	 *
	 * @return void
	 */
	public function testTheAccessCodeIsRequired(): void {
		$this->register->put('exam', 'exam-code', $this->exam(['accessCode' => 'ROOM-12']));
		$service = $this->service();

		$missing = $service->start(learner: $this->learner(), taskId: 'exam-code', accessCode: null);
		self::assertSame(['error' => 'access_code_required'], $missing->body);
		$wrong = $service->start(learner: $this->learner(), taskId: 'exam-code', accessCode: 'ROOM-13');
		self::assertSame(['error' => 'access_code_wrong'], $wrong->body);
		self::assertSame([], $this->register->writes);

		$right = $service->start(learner: $this->learner(), taskId: 'exam-code', accessCode: ' ROOM-12 ');
		self::assertSame(200, $right->status);
		self::assertSame('pupil-1', $this->register->writes[0]['as']);
		self::assertSame(' ROOM-12 ', $this->register->writes[0]['data']['accessCode']);
		self::assertSame('pupil-1', $this->register->writes[0]['data']['learnerId']);
		self::assertSame(self::TENANT, $this->register->writes[0]['data']['tenant_id']);
		self::assertSame(1, $this->register->writes[0]['data']['attemptNumber']);
	}//end testTheAccessCodeIsRequired()

	/**
	 * The start payload: server clock, deadline, drawn items in order, never a
	 * correct answer.
	 *
	 * @return void
	 */
	public function testStartReturnsTheAttemptWithoutAnswers(): void {
		$outcome = $this->service()->start(learner: $this->learner(), taskId: 'exam-open', accessCode: null);

		self::assertSame(200, $outcome->status);
		self::assertSame('Toets hoofdstuk 3', $outcome->body['title']);
		self::assertSame('2026-10-01T09:00:00+02:00', $outcome->body['serverNow']);
		self::assertSame('2026-10-01T09:30:00+02:00', $outcome->body['deadlineAt']);
		self::assertSame(['item-1', 'item-2'], array_column($outcome->body['items'], 'itemId'));
		self::assertSame('Pick B', $outcome->body['items'][0]['prompt']);
		self::assertStringNotContainsString('correctResponse', (string)json_encode($outcome->body));
		self::assertSame('2026-10-01T09:00:00+02:00', $this->register->writes[0]['data']['startedAt']);
	}//end testStartReturnsTheAttemptWithoutAnswers()

	/**
	 * With one attempt allowed, a second start after hand-in is refused.
	 *
	 * @return void
	 */
	public function testASecondAttemptIsRefusedWhenOnlyOneIsAllowed(): void {
		$this->attempt('done-1', ['lifecycle' => 'submitted']);

		$outcome = $this->service()->start(learner: $this->learner(), taskId: 'exam-open', accessCode: null);

		self::assertSame(403, $outcome->status);
		self::assertSame(PortalAssessmentCatalogue::ATTEMPTS_USED, $outcome->reason);
		self::assertSame([], $this->register->writes);
	}//end testASecondAttemptIsRefusedWhenOnlyOneIsAllowed()

	/**
	 * A retake is allowed up to maxAttempts, numbered on.
	 *
	 * @return void
	 */
	public function testARetakeIsAllowedUpToMaxAttempts(): void {
		$this->register->put('exam', 'exam-open', $this->exam(['maxAttempts' => 2]));
		$this->attempt('done-1', ['lifecycle' => 'graded']);

		$outcome = $this->service()->start(learner: $this->learner(), taskId: 'exam-open', accessCode: null);

		self::assertSame(200, $outcome->status);
		self::assertSame(2, $this->register->writes[0]['data']['attemptNumber']);
	}//end testARetakeIsAllowedUpToMaxAttempts()

	/**
	 * An attempt in progress is resumed, with its saved answers, not duplicated.
	 *
	 * @return void
	 */
	public function testAnOpenAttemptIsResumedNotDuplicated(): void {
		$this->attempt('busy-1');
		$this->now = '2026-10-01T09:10:00+02:00';

		$outcome = $this->service()->start(learner: $this->learner(), taskId: 'exam-open', accessCode: null);

		self::assertSame('busy-1', $outcome->body['attemptId']);
		self::assertSame(['item-2' => 'Draft'], (array)$outcome->body['responses']);
		self::assertSame([], $this->register->writes);
	}//end testAnOpenAttemptIsResumedNotDuplicated()

	/**
	 * 25% extra time from an active accommodation moves the deadline to 09:37:30
	 * and shows 7.5 extra minutes in the task list.
	 *
	 * @return void
	 */
	public function testExtraTimeMovesTheDeadline(): void {
		$this->register->put(
			'exam-accommodation',
			'acc-1',
			['learnerId' => 'pupil-1', 'accommodationKind' => 'extra-time-percentage', 'value' => 25, 'assessmentId' => null, 'lifecycle' => 'active']
		);
		$service = $this->service();

		$tasks = $service->available(learner: $this->learner())->body['tasks'];
		self::assertSame(7.5, $tasks[0]['extraTimeMinutes']);

		$outcome = $service->start(learner: $this->learner(), taskId: 'exam-open', accessCode: null);
		self::assertSame('2026-10-01T09:37:30+02:00', $outcome->body['deadlineAt']);
	}//end testExtraTimeMovesTheDeadline()

	/**
	 * An answer replaces only its own question's response, unscored, as the pupil.
	 *
	 * @return void
	 */
	public function testAnAnswerIsSavedPerQuestion(): void {
		$this->attempt('busy-1');
		$this->now = '2026-10-01T09:05:00+02:00';

		$outcome = $this->service()->answer(learner: $this->learner(), attemptId: 'busy-1', itemId: 'item-1', response: 'SECRET_B');

		self::assertSame(['saved' => true], $outcome->body);
		self::assertSame('pupil-1', $this->register->writes[0]['as']);
		$responses = array_column($this->register->get('assessment-result', 'busy-1')['responses'], null, 'itemId');
		self::assertSame(['value' => 'SECRET_B'], $responses['item-1']['response']);
		self::assertNull($responses['item-1']['autoScore']);
		self::assertNull($responses['item-1']['manualScore']);
		self::assertSame(['value' => 'Draft'], $responses['item-2']['response']);
	}//end testAnAnswerIsSavedPerQuestion()

	/**
	 * An item the attempt did not draw, or an answer that does not fit, is refused.
	 *
	 * @return void
	 */
	public function testUnknownItemsAndInvalidAnswersAreRefused(): void {
		$this->attempt('busy-1');
		$service = $this->service();

		self::assertSame(['error' => 'unknown_item'], $service->answer(learner: $this->learner(), attemptId: 'busy-1', itemId: 'item-9', response: 'A')->body);
		self::assertSame(['error' => 'invalid_response'], $service->answer(learner: $this->learner(), attemptId: 'busy-1', itemId: 'item-1', response: 'Z')->body);
		self::assertSame(['error' => 'invalid_response'], $service->answer(learner: $this->learner(), attemptId: 'busy-1', itemId: 'item-2', response: ['x'])->body);
		self::assertSame([], $this->register->writes);
	}//end testUnknownItemsAndInvalidAnswersAreRefused()

	/**
	 * Another pupil's attempt, or one that does not exist, is not found.
	 *
	 * @return void
	 */
	public function testAnotherPupilsAttemptIsNotFound(): void {
		$this->attempt('busy-1');
		$service = $this->service();

		self::assertSame(404, $service->answer(learner: $this->learner('pupil-2'), attemptId: 'busy-1', itemId: 'item-1', response: 'A')->status);
		self::assertSame(404, $service->submit(learner: $this->learner('pupil-2'), attemptId: 'busy-1')->status);
		self::assertSame(404, $service->answer(learner: $this->learner(), attemptId: 'nope', itemId: 'item-1', response: 'A')->status);
		self::assertSame([], $this->register->writes);
	}//end testAnotherPupilsAttemptIsNotFound()

	/**
	 * Nothing changes after hand-in.
	 *
	 * @return void
	 */
	public function testNoAnswerAfterHandIn(): void {
		$this->attempt('done-1', ['lifecycle' => 'submitted']);

		$outcome = $this->service()->answer(learner: $this->learner(), attemptId: 'done-1', itemId: 'item-1', response: 'A');

		self::assertSame(409, $outcome->status);
		self::assertSame('attempt_closed', $outcome->body['error']);
		self::assertSame([], $this->register->writes);
		self::assertSame([], $this->register->transitions);
	}//end testNoAnswerAfterHandIn()

	/**
	 * Ten seconds past the deadline an answer is saved (grace); 31 seconds past
	 * it is refused and the attempt is handed in, as the pupil.
	 *
	 * @return void
	 */
	public function testAnAnswerPastTheDeadlineHandsTheAttemptIn(): void {
		$this->attempt('busy-1');

		$this->now = '2026-10-01T09:30:10+02:00';
		self::assertSame(200, $this->service()->answer(learner: $this->learner(), attemptId: 'busy-1', itemId: 'item-1', response: 'A')->status);

		$this->now = '2026-10-01T09:30:31+02:00';
		$outcome = $this->service()->answer(learner: $this->learner(), attemptId: 'busy-1', itemId: 'item-1', response: 'SECRET_B');

		self::assertSame(409, $outcome->status);
		self::assertSame([['id' => 'busy-1', 'action' => 'submit', 'as' => 'pupil-1']], $this->register->transitions);
		$stored = $this->register->get('assessment-result', 'busy-1');
		self::assertSame('2026-10-01T09:30:31+02:00', $stored['submittedAt']);
		self::assertSame('submitted', $stored['lifecycle']);
		self::assertSame(['value' => 'A'], array_column($stored['responses'], null, 'itemId')['item-1']['response']);
	}//end testAnAnswerPastTheDeadlineHandsTheAttemptIn()

	/**
	 * Listing the tests also hands in an attempt that ran out of time.
	 *
	 * @return void
	 */
	public function testAvailableHandsInAnAttemptThatRanOutOfTime(): void {
		$this->register->put('exam', 'exam-open', $this->exam(['maxAttempts' => 1]));
		$this->attempt('busy-1');
		$this->now = '2026-10-01T10:00:00+02:00';

		$tasks = $this->service()->available(learner: $this->learner())->body['tasks'];

		self::assertSame([], $tasks);
		self::assertSame('submit', $this->register->transitions[0]['action']);
	}//end testAvailableHandsInAnAttemptThatRanOutOfTime()

	/**
	 * Hand-in records the time and fires `submit` as the pupil; a second
	 * hand-in is refused.
	 *
	 * @return void
	 */
	public function testSubmitFiresTheTransitionAsThePupil(): void {
		$this->attempt('busy-1');
		$this->now = '2026-10-01T09:20:00+02:00';
		$service = $this->service();

		self::assertSame(['state' => 'submitted'], $service->submit(learner: $this->learner(), attemptId: 'busy-1')->body);
		self::assertSame([['id' => 'busy-1', 'action' => 'submit', 'as' => 'pupil-1']], $this->register->transitions);
		self::assertSame('pupil-1', $this->register->writes[0]['as']);
		self::assertSame('2026-10-01T09:20:00+02:00', $this->register->get('assessment-result', 'busy-1')['submittedAt']);

		self::assertSame(409, $service->submit(learner: $this->learner(), attemptId: 'busy-1')->status);
		self::assertCount(1, $this->register->transitions);
	}//end testSubmitFiresTheTransitionAsThePupil()

	/**
	 * Sort a list of strings.
	 *
	 * @param array<int, string> $values The values.
	 *
	 * @return array<int, string>
	 */
	private function sorted(array $values): array {
		sort($values);

		return $values;
	}//end sorted()
}//end class
