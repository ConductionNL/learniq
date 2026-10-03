<?php

/**
 * Learniq xAPI Enrolment Completion
 *
 * The deferred half of XapiCompletionHandler (gate 61, ADR-078): when a
 * completed or passed statement is the final published mandatory lesson of a
 * course, runs the `complete` transition on the learner's active Enrolment.
 * Moved unchanged from the handler.
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

use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Completes an enrolment from an xAPI statement.
 *
 * ADR-031: no state machine logic, no notification dispatch, no audit
 * writing; all delegated to OR through the transition.
 *
 * @spec openspec/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
 */
class XapiEnrolmentCompletion {

	/**
	 * XAPI verb IRIs that indicate successful completion, for every xAPI listener.
	 *
	 * @var array<int, string>
	 */
	public const COMPLETION_VERBS = [
		'http://adlnet.gov/expapi/verbs/completed',
		'http://adlnet.gov/expapi/verbs/passed',
	];

	/**
	 * OR register slug for Learniq objects.
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ObjectService    $objectService    OR object service used to query Lessons and Enrolments.
	 * @param TransitionEngine $transitionEngine OR lifecycle engine used to dispatch the `complete` transition.
	 * @param LoggerInterface  $logger           PSR logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly TransitionEngine $transitionEngine,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Complete the learner's enrolment when the statement closes the course.
	 *
	 * Fires the `complete` transition on the learner's active Enrolment when:
	 *   1. verb.id is `completed` or `passed`
	 *   2. The related Lesson has mandatoryTraining=true
	 *   3. The Lesson is the final published Lesson of its Course
	 *
	 * @param array<string, mixed> $statement The XapiStatement as saved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-19
	 * @spec openspec/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
	 */
	public function complete(array $statement): void {
		$tenantId = (string)($statement['tenant_id'] ?? '');
		if (in_array(($statement['verb']['id'] ?? ''), self::COMPLETION_VERBS, true) === false) {
			return;
		}

		// The statement's object must resolve to a mandatory-training Lesson
		// that is the final published lesson of its course.
		$lesson = $this->resolveMandatoryLesson(payload: $statement, tenantId: $tenantId);
		if ($lesson === null
			|| $this->isFinalPublishedLesson(lesson: $lesson, courseId: $lesson['courseId'], tenantId: $tenantId) === false
		) {
			return;
		}

		$learnerId = $this->resolveVerifiedLearnerId(payload: $statement);
		if ($learnerId === null) {
			return;
		}

		$enrolmentId = $this->resolveActiveEnrolmentId(learnerId: $learnerId, courseId: $lesson['courseId'], tenantId: $tenantId);
		if ($enrolmentId === null) {
			return;
		}

		// OR's lifecycle engine emits the enrolment.completed audit entry and
		// the completionOnComplete notification.
		$this->transitionEngine->transition($enrolmentId, 'complete');

		$this->logger->info(
			'[XapiEnrolmentCompletion] Enrolment {id} transitioned to completed via xAPI statement.',
			['id' => $enrolmentId]
		);
	}//end complete()

	/**
	 * Resolve the learner identity this statement may act on.
	 *
	 * C6 fix: identity comes ONLY from the server-trusted `verified_actor_id`
	 * field, stamped by the authenticated xAPI ingest controller before OR
	 * writes the statement. `payload.actor.*` is NEVER read — those values are
	 * user-controlled and allow credential forgery (an attacker sets
	 * actor.account.name to a victim UUID, this handler fires, the victim's
	 * enrolment auto-completes, and a signed OB3 credential is minted under the
	 * victim's learnerId).
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
				'[XapiEnrolmentCompletion] xAPI statement missing verified_actor_id; skipping. '
				. 'Ensure the xAPI ingest controller stamps this field on authenticated saves.'
			);
			return null;
		}

		return $learnerId;
	}//end resolveVerifiedLearnerId()

	/**
	 * Add the tenant filter to a filter set when a tenant scope is known.
	 *
	 * H1: every lookup this handler makes — Lesson, published Lessons, Enrolment
	 * — must be scoped to the statement's own tenant.
	 *
	 * @param array<string,mixed> $filters The filters built so far.
	 * @param string $tenantId Tenant UUID, or '' when unknown.
	 *
	 * @return array<string,mixed> The filters, tenant-scoped when possible.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-19
	 */
	private function tenantScoped(array $filters, string $tenantId): array {
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		return $filters;
	}//end tenantScoped()

