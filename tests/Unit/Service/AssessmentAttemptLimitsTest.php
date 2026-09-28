<?php

/**
 * Tests for AssessmentAttemptLimits: the start rules and the late-answer rule
 * of an in-app attempt.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/in-app-test-limits-server-side/specs/assessment/spec.md#requirement-the-in-app-test-screen-enforces-attempts-and-time-on-the-server
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use DateTime;
use OCA\Learniq\Service\AssessmentAccessPolicy;
use OCA\Learniq\Service\AssessmentAttemptLimits;
use OCA\Learniq\Service\Portal\PortalAttemptClock;
use OCA\Learniq\Service\Portal\PortalAttemptReader;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AssessmentAttemptLimits.
 */
class AssessmentAttemptLimitsTest extends TestCase {

	/**
	 * Assessment rows by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $exams = ['a1' => ['id' => 'a1', 'title' => 'Toets', 'timeLimitMinutes' => 30, 'maxAttempts' => 2]];

	/**
	 * Existing attempts.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $attempts = [];

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
	 * Build the service over in-memory rows.
	 *
	 * @return AssessmentAttemptLimits
	 */
	private function makeLimits(): AssessmentAttemptLimits {
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
			fn (array $config = []): array => match ($config['filters']['schema'] ?? '') {
				'assessment-result' => $this->attempts,
				'exam-accommodation' => $this->accommodations,
				default => [],
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn (): DateTime => new DateTime($this->now));

		return new AssessmentAttemptLimits(
			attempts: new PortalAttemptReader(objectService: $objects),
			clock: new PortalAttemptClock(),
			policy: new AssessmentAccessPolicy(),
			timeFactory: $time,
		);
	}//end makeLimits()

	/**
	 * An attempt of the learner.
	 *
	 * @param array<string, mixed> $override Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function attempt(array $override = []): array {
		return array_merge(
			[
				'assessmentId' => 'a1',
				'learnerId' => 'learner1',
				'lifecycle' => 'in-progress',
				'startedAt' => '2026-09-27T09:00:00+00:00',
				'responses' => [['itemId' => 'mc', 'response' => ['value' => 'A'], 'autoScore' => null]],
			],
			$override
		);
	}//end attempt()

	/**
	 * A start with attempts left is let through with the server's stamp; the
	 * last attempt used refuses the next.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/in-app-test-limits-server-side/specs/assessment/spec.md#scenario-a-second-attempt-on-a-one-attempt-test-is-refused
	 */
	public function testStartCountsAttemptsAndStampsTheServerClock(): void {
		$limits = $this->makeLimits();
		$this->attempts = [self::attempt(['lifecycle' => 'graded'])];

		$second = $limits->start(assessment: $this->exams['a1'], payload: ['assessmentId' => 'a1', 'learnerId' => 'learner1', 'accessCode' => 'x']);
		self::assertNull($second['block']);
		self::assertSame(['startedAt' => '2026-09-27T09:10:00+00:00', 'attemptNumber' => 2, 'deadlineAt' => '2026-09-27T09:40:00+00:00', 'accessCode' => null], $second['stamp']);

		$this->attempts[] = self::attempt(['lifecycle' => 'submitted']);
		$third = $limits->start(assessment: $this->exams['a1'], payload: ['assessmentId' => 'a1', 'learnerId' => 'learner1']);
		self::assertSame(AssessmentAccessPolicy::REASON_ATTEMPTS_USED, $third['block']['reason']);
		self::assertSame([], $third['stamp']);
	}//end testStartCountsAttemptsAndStampsTheServerClock()

	/**
	 * The stamped deadline counts the learner's granted extra time, and a test
	 * without a time limit has none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/test-screen-autosave-and-deadline/specs/assessment/spec.md#scenario-the-timer-counts-down-to-the-servers-deadline
	 */
	public function testTheDeadlineCountsExtraTime(): void {
		$limits = $this->makeLimits();
		$this->accommodations = [['learnerId' => 'learner1', 'accommodationKind' => 'extra-time-percentage', 'value' => 50, 'lifecycle' => 'approved']];

		$start = $limits->start(assessment: $this->exams['a1'], payload: ['assessmentId' => 'a1', 'learnerId' => 'learner1']);
		self::assertSame('2026-09-27T09:55:00+00:00', $start['stamp']['deadlineAt']);

		$untimed = array_merge($this->exams['a1'], ['timeLimitMinutes' => null]);
		self::assertNull($limits->start(assessment: $untimed, payload: ['assessmentId' => 'a1', 'learnerId' => 'learner1'])['stamp']['deadlineAt']);
	}//end testTheDeadlineCountsExtraTime()

	/**
	 * The window and the access code are checked before the attempts.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/in-app-test-limits-server-side/specs/assessment/spec.md#requirement-the-in-app-test-screen-enforces-attempts-and-time-on-the-server
	 */
	public function testWindowAndCodeComeFirst(): void {
		$limits = $this->makeLimits();
		$closed = array_merge($this->exams['a1'], ['availableUntil' => '2026-09-26T00:00:00+00:00']);
		$coded = array_merge($this->exams['a1'], ['accessCode' => 'room-12']);

		self::assertSame(AssessmentAccessPolicy::REASON_CLOSED, $limits->start(assessment: $closed, payload: ['assessmentId' => 'a1', 'learnerId' => 'learner1'])['block']['reason']);
		self::assertSame(AssessmentAccessPolicy::REASON_CODE_REQUIRED, $limits->start(assessment: $coded, payload: ['assessmentId' => 'a1', 'learnerId' => 'learner1'])['block']['reason']);
	}//end testWindowAndCodeComeFirst()

	/**
	 * Late answers: only the learner's own attempt in progress, only a change
	 * of answers, only past the deadline plus the grace; an unreadable test
	 * counts as past.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/in-app-test-limits-server-side/specs/assessment/spec.md#scenario-answers-after-the-deadline-are-not-saved
	 */
	public function testAnswersLate(): void {
		$limits = $this->makeLimits();
		$old = self::attempt();
		$changed = self::attempt(['responses' => [['itemId' => 'mc', 'response' => ['value' => 'C'], 'autoScore' => null]]]);
		$scored = self::attempt(['responses' => [['itemId' => 'mc', 'response' => ['value' => 'A'], 'autoScore' => 1]]]);

		self::assertFalse($limits->answersLate(old: $old, new: $changed, uid: 'learner1'), 'inside the time');

		$this->now = '2026-09-27T09:31:00+00:00';
		self::assertTrue($limits->answersLate(old: $old, new: $changed, uid: 'learner1'));
		self::assertFalse($limits->answersLate(old: $old, new: $scored, uid: 'learner1'), 'scores on unchanged answers');
		self::assertFalse($limits->answersLate(old: $old, new: $changed, uid: 'learner2'), 'not their attempt');
		self::assertFalse($limits->answersLate(old: self::attempt(['lifecycle' => 'submitted']), new: $changed, uid: 'learner1'), 'not in progress');

		unset($this->exams['a1']);
		$this->now = '2026-09-27T09:05:00+00:00';
		self::assertTrue($limits->answersLate(old: $old, new: $changed, uid: 'learner1'), 'an unreadable test counts as past');
	}//end testAnswersLate()
}//end class
