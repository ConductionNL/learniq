<?php

/**
 * Learniq Peer Feedback Aggregator
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Listens for OpenRegister's ObjectTransitionedEvent, filtered to PeerReview
 * objects transitioning to `released`. Recomputes the linked Submission's
 * `PeerFeedbackSummary` — `reviewCount`, `averageScore` (mean of released
 * PeerReview.totalScore), `feedbackItems` (one per released PeerReview:
 * `comments` and `rubricScores`) and `learnerIds`, the Submission's own
 * learners, copied so the schema's read rule can name them.
 *
 * This is the server-side anonymity mechanism (design.md "Anonymity
 * Enforcement"): the summary is what the submission's learner reads, so it
 * carries no reviewer name, user id or PeerReview id in any anonymity mode —
 * the fields are never written, not merely hidden. Staff who need to know who
 * reviewed read the PeerReview rows themselves; the learner's own read of
 * those rows is denied by `x-property-rbac.read`.
 *
 * ADR-031 legitimate exception: this register's `x-openregister-aggregations`
 * vocabulary is `count`/`count_distinct` only (no `avg`, verified — a
 * full-file grep for `"metric":` finds no other value) and cannot
 * conditionally redact a field per matching row; `averageScore` and
 * `feedbackItems` require both, so they are computed here, in PHP, mirroring
 * GradeRollupHandler's "recompute on publish" shape exactly.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/assignments/spec.md#scenario-blind-and-double-blind-hide-reviewer-identity-in-the-feedback-summary
 * @spec openspec/changes/cohort-room-and-feedback-summary-for-the-learner/specs/assignments/spec.md#requirement-a-learner-reads-the-feedback-summary-of-their-own-submission-with-reviewers-anonymised
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Support\SerialisedRow;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Bridges PeerReview.released -> PeerFeedbackSummary recompute.
 *
 * @implements IEventListener<Event>
 * @spec       openspec/changes/archive/2026-07-16-peer-and-self-assessment/specs/assignments/spec.md#requirement-reviewer-identity-is-hidden-from-the-submission-author-via-a-server-enforced-feedback-projection
 */