	/**
	 * Resolve the statement's object IRI to a mandatory-training Lesson.
	 *
	 * @param array<string,mixed> $payload The xAPI statement payload.
	 * @param string $tenantId Tenant UUID, or '' when unknown.
	 *
	 * @return array<string,mixed>|null The Lesson, or null when it does not resolve or is not mandatory.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-19
	 */
	private function resolveMandatoryLesson(array $payload, string $tenantId): ?array {
		$lessonId = $payload['object']['id'] ?? null;
		if ($lessonId === null) {
			return null;
		}

		$lessons = $this->objectService->findAll(
			[
				'filters' => $this->tenantScoped(
					filters: [
						'register' => self::LEARNIQ_REGISTER,
						'schema' => 'lesson',
						'xapiObjectId' => $lessonId,
					],
					tenantId: $tenantId
				),
				'limit' => 1,
			]
		);

		if (empty($lessons) === true) {
			return null;
		}

		$lesson = $this->toArray(row: $lessons[0]);

		// Only mandatory training auto-completes an enrolment.
		if (($lesson['mandatoryTraining'] ?? false) !== true) {
			return null;
		}

		// A lesson with no course cannot complete an enrolment, so it is not a
		// candidate either — the caller can rely on `courseId` being present.
		if (($lesson['courseId'] ?? null) === null) {
			return null;
		}

		return $lesson;
	}//end resolveMandatoryLesson()

	/**
	 * Whether a Lesson is the last published lesson of its course.
	 *
	 * #200: ordering is decided by the `order` field, not by insertion order, so
	 * a course whose lessons were created out of sequence still completes on its
	 * true final lesson.
	 *
	 * @param array<string,mixed> $lesson The completed Lesson.
	 * @param mixed $courseId The Lesson's course id.
	 * @param string $tenantId Tenant UUID, or '' when unknown.
	 *
	 * @return bool True when this Lesson is the course's final published lesson.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-19
	 */
	private function isFinalPublishedLesson(array $lesson, mixed $courseId, string $tenantId): bool {
		$publishedLessons = $this->objectService->findAll(
			[
				'filters' => $this->tenantScoped(
					filters: [
						'register' => self::LEARNIQ_REGISTER,
						'schema' => 'lesson',
						'courseId' => $courseId,
						'lifecycle' => 'published',
					],
					tenantId: $tenantId
				),
				'sort' => ['order' => 'ASC'],
			]
		);

		if (empty($publishedLessons) === true) {
			return false;
		}

		// Find the lesson with the highest `order` value — that is the final lesson.
		$maxOrder = -1;
		$lastLesson = null;
		foreach ($publishedLessons as $publishedLesson) {
			$data = $this->toArray(row: $publishedLesson);
			$order = (int)($data['order'] ?? 0);
			if ($order > $maxOrder) {
				$maxOrder = $order;
				$lastLesson = $data;
			}
		}

		if ($lastLesson === null) {
			return false;
		}

		return (($lastLesson['uuid'] ?? null) === ($lesson['uuid'] ?? null));
	}//end isFinalPublishedLesson()

	/**
	 * Find the learner's active Enrolment on the course and return its UUID.
	 *
	 * #179: the Enrolment's own learnerId is re-checked against the verified
	 * actor claim, so a lookup collision can never complete a different
	 * learner's enrolment.
	 *
	 * @param mixed $learnerId Server-trusted learner identity.
	 * @param mixed $courseId The course being completed.
	 * @param string $tenantId Tenant UUID, or '' when unknown.
	 *
	 * @return string|null The Enrolment UUID, or null when there is nothing to complete.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-19
	 */
	private function resolveActiveEnrolmentId(mixed $learnerId, mixed $courseId, string $tenantId): ?string {
		$enrolments = $this->objectService->findAll(
			[
				'filters' => $this->tenantScoped(
					filters: [
						'register' => self::LEARNIQ_REGISTER,
						'schema' => 'enrolment',
						'learnerId' => $learnerId,
						'courseId' => $courseId,
						'lifecycle' => 'active',
					],
					tenantId: $tenantId
				),
				'limit' => 1,
			]
		);

		if (empty($enrolments) === true) {
			$this->logger->info(
				'[XapiEnrolmentCompletion] No active Enrolment found for learner {learner} course {course}; skipping.',
				['learner' => $learnerId, 'course' => $courseId]
			);
			return null;
		}

		$enrolmentData = $this->toArray(row: $enrolments[0]);

		// #179: secondary integrity check — the enrolment's own learnerId must
		// match the actor claim to prevent a statement for learner A inadvertently
		// completing learner B's enrolment if there is a lookup collision.
		if (($enrolmentData['learnerId'] ?? '') !== $learnerId) {
			$this->logger->warning(
				'[XapiEnrolmentCompletion] Enrolment learnerId mismatch — actor claim rejected.',
				['claimed' => $learnerId, 'enrolled' => ($enrolmentData['learnerId'] ?? '')]
			);
			return null;
		}

		return (string)$enrolmentData['uuid'];
	}//end resolveActiveEnrolmentId()

	/**
	 * Normalise an OR result row to a plain array.
	 *
	 * @param mixed $row One row as returned by the OR object service.
	 *
	 * @return array<string,mixed> The row as a plain array.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-19
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		return (array)$row->jsonSerialize();
	}//end toArray()
}//end class
