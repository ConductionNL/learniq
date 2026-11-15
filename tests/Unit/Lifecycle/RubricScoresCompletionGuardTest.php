<?php

/**
 * Learniq RubricScoresCompletionGuard unit tests.
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
 * @spec openspec/specs/assignments/spec.md#scenario-submit-is-blocked-when-rubric-coverage-is-incomplete
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Lifecycle\LearnerCaller;
use OCA\Learniq\Lifecycle\RubricScoresCompletionGuard;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the RubricScoresCompletionGuard (PeerReview.submit / SelfAssessment.submit).
 */
class RubricScoresCompletionGuardTest extends TestCase {

	private const ASSIGNMENT_ID = 'assignment-1';
	private const RUBRIC_ID = 'rubric-1';
	private const SUBMISSION_ID = 'submission-1';

	/**
	 * A three-criterion Rubric fixture.
	 *
	 * @var array<string,mixed>
	 */
	private array $rubric = [
		'id' => self::RUBRIC_ID,
		'criteria' => [
			['criterionId' => 'structure', 'levels' => []],
			['criterionId' => 'analysis', 'levels' => []],
			['criterionId' => 'conclusion', 'levels' => []],
		],
	];

	/**
	 * Build a guard wired to the given Assignment/Rubric/Submission fixtures.
	 *
	 * @param array<string,mixed>|null $assignment Assignment fixture, or null (not found).
	 * @param array<string,mixed>|null $rubric Rubric fixture, or null (not found).
	 * @param array<string,mixed>|null $submission Submission fixture, or null (not found).
	 *
	 * @return RubricScoresCompletionGuard
	 */
	private function makeGuard(?array $assignment, ?array $rubric, ?array $submission): RubricScoresCompletionGuard {
		$objectService = $this->createMock(ObjectService::class);
		// OpenRegister's find() is find($id, $_extend, $files, $register, $schema, ...)
		// and returns ?ObjectEntity. willReturnCallback() hands the closure the
		// mock's arguments POSITIONALLY, so the closure must mirror that order.
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) use ($assignment, $rubric, $submission) {
				if ($schema === 'assignment' && $assignment !== null) {
					return OrEntityFactory::make($assignment, 'assignment');
				}

				if ($schema === 'rubric' && $rubric !== null) {
					return OrEntityFactory::make($rubric, 'rubric');
				}

				if ($schema === 'submission' && $submission !== null) {
					return OrEntityFactory::make($submission, 'submission');
				}

				return null;
			}
		);

		return new RubricScoresCompletionGuard($objectService, $this->createMock(LoggerInterface::class), new LearnerCaller($this->createMock(IGroupManager::class)));
	}//end makeGuard()

	/**
	 * Complete rubric coverage (all three criteria) allows submit — PeerReview shape.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
	 */
	public function testCompleteCoverageAllowsSubmitForPeerReview(): void {
		$guard = $this->makeGuard(['id' => self::ASSIGNMENT_ID, 'rubricId' => self::RUBRIC_ID], $this->rubric, null);
		$context = [
			'object' => [
				'id' => 'pr-1',
				'assignmentId' => self::ASSIGNMENT_ID,
				'reviewerId' => 'teacher-uid',
				'rubricScores' => [
					['criterionId' => 'structure', 'levelId' => 'good', 'points' => 3],
					['criterionId' => 'analysis', 'levelId' => 'good', 'points' => 3],
					['criterionId' => 'conclusion', 'levelId' => 'good', 'points' => 3],
				],
			],
		];

		self::assertTrue($guard->check($context['object'], 'submit', '')->isAllowed());
	}//end testCompleteCoverageAllowsSubmitForPeerReview()

	/**
	 * Incomplete rubric coverage (2 of 3 criteria) blocks submit — PeerReview shape.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assignments/spec.md#scenario-submit-is-blocked-when-rubric-coverage-is-incomplete
	 */
	public function testIncompleteCoverageBlocksSubmitForPeerReview(): void {
		$guard = $this->makeGuard(['id' => self::ASSIGNMENT_ID, 'rubricId' => self::RUBRIC_ID], $this->rubric, null);
		$context = [
			'object' => [
				'id' => 'pr-1',
				'assignmentId' => self::ASSIGNMENT_ID,
				'reviewerId' => 'teacher-uid',
				'rubricScores' => [
					['criterionId' => 'structure', 'levelId' => 'good', 'points' => 3],
					['criterionId' => 'analysis', 'levelId' => 'good', 'points' => 3],
				],
			],
		];

		self::assertFalse($guard->check($context['object'], 'submit', '')->isAllowed());
	}//end testIncompleteCoverageBlocksSubmitForPeerReview()

	/**
	 * Complete rubric coverage + learnerId on the linked Submission allows submit —
	 * SelfAssessment shape.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-after-submitting
	 */
	public function testCompleteCoverageAndValidLearnerAllowsSubmitForSelfAssessment(): void {
		$guard = $this->makeGuard(
			['id' => self::ASSIGNMENT_ID, 'rubricId' => self::RUBRIC_ID],
			$this->rubric,
			['id' => self::SUBMISSION_ID, 'learnerIds' => ['learner-a', 'learner-b']]
		);
		$context = [
			'object' => [
				'id' => 'sa-1',
				'assignmentId' => self::ASSIGNMENT_ID,
				'submissionId' => self::SUBMISSION_ID,
				'learnerId' => 'learner-a',
				'rubricScores' => [
					['criterionId' => 'structure', 'levelId' => 'good', 'points' => 3],
					['criterionId' => 'analysis', 'levelId' => 'good', 'points' => 3],
					['criterionId' => 'conclusion', 'levelId' => 'good', 'points' => 3],
				],
			],
		];

		self::assertTrue($guard->check($context['object'], 'submit', '')->isAllowed());
	}//end testCompleteCoverageAndValidLearnerAllowsSubmitForSelfAssessment()

	/**
	 * SelfAssessment with learnerId NOT in Submission.learnerIds is blocked, even
	 * with complete rubric coverage.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-self-assessment-lets-a-learner-score-their-own-submission-against-the-assignments-rubric
	 */
	public function testLearnerNotOnSubmissionBlocksSubmitForSelfAssessment(): void {
		$guard = $this->makeGuard(
			['id' => self::ASSIGNMENT_ID, 'rubricId' => self::RUBRIC_ID],
			$this->rubric,
			['id' => self::SUBMISSION_ID, 'learnerIds' => ['learner-a', 'learner-b']]
		);
		$context = [
			'object' => [
				'id' => 'sa-1',
				'assignmentId' => self::ASSIGNMENT_ID,
				'submissionId' => self::SUBMISSION_ID,
				'learnerId' => 'learner-outsider',
				'rubricScores' => [
					['criterionId' => 'structure', 'levelId' => 'good', 'points' => 3],
					['criterionId' => 'analysis', 'levelId' => 'good', 'points' => 3],
					['criterionId' => 'conclusion', 'levelId' => 'good', 'points' => 3],
				],
			],
		];

		self::assertFalse($guard->check($context['object'], 'submit', '')->isAllowed());
	}//end testLearnerNotOnSubmissionBlocksSubmitForSelfAssessment()

	/**
	 * An Assignment with no rubricId has no required criteria — an empty
	 * rubricScores array is trivially "complete".
	 *
	 * @return void
	 */
	public function testNoRubricOnAssignmentAllowsSubmitWithEmptyScores(): void {
		$guard = $this->makeGuard(['id' => self::ASSIGNMENT_ID, 'rubricId' => null], null, null);
		$context = [
			'object' => [
				'id' => 'pr-1',
				'assignmentId' => self::ASSIGNMENT_ID,
				'reviewerId' => 'teacher-uid',
				'rubricScores' => [],
			],
		];

		self::assertTrue($guard->check($context['object'], 'submit', '')->isAllowed());
	}//end testNoRubricOnAssignmentAllowsSubmitWithEmptyScores()

	/**
	 * A missing assignmentId fails closed.
	 *
	 * @return void
	 */
	public function testMissingAssignmentIdFailsClosed(): void {
		$guard = $this->makeGuard(null, null, null);
		$context = ['object' => ['id' => 'pr-1', 'reviewerId' => 'teacher-uid', 'rubricScores' => []]];

		self::assertFalse($guard->check($context['object'], 'submit', '')->isAllowed());
	}//end testMissingAssignmentIdFailsClosed()

	/**
	 * A guard over recording doubles: every find (schema and id) and every
	 * info line lands in $calls, in order.
	 *
	 * @param array<string,array<string,mixed>> $rows   "schema:id" => the stored row.
	 * @param \ArrayObject<int,mixed>            $calls  Filled as the guard runs.
	 * @param bool                              $admin  Whether the caller is an admin.
	 *
	 * @return RubricScoresCompletionGuard
	 */
	private function recordingGuard(array $rows, \ArrayObject $calls, bool $admin = false): RubricScoresCompletionGuard {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			static function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) use ($rows, $calls) {
				$calls[] = 'find ' . $register . '/' . $schema . '/' . $id;
				$row = ($rows[$schema . ':' . $id] ?? null);
				if ($row === null) {
					return null;
				}

				return OrEntityFactory::make($row, (string)$schema);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			static function (string $message, array $context = []) use ($calls): void {
				$calls[] = 'info ' . $message . ' ' . json_encode($context);
			}
		);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return new RubricScoresCompletionGuard($objectService, $logger, new LearnerCaller($groups));
	}//end recordingGuard()

	/**
	 * Run check() and return the verdict message (null when allowed) followed by the calls.
	 *
	 * @param array<string,array<string,mixed>> $rows   The stored rows.
	 * @param array<string,mixed>               $object The row being submitted.
	 * @param string                            $userId The caller.
	 *
	 * @return array<int,mixed>
	 */
	private function submitThrough(array $rows, array $object, string $userId = 'pupil'): array {
		$calls   = new \ArrayObject();
		$verdict = $this->recordingGuard(rows: $rows, calls: $calls)->check($object, 'submit', $userId);
		$message = null;
		if ($verdict->isAllowed() === false) {
			$message = $verdict->getMessage();
		}

		return array_merge([$message], $calls->getArrayCopy());
	}//end submitThrough()

	/**
	 * The coverage half: every lookup, log line and verdict is exact.
	 *
	 * @return void
	 */
	public function testEachCoverageOutcomeIsExact(): void {
		$deny = 'Score every rubric criterion before submitting.';
		$review = ['uuid' => 'pr-9', 'reviewerId' => 'pupil', 'assignmentId' => 'a1', 'rubricScores' => []];

		self::assertSame(
			[$deny, 'info [RubricScoresCompletionGuard] {id} has no assignmentId — denying submit (fail closed). {"id":"pr-9"}'],
			$this->submitThrough([], ['assignmentId' => ''] + $review)
		);
		self::assertSame([null, 'find learniq/assignment/a1'], $this->submitThrough([], $review));
		self::assertSame([null, 'find learniq/assignment/a1'], $this->submitThrough(['assignment:a1' => ['rubricId' => '']], $review));
		self::assertSame(
			[null, 'find learniq/assignment/a1', 'find learniq/rubric/r1'],
			$this->submitThrough(['assignment:a1' => ['rubricId' => 'r1']], $review)
		);

		$rows = [
			'assignment:a1' => ['rubricId' => 'r1'],
			'rubric:r1' => ['criteria' => ['junk', ['levels' => []], ['criterionId' => 5], ['criterionId' => 'b']]],
		];
		self::assertSame(
			[
				$deny,
				'find learniq/assignment/a1',
				'find learniq/rubric/r1',
				'info [RubricScoresCompletionGuard] {id} rubricScores does not cover every criterionId of the linked Rubric — blocking submit. {"id":"pr-9"}',
			],
			$this->submitThrough($rows, ['rubricScores' => [['criterionId' => 5], 'junk']] + $review)
		);
		self::assertSame(
			[null, 'find learniq/assignment/a1', 'find learniq/rubric/r1'],
			$this->submitThrough($rows, ['rubricScores' => [['criterionId' => 'b'], ['criterionId' => '5']]] + $review)
		);
	}//end testEachCoverageOutcomeIsExact()

	/**
	 * The self-assessment half: who may submit, and the learner-on-submission
	 * check with every way it fails closed.
	 *
	 * @return void
	 */
	public function testEachSelfAssessmentOutcomeIsExact(): void {
		$deny = 'Score every rubric criterion before submitting.';
		$log = 'info [RubricScoresCompletionGuard] {id} learnerId is not a member of the linked Submission.learnerIds — blocking submit. {"id":"sa-1"}';
		$self = ['id' => 'sa-1', 'learnerId' => 'pupil', 'submissionId' => 's1', 'assignmentId' => 'a1', 'rubricScores' => []];

		self::assertSame(['Only the person this assessment belongs to can submit it.'], $this->submitThrough([], $self, 'other'));
		self::assertSame([$deny, 'find learniq/assignment/a1', $log], $this->submitThrough([], ['submissionId' => ''] + $self));
		self::assertSame([$deny, 'find learniq/assignment/a1', $log], $this->submitThrough([], ['learnerId' => null] + $self, ''));
		self::assertSame([$deny, 'find learniq/assignment/a1', 'find learniq/submission/s1', $log], $this->submitThrough([], $self));
		self::assertSame(
			[$deny, 'find learniq/assignment/a1', 'find learniq/submission/s1', $log],
			$this->submitThrough(['submission:s1' => ['learnerIds' => 'pupil']], $self)
		);
		self::assertSame(
			[$deny, 'find learniq/assignment/a1', 'find learniq/submission/s1', $log],
			$this->submitThrough(['submission:s1' => ['learnerIds' => ['someone']]], $self)
		);
		self::assertSame(
			[null, 'find learniq/assignment/a1', 'find learniq/submission/s1'],
			$this->submitThrough(['submission:s1' => ['learnerIds' => ['x', 'pupil']]], $self)
		);

		$calls = new \ArrayObject();
		$admin = $this->recordingGuard(rows: ['submission:s1' => ['learnerIds' => ['pupil']]], calls: $calls, admin: true);
		self::assertTrue($admin->check($self, 'submit', 'root')->isAllowed());
	}//end testEachSelfAssessmentOutcomeIsExact()
}//end class
