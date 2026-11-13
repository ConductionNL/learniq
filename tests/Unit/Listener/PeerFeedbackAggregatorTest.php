<?php

/**
 * Learniq PeerFeedbackAggregator unit tests.
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
 *
 * @spec openspec/specs/assignments/spec.md#scenario-blind-and-double-blind-hide-reviewer-identity-in-the-feedback-summary
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Listener\PeerFeedbackAggregator;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PeerFeedbackAggregator::handle() on PeerReview -> released.
 */
class PeerFeedbackAggregatorTest extends TestCase {

	/**
	 * Recorded saveObject() calls, captured by the ObjectService stub used per test.
	 *
	 * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
	 */
	private array $savedObjects = [];

	/**
	 * Reset the capture buffer before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->savedObjects = [];
	}//end setUp()

	/**
	 * Build an aggregator wired to the given Assignment + released-PeerReview set.
	 *
	 * @param array<string,mixed> $assignment Assignment fixture (peerReviewAnonymity).
	 * @param array<int,array<string,mixed>> $releasedReviews Released PeerReview fixtures for the submission.
	 * @param array<string,mixed>|null $existingSummary Existing PeerFeedbackSummary, or null.
	 * @param array<mixed>|null $learnerIds The Submission's learnerIds, or null when the Submission cannot be read.
	 *
	 * @return PeerFeedbackAggregator
	 */
	private function makeAggregator(array $assignment, array $releasedReviews, ?array $existingSummary = null, ?array $learnerIds = ['learner-1']): PeerFeedbackAggregator {
		$objectService = $this->createMock(ObjectService::class);

		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) use ($assignment, $learnerIds): ?ObjectEntity {
				if ($schema === 'assignment') {
					return OrEntityFactory::make($assignment, 'assignment');
				}

				if ($schema === 'submission' && $learnerIds !== null) {
					return OrEntityFactory::make(['id' => (string)$id, 'tenant_id' => 'tenant-1', 'learnerIds' => $learnerIds], 'submission');
				}

				return null;
			}
		);

		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($releasedReviews, $existingSummary) {
				if (($config['filters']['schema'] ?? '') === 'peer-review') {
					return $releasedReviews;
				}

				if (($config['filters']['schema'] ?? '') === 'peer-feedback-summary') {
					return $existingSummary === null ? [] : [$existingSummary];
				}

				return [];
			}
		);

		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null): ObjectEntity {
				$data = ($object instanceof ObjectEntity) ? $object->jsonSerialize() : $object;
				$this->savedObjects[] = [
					'register' => (string)$register,
					'schema' => (string)$schema,
					'object' => $data,
				];
				return OrEntityFactory::make($data, (string)$schema, (string)$register);
			}
		);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn(new DateTime('2026-07-14T10:00:00+00:00'));

		return new PeerFeedbackAggregator($objectService, $timeFactory, \OCA\Learniq\Tests\Support\TransitionScope::resolver());
	}//end makeAggregator()

	/**
	 * Build a mocked ObjectTransitionedEvent for a PeerReview -> released transition.
	 *
	 * @param array<string,mixed> $peerReview The PeerReview's jsonSerialize() payload.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function makeEvent(array $peerReview): ObjectTransitionedEvent {
		$objectEntity = $this->createMock(ObjectEntity::class);
		$objectEntity->method('jsonSerialize')->willReturn($peerReview);

		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('peer-review');
		$event->method('getTo')->willReturn('released');
		$event->method('getFrom')->willReturn('submitted');

		return $event;
	}//end makeEvent()

	/**
	 * Every anonymity mode the Assignment can carry.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function anonymityModes(): array {
		return [
			'blind'        => ['blind'],
			'double-blind' => ['double-blind'],
			'open'         => ['open'],
		];
	}//end anonymityModes()

	/**
	 * The summary the learner reads carries the comments and scores but no
	 * reviewer user id and no PeerReview id, whatever the anonymity mode.
	 *
	 * @param string $anonymity The Assignment's peerReviewAnonymity.
	 *
	 * @return void
	 *
	 * @dataProvider anonymityModes
	 *
	 * @spec openspec/specs/assignments/spec.md#scenario-blind-and-double-blind-hide-reviewer-identity-in-the-feedback-summary
	 * @spec openspec/changes/cohort-room-and-feedback-summary-for-the-learner/specs/assignments/spec.md#scenario-a-learner-opens-the-feedback-on-their-work
	 */
	public function testTheSummaryCarriesNoReviewerIdentity(string $anonymity): void {
		$assignment = ['id' => 'assignment-1', 'peerReviewAnonymity' => $anonymity];
		$reviews = [
			['id' => 'pr-1', 'submissionId' => 'sub-1', 'reviewerId' => 'reviewer-a', 'totalScore' => 8, 'comments' => 'Good', 'rubricScores' => [['criterionId' => 'c-1', 'points' => 4]]],
		];

		$aggregator = $this->makeAggregator($assignment, $reviews);
		$aggregator->handle($this->makeEvent(['id' => 'pr-1', 'submissionId' => 'sub-1', 'assignmentId' => 'assignment-1']));

		self::assertCount(1, $this->savedObjects);
		self::assertSame('peer-feedback-summary', $this->savedObjects[0]['schema']);
		$summary = $this->savedObjects[0]['object'];
		self::assertSame(
			[['comments' => 'Good', 'rubricScores' => [['criterionId' => 'c-1', 'points' => 4]]]],
			$summary['feedbackItems']
		);
		$serialised = (string)json_encode($summary);
		self::assertStringNotContainsString('reviewer-a', $serialised);
		self::assertStringNotContainsString('pr-1', $serialised);
		self::assertStringNotContainsString('reviewerId', $serialised);
	}//end testTheSummaryCarriesNoReviewerIdentity()

	/**
	 * The summary names the Submission's own learners, the people its read
	 * rule lets in; empty and non-string entries and repeats are dropped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cohort-room-and-feedback-summary-for-the-learner/specs/assignments/spec.md#scenario-another-learners-summary
	 */
	public function testTheSummaryNamesTheSubmissionsLearners(): void {
		$reviews = [
			['id' => 'pr-1', 'submissionId' => 'sub-1', 'reviewerId' => 'reviewer-a', 'totalScore' => 8, 'comments' => 'Good', 'rubricScores' => []],
		];

		$aggregator = $this->makeAggregator(['id' => 'assignment-1'], $reviews, null, ['fenna', '', 7, 'sem', 'fenna']);
		$aggregator->handle($this->makeEvent(['id' => 'pr-1', 'submissionId' => 'sub-1', 'assignmentId' => 'assignment-1']));

		$summary = $this->savedObjects[0]['object'];
		self::assertSame(['fenna', 'sem'], $summary['learnerIds']);
		self::assertSame('tenant-1', $summary['tenant_id']);
	}//end testTheSummaryNamesTheSubmissionsLearners()

	/**
	 * A Submission that cannot be read leaves the summary readable by staff
	 * only: no learner is named and no tenant is guessed.
	 *
	 * @return void
	 */
	public function testAnUnreadableSubmissionNamesNoLearner(): void {
		$reviews = [
			['id' => 'pr-1', 'submissionId' => 'sub-1', 'reviewerId' => 'reviewer-a', 'totalScore' => 8, 'comments' => 'Good', 'rubricScores' => []],
		];

		$aggregator = $this->makeAggregator(['id' => 'assignment-1'], $reviews, null, null);
		$aggregator->handle($this->makeEvent(['id' => 'pr-1', 'submissionId' => 'sub-1', 'assignmentId' => 'assignment-1']));

		$summary = $this->savedObjects[0]['object'];
		self::assertSame([], $summary['learnerIds']);
		self::assertSame('', $summary['tenant_id']);
	}//end testAnUnreadableSubmissionNamesNoLearner()

	/**
	 * reviewCount and averageScore recompute correctly across multiple released reviews.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assignments/spec.md#scenario-a-teacher-releases-a-submitted-peerreview
	 */
	public function testReviewCountAndAverageScoreRecomputeCorrectly(): void {
		$assignment = ['id' => 'assignment-1', 'peerReviewAnonymity' => 'open'];
		$reviews = [
			['id' => 'pr-1', 'submissionId' => 'sub-1', 'reviewerId' => 'reviewer-a', 'totalScore' => 6, 'comments' => null, 'rubricScores' => []],
			['id' => 'pr-2', 'submissionId' => 'sub-1', 'reviewerId' => 'reviewer-b', 'totalScore' => 8, 'comments' => null, 'rubricScores' => []],
		];

		$aggregator = $this->makeAggregator($assignment, $reviews);
		$aggregator->handle($this->makeEvent(['id' => 'pr-2', 'submissionId' => 'sub-1', 'assignmentId' => 'assignment-1']));

		$summary = $this->savedObjects[0]['object'];
		self::assertSame(2, $summary['reviewCount']);
		self::assertSame(7.0, $summary['averageScore']);
		self::assertCount(2, $summary['feedbackItems']);
	}//end testReviewCountAndAverageScoreRecomputeCorrectly()

	/**
	 * A transition to a state other than `released` is ignored.
	 *
	 * @return void
	 */
	public function testIgnoresNonReleasedTransitions(): void {
		$aggregator = $this->makeAggregator(['id' => 'assignment-1', 'peerReviewAnonymity' => 'open'], []);

		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('peer-review');
		$event->method('getTo')->willReturn('submitted');

		$aggregator->handle($event);

		self::assertEmpty($this->savedObjects);
	}//end testIgnoresNonReleasedTransitions()

	/**
	 * A non-ObjectTransitionedEvent is ignored.
	 *
	 * @return void
	 */
	public function testIgnoresUnrelatedEvent(): void {
		$aggregator = $this->makeAggregator(['id' => 'assignment-1', 'peerReviewAnonymity' => 'open'], []);
		$aggregator->handle($this->createMock(Event::class));

		self::assertEmpty($this->savedObjects);
	}//end testIgnoresUnrelatedEvent()
}//end class
