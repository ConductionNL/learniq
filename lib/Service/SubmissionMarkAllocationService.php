<?php

/**
 * Learniq Submission Mark Allocation Service
 *
 * Allocates markers to the handed-in submissions of a double-marked
 * assignment (assignments-double-marking). Allocation tops up one draft
 * SubmissionMark per (marker, submission) pair and never duplicates a pair,
 * refuses more markers than the assignment's `markersPerSubmission`, and
 * refuses a marker who is one of the submission's own learners. It writes the
 * union of markers to `Submission.markerIds`, the only writer of that field.
 *
 * Imperative for the reason PeerReviewAllocationService is: a batch over a set
 * of submissions with an idempotent top-up, which a per-object declaration
 * cannot express. The caller (SubmissionMarkController) checks who may
 * allocate; this service reads and writes as the system after that check.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/specs/assignments/spec.md#requirement-the-teacher-in-charge-allocates-markers
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Allocates SubmissionMark rows for an assignment's handed-in submissions.
 *
 * @spec openspec/specs/assignments/spec.md#requirement-the-teacher-in-charge-allocates-markers
 */
class SubmissionMarkAllocationService {

	private const REGISTER = 'learniq';
	private const SUBMISSION_SCHEMA = 'submission';
	private const MARK_SCHEMA = 'submission-mark';

	/**
	 * Submission lifecycles that count as handed in and may be marked.
	 */
	private const HANDED_IN_STATES = ['submitted', 'late'];

	/**
	 * Explicit read limits instead of OpenRegister's default page.
	 */
	private const READ_LIMIT = 5000;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Allocate the given markers to every handed-in submission of the
	 * assignment, or to one submission when `$submissionId` is set.
	 *
	 * @param array<string, mixed> $assignment   The assignment, already read by the caller.
	 * @param array<int, string>   $markerIds    Nextcloud user ids of the markers.
	 * @param string               $submissionId One submission's uuid, or '' for all.
	 *
	 * @return array{error: string|null, submissionsProcessed: int, createdCount: int, refused: list<array<string, string>>}
	 *
	 * @spec openspec/specs/assignments/spec.md#scenario-a-coordinator-allocates-two-markers-to-every-hand-in
	 * @spec openspec/specs/assignments/spec.md#scenario-a-learner-cannot-mark-their-own-group-work
	 */
	public function allocate(array $assignment, array $markerIds, string $submissionId = ''): array {
		$markerIds = $this->cleanMarkers(markerIds: $markerIds);
		$maximum = (int)($assignment['markersPerSubmission'] ?? 1);

		if ($maximum < 2) {
			return $this->result(error: 'single-marker');
		}

		if ($markerIds === []) {
			return $this->result(error: 'no-markers');
		}

		if (count($markerIds) > $maximum) {
			return $this->result(error: 'too-many-markers');
		}

		$assignmentId = (string)($assignment['id'] ?? '');
		$submissions = $this->handedIn(assignmentId: $assignmentId, submissionId: $submissionId);
		if ($submissionId !== '' && $submissions === []) {
			return $this->result(error: 'submission-not-found');
		}

		$existing = $this->existingMarkers(assignmentId: $assignmentId);
		$created = 0;
		$refused = [];

		foreach ($submissions as $submission) {
			$outcome = $this->allocateOne(
				submission: $submission,
				markerIds: $markerIds,
				already: ($existing[(string)$submission['id']] ?? []),
				maximum: $maximum
			);
			$created += $outcome['created'];
			array_push($refused, ...$outcome['refused']);
		}

		return $this->result(error: null, processed: count($submissions), created: $created, refused: $refused);
	}//end allocate()

