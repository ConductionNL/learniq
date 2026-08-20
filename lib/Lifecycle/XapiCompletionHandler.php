<?php

/**
 * XapiCompletionHandler
 *
 * ADR-031 legitimate PHP exception: single-method lifecycle guard that bridges
 * an OR ObjectCreatedEvent (for XapiStatement objects) to an Enrolment lifecycle
 * transition. All other Enrolment behaviour is declared in lib/Settings/learniq_register.json
 * via x-openregister-lifecycle / x-openregister-notifications / x-openregister-calculations.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-19
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Listens for OR's ObjectCreatedEvent on XapiStatement objects and, when the
 * statement represents the final mandatory lesson completion of a course,
 * dispatches the `complete` transition on the matching active Enrolment.
 *
 * ADR-031 §"Lifecycle guards": single-method handler, no state machine logic,
 * no notification dispatch, no audit writing — all delegated to OR via transition.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
 *
 * @implements IEventListener<Event>
 */
class XapiCompletionHandler implements IEventListener {

	/**
	 * OR register slug for Learniq objects.
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * OR schema slug for xAPI statement objects.
	 *
	 * C5 fix: use the real kebab-case slug from learniq_register.json.
	 */
	private const XAPI_SCHEMA = 'xapi-statement';

	/**
	 * XAPI verb IRIs that indicate successful completion.
	 */
	private const COMPLETION_VERBS = [
		'http://adlnet.gov/expapi/verbs/completed',
		'http://adlnet.gov/expapi/verbs/passed',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object service used to query Lessons and Enrolments.
	 * @param TransitionEngine $transitionEngine OR lifecycle engine used to dispatch the `complete` transition.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's register/schema ids to slugs.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly TransitionEngine $transitionEngine,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an incoming ObjectCreatedEvent.
	 *
	 * Only acts on XapiStatement objects in the learniq register.
	 * Fires the `complete` transition on the learner's active Enrolment when:
	 *   1. verb.id is `completed` or `passed`
	 *   2. The related Lesson has mandatoryTraining=true
	 *   3. The Lesson is the final published Lesson of its Course
	 *
	 * @param Event $event The dispatched event from OR.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-19
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false) {
			return;
		}

		$objectEntity = $event->getObject();

		// Filter to XapiStatement objects in the learniq register only.
		if ($this->isLearniqXapiStatement(entity: $objectEntity) === false) {
			return;
		}

		$payload = $objectEntity->jsonSerialize();
		$tenantId = $payload['tenant_id'] ?? '';

		// Guard 1: verb must be completed/passed.
		if (in_array(($payload['verb']['id'] ?? ''), self::COMPLETION_VERBS, true) === false) {
			return;
		}

		// Guards 2 and 3: the statement's object must resolve to a mandatory-training Lesson.
		$lesson = $this->resolveMandatoryLesson(payload: $payload, tenantId: $tenantId);
		if ($lesson === null) {
			return;
		}

		$courseId = $lesson['courseId'];

		// Guard 4: lesson must be the final published lesson of the course.
		if ($this->isFinalPublishedLesson(lesson: $lesson, courseId: $courseId, tenantId: $tenantId) === false) {
			return;
		}

		$learnerId = $this->resolveVerifiedLearnerId(payload: $payload);
		if ($learnerId === null) {
			return;
		}

		$enrolmentId = $this->resolveActiveEnrolmentId(learnerId: $learnerId, courseId: $courseId, tenantId: $tenantId);
		if ($enrolmentId === null) {
			return;
		}

		// Dispatch the `complete` transition. OR's lifecycle engine emits the
		// enrolment.completed audit entry and the completionOnComplete notification
		// automatically — no additional PHP code needed here.
		$this->transitionEngine->transition($enrolmentId, 'complete');

		$this->logger->info(
			'[XapiCompletionHandler] Enrolment {id} transitioned to completed via xAPI statement.',
			['id' => $enrolmentId]
		);

	}//end handle()

	/**
	 * Whether the created object is an XapiStatement in the learniq register.
	 *
	 * @param mixed $entity The created ObjectEntity.
	 *
	 * @return bool True when this handler should act on it.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-19
	 */
	private function isLearniqXapiStatement(mixed $entity): bool {
		if ($this->schemaResolver->registerSlug(entity: $entity) !== self::LEARNIQ_REGISTER) {
			return false;
		}

		return ($this->schemaResolver->schemaSlug(entity: $entity) === self::XAPI_SCHEMA);
	}//end isLearniqXapiStatement()

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
				'[XapiCompletionHandler] xAPI statement missing verified_actor_id; skipping. '
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
				'register' => self::LEARNIQ_REGISTER,
				'schema' => 'lesson',
				'filters' => $this->tenantScoped(filters: ['xapiObjectId' => $lessonId], tenantId: $tenantId),
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
				'register' => self::LEARNIQ_REGISTER,
				'schema' => 'lesson',
				'filters' => $this->tenantScoped(
					filters: [
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
				'register' => self::LEARNIQ_REGISTER,
				'schema' => 'enrolment',
				'filters' => $this->tenantScoped(
					filters: [
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
				'[XapiCompletionHandler] No active Enrolment found for learner {learner} course {course}; skipping.',
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
				'[XapiCompletionHandler] Enrolment learnerId mismatch — actor claim rejected.',
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
