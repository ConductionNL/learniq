<?php

/**
 * Learniq Lesson Progress
 *
 * The deferred half of LessonProgressHandler (gate 61, ADR-078): upserts a
 * LessonCompletion for a resolvable completed or passed xAPI statement, with
 * no mandatoryTraining or last-lesson gate. Moved unchanged from the handler;
 * the completion time is the moment the statement was queued.
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
 * @spec openspec/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Records that a lesson was completed.
 *
 * @spec openspec/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
 */
class LessonProgress {

	private const LEARNIQ_REGISTER = 'learniq';
	private const LESSON_SCHEMA = 'lesson';
	private const LESSON_COMPLETION_SCHEMA = 'lesson-completion';
	private const ENROLMENT_SCHEMA = 'enrolment';

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OR object service used to query/write objects.
	 * @param LoggerInterface $logger        PSR logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Upsert the LessonCompletion for one completed or passed statement.
	 *
	 * @param array<string, mixed> $statement   The XapiStatement as saved.
	 * @param string               $completedAt When it was queued, ISO 8601.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/progress-tracking/spec.md#requirement-xapi-completion-statements-are-wired-into-per-lesson-completion-not-duplicated
	 * @spec openspec/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
	 */
	public function record(array $statement, string $completedAt): void {
		$tenantId = (string)($statement['tenant_id'] ?? '');
		$verbId = (string)($statement['verb']['id'] ?? '');
		if (in_array($verbId, XapiEnrolmentCompletion::COMPLETION_VERBS, true) === false) {
			return;
		}

		$lesson = $this->resolveLesson(payload: $statement, tenantId: $tenantId);
		if ($lesson === null) {
			// No resolvable Lesson: skipped without error, exactly like the
			// enrolment completion skips an unresolvable object id.
			return;
		}

		$lessonId = $lesson['id'] ?? ($lesson['uuid'] ?? null);
		$courseId = $lesson['courseId'] ?? null;
		if ($lessonId === null || $courseId === null) {
			return;
		}

		$learnerId = $this->resolveVerifiedLearnerId(payload: $statement);
		if ($learnerId === null) {
			return;
		}

		$this->upsertLessonCompletion(
			learnerId: $learnerId,
			lessonId: $lessonId,
			courseId: $courseId,
			enrolmentId: $this->resolveActiveEnrolmentId(learnerId: $learnerId, courseId: $courseId, tenantId: $tenantId),
			verb: $verbId,
			score: ($statement['result']['score']['scaled'] ?? null),
			tenantId: $tenantId,
			completedAt: $completedAt
		);
	}//end record()

	/**
	 * Resolve the learner identity this statement may act on.
	 *
	 * C6: identity comes ONLY from the server-trusted `verified_actor_id` field
	 * — the same trust boundary XapiCompletionHandler enforces.
	 * `payload.actor.*` is NEVER read, because it is user-controlled.
	 *
	 * @param array<string,mixed> $payload The xAPI statement payload.
	 *
	 * @return string|null The verified learner id, or null when the statement carries none.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-19
	 */
	private function resolveVerifiedLearnerId(array $payload): ?string {
		$learnerId = (string)($payload['verified_actor_id'] ?? '');
		if ($learnerId === '') {
			$this->logger->warning(
				'[LessonProgress] xAPI statement missing verified_actor_id; skipping. '
				. 'Ensure the xAPI ingest controller stamps this field on authenticated saves.'
			);
			return null;
		}

		return $learnerId;
	}//end resolveVerifiedLearnerId()