	/**
	 * Allocate markers to one submission and record the union on it.
	 *
	 * @param array<string, mixed> $submission The submission.
	 * @param array<int, string>   $markerIds  The markers asked for.
	 * @param array<int, string>   $already    The markers that already have a mark here.
	 * @param int                  $maximum    The assignment's markersPerSubmission.
	 *
	 * @return array{created: int, refused: list<array<string, string>>}
	 */
	private function allocateOne(array $submission, array $markerIds, array $already, int $maximum): array {
		$submissionId = (string)$submission['id'];
		$learners = $this->stringList(value: ($submission['learnerIds'] ?? []));
		$refused = [];
		$toCreate = [];

		foreach ($markerIds as $markerId) {
			if (in_array($markerId, $already, true) === true) {
				continue;
			}

			if (in_array($markerId, $learners, true) === true) {
				$refused[] = ['submissionId' => $submissionId, 'markerId' => $markerId, 'reason' => 'marker-is-learner'];
				continue;
			}

			if (count($already) + count($toCreate) >= $maximum) {
				$refused[] = ['submissionId' => $submissionId, 'markerId' => $markerId, 'reason' => 'submission-full'];
				continue;
			}

			$toCreate[] = $markerId;
		}

		foreach ($toCreate as $markerId) {
			$this->objectService->saveObject(
				register: self::REGISTER,
				schema: self::MARK_SCHEMA,
				object: [
					'submissionId' => $submissionId,
					'assignmentId' => (string)($submission['assignmentId'] ?? ''),
					'markerId' => $markerId,
					'rubricScores' => [],
					'lifecycle' => 'draft',
					'tenant_id' => (string)($submission['tenant_id'] ?? ''),
				],
				_rbac: false
			);
		}

		$union = array_values(array_unique(array_merge($this->stringList(value: ($submission['markerIds'] ?? [])), $already, $toCreate)));
		if ($union !== $this->stringList(value: ($submission['markerIds'] ?? []))) {
			$row = $submission;
			unset($row['@self']);
			$row['markerIds'] = $union;
			$this->objectService->saveObject(
				register: self::REGISTER,
				schema: self::SUBMISSION_SCHEMA,
				object: $row,
				uuid: $submissionId,
				_rbac: false
			);
		}

		return ['created' => count($toCreate), 'refused' => $refused];
	}//end allocateOne()

	/**
	 * The handed-in submissions of the assignment, or the one named.
	 *
	 * @param string $assignmentId The assignment's uuid.
	 * @param string $submissionId One submission's uuid, or ''.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function handedIn(string $assignmentId, string $submissionId): array {
		$rows = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SUBMISSION_SCHEMA,
					'assignmentId' => $assignmentId,
				],
				'limit' => self::READ_LIMIT,
			],
			_rbac: false
		);

		$submissions = [];
		foreach ($rows as $row) {
			$submission = $this->toArray(value: $row);
			if (in_array(($submission['lifecycle'] ?? ''), self::HANDED_IN_STATES, true) === false) {
				continue;
			}

			if ($submissionId !== '' && $submission['id'] !== $submissionId) {
				continue;
			}

			$submissions[] = $submission;
		}

		return $submissions;
	}//end handedIn()

	/**
	 * The markers that already have a mark, per submission id.
	 *
	 * @param string $assignmentId The assignment's uuid.
	 *
	 * @return array<string, list<string>>
	 */
	private function existingMarkers(string $assignmentId): array {
		$rows = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::MARK_SCHEMA,
					'assignmentId' => $assignmentId,
				],
				'limit' => self::READ_LIMIT,
			],
			_rbac: false
		);

		$bySubmission = [];
		foreach ($rows as $row) {
			$mark = $this->toArray(value: $row);
			$bySubmission[(string)($mark['submissionId'] ?? '')][] = (string)($mark['markerId'] ?? '');
		}

		return $bySubmission;
	}//end existingMarkers()

	/**
	 * The markers asked for: non-empty strings, each once.
	 *
	 * @param array<int, mixed> $markerIds The raw list.
	 *
	 * @return list<string>
	 */
	private function cleanMarkers(array $markerIds): array {
		return array_values(array_unique(array_filter($this->stringList(value: $markerIds), static fn (string $id): bool => $id !== '')));
	}//end cleanMarkers()

	/**
	 * A list of trimmed strings from a value that should be one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return list<string>
	 */
	private function stringList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$list = [];
		foreach ($value as $item) {
			if (is_string($item) === true) {
				$list[] = trim($item);
			}
		}

		return $list;
	}//end stringList()

	/**
	 * An OpenRegister result as a plain array with a string `id`.
	 *
	 * @param mixed $value An array or an entity.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $value): array {
		$row = $value;
		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$row = $value->jsonSerialize();
		}

		if (is_array($row) === false) {
			return ['id' => ''];
		}

		$row['id'] = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));

		return $row;
	}//end toArray()

	/**
	 * The allocation summary.
	 *
	 * @param string|null                                                            $error     A refusal of the whole request, or null.
	 * @param int                                                                    $processed Submissions looked at.
	 * @param int                                                                    $created   Marks created.
	 * @param list<array<string, string>>                                          $refused   Per-pair refusals.
	 *
	 * @return array{error: string|null, submissionsProcessed: int, createdCount: int, refused: list<array<string, string>>}
	 */
	private function result(?string $error, int $processed = 0, int $created = 0, array $refused = []): array {
		return ['error' => $error, 'submissionsProcessed' => $processed, 'createdCount' => $created, 'refused' => $refused];
	}//end result()
}//end class
