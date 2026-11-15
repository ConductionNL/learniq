<?php

/**
 * Learniq PortalAttemptCloser and PortalAttemptPayload unit tests.
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
use DateTimeImmutable;
use OCA\Learniq\Service\AssessmentAccessPolicy;
use OCA\Learniq\Service\Portal\PortalAttemptClock;
use OCA\Learniq\Service\Portal\PortalAttemptCloser;
use OCA\Learniq\Service\Portal\PortalAttemptPayload;
use OCA\Learniq\Service\Portal\PortalAttemptReader;
use OCA\Learniq\Service\Portal\PortalAttemptWriter;
use OCA\Learniq\Service\Portal\PortalItemPresenter;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Tests\Support\PortalFakeRegister;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for PortalAttemptCloser and PortalAttemptPayload.
 */
class PortalAttemptTimingTest extends TestCase {

	/**
	 * The closer over a register at a fixed time.
	 *
	 * @param PortalFakeRegister $register The register.
	 * @param string $now The current time.
	 *
	 * @return PortalAttemptCloser
	 */
	private function closer(PortalFakeRegister $register, string $now): PortalAttemptCloser {
		$objects = $register->objectService($this);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime($now));

		return new PortalAttemptCloser(
			reader: new PortalAttemptReader(objectService: $objects),
			writer: new PortalAttemptWriter(objectService: $objects, transitionEngine: $register->transitionEngine($this)),
			clock: new PortalAttemptClock(),
			time: $time,
			logger: new NullLogger()
		);
	}//end closer()

	/**
	 * The pupil.
	 *
	 * @return PortalLearner
	 */
	private function learner(): PortalLearner {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pupil-1');

		return new PortalLearner(profileRef: 'lp-1', ncUserId: 'pupil-1', tenantId: '', user: $user);
	}//end learner()

	/**
	 * An attempt whose test cannot be read takes no answers and is not handed
	 * in; an untimed attempt stays open.
	 *
	 * @return void
	 */
	public function testAnUnreadableTestClosesTheAttempt(): void {
		$register = new PortalFakeRegister();
		$register->put('exam', 'e-untimed', ['timeLimitMinutes' => null]);
		$closer = $this->closer($register, '2030-01-01T00:00:00+00:00');

		self::assertTrue($closer->closeIfDue(learner: $this->learner(), attempt: ['id' => 'a-1', 'lifecycle' => 'in-progress', 'assessmentId' => 'e-gone']));
		self::assertFalse($closer->closeIfDue(learner: $this->learner(), attempt: ['id' => 'a-2', 'lifecycle' => 'in-progress', 'assessmentId' => 'e-untimed']));
		self::assertTrue($closer->closeIfDue(learner: $this->learner(), attempt: ['id' => 'a-3', 'lifecycle' => 'graded']));
		self::assertSame([], $register->transitions);
	}//end testAnUnreadableTestClosesTheAttempt()

	/**
	 * A hand-in keeps a recorded submittedAt and only fires the transition.
	 *
	 * @return void
	 */
	public function testAHandInKeepsAnEarlierSubmittedAt(): void {
		$register = new PortalFakeRegister();
		$register->put('assessment-result', 'a-1', ['lifecycle' => 'in-progress']);

		$this->closer($register, '2026-10-01T09:00:00+00:00')->handIn(
			learner: $this->learner(),
			attempt: ['id' => 'a-1', 'lifecycle' => 'in-progress', 'submittedAt' => '2026-10-01T08:59:00+00:00']
		);

		self::assertSame([], $register->writes);
		self::assertSame('submit', $register->transitions[0]['action']);
	}//end testAHandInKeepsAnEarlierSubmittedAt()

	/**
	 * Without a draw the payload falls back to the test's item list; a task
	 * line reports the access code and the extra minutes.
	 *
	 * @return void
	 */
	public function testThePayloadFallsBackToTheTestItems(): void {
		$register = new PortalFakeRegister();
		$register->put('item', 'i-1', ['title' => 'Q1', 'interactionType' => 'textEntry', 'qtiBody' => '<itemBody><p>Name it.</p></itemBody>']);
		$clock = new PortalAttemptClock();
		$payload = new PortalAttemptPayload(
			reader: new PortalAttemptReader(objectService: $register->objectService($this)),
			presenter: new PortalItemPresenter(),
			clock: $clock,
			policy: new AssessmentAccessPolicy(),
			logger: new NullLogger()
		);
		$exam = ['id' => 'e-1', 'title' => 'Toets', 'timeLimitMinutes' => 20, 'accessCode' => 'X-1', 'itemRefs' => [['itemId' => 'i-1', 'points' => 2]]];

		$body = $payload->attempt(
			exam: $exam,
			attempt: ['id' => 'a-1', 'startedAt' => '2026-10-01T09:00:00+00:00', 'drawnItemRefs' => []],
			extra: 50.0,
			now: new DateTimeImmutable('2026-10-01T09:01:00+00:00')
		);
		$task = $payload->task(exam: $exam, extra: 50.0, attemptId: null);

		self::assertSame(['i-1'], array_column($body['items'], 'itemId'));
		self::assertSame('Name it.', $body['items'][0]['prompt']);
		self::assertSame('2026-10-01T09:30:00+00:00', $body['deadlineAt']);
		self::assertSame([], (array)$body['responses']);
		self::assertTrue($task['needsAccessCode']);
		self::assertSame(10.0, $task['extraTimeMinutes']);
		self::assertSame('available', $task['state']);
	}//end testThePayloadFallsBackToTheTestItems()
}//end class