	/**
	 * Resolve the Lesson referenced by the xAPI statement's object id — the
	 * same xapiObjectId lookup XapiCompletionHandler already uses.
	 *
	 * @param array<string, mixed> $payload The XapiStatement payload.
	 * @param string $tenantId Tenant scope for the lookup.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolveLesson(array $payload, string $tenantId): ?array {
		$lessonObjectId = $payload['object']['id'] ?? null;
		if ($lessonObjectId === null) {
			return null;
		}

		$lessonFilters = ['xapiObjectId' => $lessonObjectId];
		if ($tenantId !== '') {
			$lessonFilters['tenant_id'] = $tenantId;
		}

		$lessons = $this->objectService->findAll(
			[
				'filters' => array_merge(
					$lessonFilters,
					[
						'register' => self::LEARNIQ_REGISTER,
						'schema' => self::LESSON_SCHEMA,
					]
				),
				'limit' => 1,
			]
		);

		if (empty($lessons) === true) {
			return null;
		}

		$lesson = $lessons[0];
		if (is_array($lesson) === false) {
			$lesson = $lesson->jsonSerialize();
		}

		return $lesson;
	}//end resolveLesson()

	/**
	 * Resolve the learner's current Enrolment id for this Course: the active
	 * one, otherwise a pending one (a completion made before the enrolment is
	 * activated still belongs to it), otherwise null.
	 *
	 * @param string $learnerId NC user ID of the learner.
	 * @param string $courseId UUID of the Course.
	 * @param string $tenantId Tenant scope for the lookup.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/progress-tracking/spec.md#requirement-a-lesson-completion-belongs-to-one-enrolment
	 */
	private function resolveActiveEnrolmentId(string $learnerId, string $courseId, string $tenantId): ?string {
		foreach (['active', 'pending'] as $lifecycle) {
			$filters = [
				'learnerId' => $learnerId,
				'courseId' => $courseId,
				'lifecycle' => $lifecycle,
			];
			if ($tenantId !== '') {
				$filters['tenant_id'] = $tenantId;
			}

			$enrolments = $this->objectService->findAll(
				[
					'filters' => array_merge(
						$filters,
						[
							'register' => self::LEARNIQ_REGISTER,
							'schema' => self::ENROLMENT_SCHEMA,
						]
					),
					'limit' => 1,
				]
			);

			if (empty($enrolments) === false) {
				$enrolment = $enrolments[0];
				if (is_array($enrolment) === false) {
					$enrolment = $enrolment->jsonSerialize();
				}

				return $enrolment['id'] ?? ($enrolment['uuid'] ?? null);
			}
		}//end foreach

		return null;
	}//end resolveActiveEnrolmentId()

	/**
	 * Create or update the LessonCompletion for (enrolmentId, lessonId).
	 *
	 * A duplicate completion statement within the same enrolment updates that
	 * enrolment's row rather than duplicating it. A completion in a later
	 * enrolment (a retake, a re-enrolment, a recertification) adds a new row
	 * and leaves the earlier enrolment's row, and its enrolmentId, untouched
	 * (learniq#945). Rows are matched in PHP rather than with an enrolmentId
	 * filter, so a legacy row without an enrolment is matched only by a
	 * completion that also has none.
	 *
	 * @param string $learnerId NC user ID of the learner.
	 * @param string $lessonId UUID of the completed Lesson.
	 * @param string $courseId UUID of the Lesson's parent Course.
	 * @param string|null $enrolmentId UUID of the learner's current Enrolment, if any.
	 * @param string $verb The xAPI verb IRI.
	 * @param float|null $score Optional result.score.scaled value.
	 * @param string $tenantId Tenant identifier.
	 * @param string $completedAt When the statement was queued, ISO 8601.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/progress-tracking/spec.md#requirement-a-lesson-completion-belongs-to-one-enrolment
	 */
	private function upsertLessonCompletion(
		string $learnerId,
		string $lessonId,
		string $courseId,
		?string $enrolmentId,
		string $verb,
		?float $score,
		string $tenantId,
		string $completedAt,
	): void {
		$existing = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::LESSON_COMPLETION_SCHEMA,
					'learnerId' => $learnerId,
					'lessonId' => $lessonId,
				],
			]
		);

		$existingData = null;
		foreach ($existing as $row) {
			if (is_array($row) === false) {
				$row = $row->jsonSerialize();
			}

			if ((string)($row['enrolmentId'] ?? '') === (string)($enrolmentId ?? '')) {
				$existingData = $row;
				break;
			}
		}

		$data = array_merge(
			$existingData ?? [],
			[
				'learnerId' => $learnerId,
				'lessonId' => $lessonId,
				'courseId' => $courseId,
				'enrolmentId' => $enrolmentId,
				'source' => 'xapi',
				'verb' => $verb,
				'score' => $score,
				'completedAt' => $completedAt,
				'tenant_id' => $tenantId,
			]
		);

		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::LESSON_COMPLETION_SCHEMA,
			object: $data
		);

	}//end upsertLessonCompletion()
}//end class