class PeerFeedbackAggregator implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const PEER_REVIEW_SCHEMA = 'peer-review';
	private const PEER_FEEDBACK_SUMMARY_SCHEMA = 'peer-feedback-summary';
	private const SUBMISSION_SCHEMA = 'submission';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param ITimeFactory $timeFactory NC time source (injectable "now" for tests).
	 * @param ListenerSchemaResolverInterface $schemas Resolves the transition event's register and schema ids to slugs.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ITimeFactory $timeFactory,
		private readonly ListenerSchemaResolverInterface $schemas,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assignments/spec.md#scenario-a-teacher-releases-a-submitted-peerreview
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		if ($this->schemas->eventRegister(event: $event) !== self::LEARNIQ_REGISTER) {
			return;
		}

		if ($this->schemas->eventSchema(event: $event) !== self::PEER_REVIEW_SCHEMA || $event->getTo() !== 'released') {
			return;
		}

		$peerReview = $event->getObject()->jsonSerialize();
		$submissionId = $peerReview['submissionId'] ?? '';
		$assignmentId = $peerReview['assignmentId'] ?? '';

		if (is_string($submissionId) === false || $submissionId === ''
			|| is_string($assignmentId) === false || $assignmentId === ''
		) {
			return;
		}

		$this->recomputeSummary(submissionId: $submissionId, assignmentId: $assignmentId);

	}//end handle()

	/**
	 * Recompute and persist the PeerFeedbackSummary for a Submission.
	 *
	 * @param string $submissionId UUID of the Submission.
	 * @param string $assignmentId UUID of the governing Assignment.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assignments/spec.md#scenario-blind-and-double-blind-hide-reviewer-identity-in-the-feedback-summary
	 * @spec openspec/changes/cohort-room-and-feedback-summary-for-the-learner/specs/assignments/spec.md#requirement-a-learner-reads-the-feedback-summary-of-their-own-submission-with-reviewers-anonymised
	 */
	private function recomputeSummary(string $submissionId, string $assignmentId): void {
		$released = $this->fetchReleasedReviews(submissionId: $submissionId);

		$feedbackItems = [];
		$scores = [];

		foreach ($released as $review) {
			$totalScore = $review['totalScore'] ?? null;
			if (is_numeric($totalScore) === true) {
				$scores[] = (float)$totalScore;
			}

			// Only what the reviewer said, never who said it: the learner
			// reads this row, so no reviewer or PeerReview id goes in.
			$feedbackItems[] = [
				'comments' => $review['comments'] ?? null,
				'rubricScores' => $review['rubricScores'] ?? [],
			];
		}

		$averageScore = null;
		if (empty($scores) === false) {
			$averageScore = array_sum($scores) / count($scores);
		}

		$existing = $this->fetchExistingSummary(submissionId: $submissionId);
		$submission = $this->fetchObject(id: $submissionId, schema: self::SUBMISSION_SCHEMA);

		$data = array_merge(
			$existing ?? [],
			[
				'submissionId' => $submissionId,
				'assignmentId' => $assignmentId,
				'learnerIds' => $this->learnerIdsOf(submission: $submission),
				'reviewCount' => count($released),
				'averageScore' => $averageScore,
				'feedbackItems' => $feedbackItems,
				'lastComputedAt' => DateTimeImmutable::createFromMutable($this->timeFactory->getDateTime())->format(\DATE_ATOM),
				'tenant_id' => $existing['tenant_id'] ?? ($this->tenantIdOf(submission: $submission) ?? ''),
			]
		);

		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::PEER_FEEDBACK_SUMMARY_SCHEMA,
			object: $data
		);

	}//end recomputeSummary()

	/**
	 * The Submission's learner user ids, the people the summary's read rule
	 * lets in. Non-string and empty entries and repeats are dropped.
	 *
	 * @param array<string,mixed>|null $submission The Submission, or null when it cannot be read.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/cohort-room-and-feedback-summary-for-the-learner/specs/assignments/spec.md#requirement-a-learner-reads-the-feedback-summary-of-their-own-submission-with-reviewers-anonymised
	 */
	private function learnerIdsOf(?array $submission): array {
		$learnerIds = $submission['learnerIds'] ?? [];
		if (is_array($learnerIds) === false) {
			return [];
		}

		$ids = [];
		foreach ($learnerIds as $learnerId) {
			if (is_string($learnerId) === true && $learnerId !== '') {
				$ids[] = $learnerId;
			}
		}

		return array_values(array_unique($ids));
	}//end learnerIdsOf()

	/**
	 * Fetch every `released` PeerReview for a Submission.
	 *
	 * @param string $submissionId UUID of the Submission.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function fetchReleasedReviews(string $submissionId): array {
		$results = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::PEER_REVIEW_SCHEMA,
					'submissionId' => $submissionId,
					'lifecycle' => 'released',
				],
			]
		);

		$reviews = [];
		foreach ($results as $result) {
			$reviews[] = $this->toArray(value: $result);
		}

		return $reviews;
	}//end fetchReleasedReviews()

	/**
	 * Fetch the existing PeerFeedbackSummary for a Submission, if any.
	 *
	 * @param string $submissionId UUID of the Submission.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchExistingSummary(string $submissionId): ?array {
		$results = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::PEER_FEEDBACK_SUMMARY_SCHEMA,
					'submissionId' => $submissionId,
				],
				'limit' => 1,
			]
		);

		if (empty($results) === true) {
			return null;
		}

		return $this->toArray(value: $results[0]);
	}//end fetchExistingSummary()

	/**
	 * The Submission's tenant_id, for a brand-new PeerFeedbackSummary.
	 *
	 * @param array<string,mixed>|null $submission The Submission, or null when it cannot be read.
	 *
	 * @return string|null
	 */
	private function tenantIdOf(?array $submission): ?string {
		$tenantId = $submission['tenant_id'] ?? null;
		if (is_string($tenantId) === false || $tenantId === '') {
			return null;
		}

		return $tenantId;
	}//end tenantIdOf()

	/**
	 * Fetch an object by id/schema, normalising to an array.
	 *
	 * @param string $id UUID of the object.
	 * @param string $schema Schema slug.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchObject(string $id, string $schema): ?array {
		$obj = $this->objectService->find(
			id: $id,
			register: self::LEARNIQ_REGISTER,
			schema: $schema
		);

		if ($obj === null) {
			return null;
		}

		return $this->toArray(value: $obj);
	}//end fetchObject()

	/**
	 * Normalise a value that may be an array or an object exposing jsonSerialize()
	 * into a plain array.
	 *
	 * @param mixed $value The value to normalise.
	 *
	 * @return array<string,mixed>
	 */
	private function toArray(mixed $value): array {
		return (new SerialisedRow())->asArray(object: $value);
	}//end toArray()
}//end class
