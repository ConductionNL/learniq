<?php

/**
 * Learniq Lesson Release Controller
 *
 * Computes, per request, whether a Lesson or Assessment is available to the
 * calling learner right now — a genuine per-(item, learner) gate decision
 * (adaptive release / drip scheduling), not a pass-through CRUD read.
 * Delegates all evaluation logic to {@see \OCA\Learniq\Service\LessonReleaseEvaluator}.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
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
 * @spec openspec/specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions
 * @spec openspec/specs/assessment/spec.md#requirement-assessment-declares-per-learner-release-conditions
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\AssessmentAccessFactsInterface;
use OCA\Learniq\Kernel\Contracts\DashboardRoleServiceInterface;
use OCA\Learniq\Kernel\Contracts\LessonReleaseEvaluatorInterface;
use OCA\Learniq\Support\FindOrNull;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Throwable;

/**
 * `GET /api/lessons/{lessonId}/release-status` and
 * `GET /api/assessments/{assessmentId}/release-status`.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions
 */
class LessonReleaseController extends Controller {
	use FindOrNull;

	private const LEARNIQ_REGISTER = 'learniq';
	private const LESSON_SCHEMA = 'lesson';
	private const ASSESSMENT_SCHEMA = 'exam';
	private const ENROLMENT_SCHEMA = 'enrolment';

	/**
	 * Learniq roles (DashboardRoleService::resolveViews) that may view a
	 * Lesson/Assessment's release status without holding a personal
	 * Enrolment — staff previewing content, not the module's rank-and-file
	 * learner path.
	 *
	 * @var string[]
	 */
	private const STAFF_VIEWS = ['admin', 'teacher'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param IUserSession $userSession NC user session.
	 * @param ObjectService $objectService OR object access service.
	 * @param LessonReleaseEvaluatorInterface $releaseEvaluator Stateless release-gate evaluator.
	 * @param DashboardRoleServiceInterface $dashboardRoleService Resolves the caller's Learniq role/views.
	 * @param AssessmentAccessFactsInterface $accessFacts Attempt-gate facts for an Assessment.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ObjectService $objectService,
		private readonly LessonReleaseEvaluatorInterface $releaseEvaluator,
		private readonly DashboardRoleServiceInterface $dashboardRoleService,
		private readonly AssessmentAccessFactsInterface $accessFacts,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Release status for a Lesson.
	 *
	 * @param string $lessonId UUID of the Lesson.
	 *
	 * @return JSONResponse `{available, reason, availableAt}`, or an error.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function status(string $lessonId): JSONResponse {
		return $this->resolveStatus(itemId: $lessonId, itemSchema: self::LESSON_SCHEMA);
	}//end status()

	/**
	 * Release status for an Assessment.
	 *
	 * @param string $assessmentId UUID of the Assessment.
	 *
	 * @return JSONResponse `{available, reason, availableAt}`, or an error.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-assessment-declares-per-learner-release-conditions
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function assessmentStatus(string $assessmentId): JSONResponse {
		return $this->resolveStatus(itemId: $assessmentId, itemSchema: self::ASSESSMENT_SCHEMA);
	}//end assessmentStatus()

	/**
	 * Shared resolution: authenticate, resolve the item, authorize the
	 * caller, resolve their own Enrolment (if any), and evaluate.
	 *
	 * @param string $itemId UUID of the Lesson or Assessment.
	 * @param string $itemSchema 'lesson' or 'exam'.
	 *
	 * @return JSONResponse
	 */
	private function resolveStatus(string $itemId, string $itemSchema): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$item = $this->itemOrError(itemId: $itemId, itemSchema: $itemSchema);
		if ($item instanceof JSONResponse) {
			return $item;
		}

		$isStaff = $this->callerIsStaff(user: $user);
		$enrolment = $this->enrolmentFor(user: $user, item: $item);

		// Any authenticated learner holding an Enrolment for the item's
		// course may see its own release status; staff (admin/teacher-
		// equivalent) may always preview it, even without a personal
		// Enrolment. Everyone else is denied — this endpoint computes new
		// information (a lock/unlock decision), not a pass-through read.
		if ($this->isRefused(enrolment: $enrolment, isStaff: $isStaff) === true) {
			return new JSONResponse(
				data: ['error' => 'Not enrolled in the course this item belongs to'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		$result = $this->releaseEvaluator->evaluate(
			item: $item,
			itemSchema: $itemSchema,
			learnerId: $user->getUID(),
			enrolment: $enrolment
		);

		$data = [
			'available' => $result['available'],
			'reason' => $result['reason'],
			'availableAt' => $result['availableAt'],
		];
		if ($itemSchema === self::ASSESSMENT_SCHEMA) {
			$data = array_merge($data, $this->accessFacts->assessmentAccess(assessmentId: $itemId, item: $item));
		}

		return new JSONResponse(data: $data);

	}//end resolveStatus()


	/**
	 * Whether the caller holds a Learniq staff (admin/teacher-equivalent)
	 * view, per DashboardRoleService::resolveViews().
	 *
	 * @param IUser $user The authenticated caller.
	 *
	 * @return bool
	 */
	private function callerIsStaff(IUser $user): bool {
		$views = $this->dashboardRoleService->resolveViews(user: $user);

		return count(array_intersect($views, self::STAFF_VIEWS)) > 0;
	}//end callerIsStaff()

	/**
	 * Whether the caller is refused the item's status: no own Enrolment and
	 * no staff view.
	 *
	 * @param array<string, mixed> $enrolment The caller's own Enrolment, or `[]`.
	 * @param bool                 $isStaff   Whether the caller holds a staff view.
	 *
	 * @return bool True to answer 403.
	 */
	private function isRefused(array $enrolment, bool $isStaff): bool {
		return $enrolment === [] && $isStaff === false;
	}//end isRefused()

	/**
	 * The caller's own Enrolment for the item's course, or `[]` when the item
	 * names no course or the caller has none.
	 *
	 * @param IUser                $user The authenticated caller.
	 * @param array<string, mixed> $item The Lesson or Assessment.
	 *
	 * @return array<string, mixed>
	 */
	private function enrolmentFor(IUser $user, array $item): array {
		$courseId = (string)($item['courseId'] ?? '');
		if ($courseId === '') {
			return [];
		}

		return $this->resolveEnrolment(learnerId: $user->getUID(), courseId: $courseId);
	}//end enrolmentFor()

	/**
	 * Resolve the caller's own Enrolment for a course, or `[]` when none.
	 *
	 * @param string $learnerId NC user ID.
	 * @param string $courseId UUID of the Course.
	 *
	 * @return array<string, mixed>
	 */
	private function resolveEnrolment(string $learnerId, string $courseId): array {
		$enrolments = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::ENROLMENT_SCHEMA,
					'learnerId' => $learnerId,
					'courseId' => $courseId,
				],
				'limit' => 1,
			]
		);

		if (empty($enrolments) === true) {
			return [];
		}

		return $this->toArray(object: $enrolments[0]);
	}//end resolveEnrolment()

	/**
	 * Resolve a Lesson/Assessment by UUID.
	 *
	 * @param string $id UUID of the object.
	 * @param string $schema Schema slug.
	 *
	 * @return array<string, mixed>|null Null when no such object exists.
	 *
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException When the id matches more than one object; LookupIntegrityMiddleware answers it.
	 */
	private function resolveObject(string $id, string $schema): ?array {
		$object = $this->findOrNull(find: fn () => $this->objectService->find(id: $id, register: self::LEARNIQ_REGISTER, schema: $schema));
		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}//end resolveObject()

	/**
	 * Normalise an ObjectService result (array or ObjectEntity) to a plain array.
	 *
	 * @param mixed $object The result row.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray($object): array {
		if (is_array($object) === true) {
			return $object;
		}

		return $object->jsonSerialize();
	}//end toArray()

	/**
	 * The item, null when it does not exist, or a 502 when it could not be read.
	 *
	 * @param string $itemId     UUID of the Lesson or Assessment.
	 * @param string $itemSchema 'lesson' or 'exam'.
	 *
	 * @return array<string, mixed>|JSONResponse|null
	 *
	 * @throws MultipleObjectsReturnedException When the id matches more than one object; LookupIntegrityMiddleware answers it.
	 */
	private function itemOrBadGateway(string $itemId, string $itemSchema): array|JSONResponse|null {
		try {
			return $this->resolveObject(id: $itemId, schema: $itemSchema);
		} catch (MultipleObjectsReturnedException $exception) {
			throw $exception;
		} catch (Throwable) {
			return new JSONResponse(
				data: ['error' => 'Failed to resolve the requested item'],
				statusCode: Http::STATUS_BAD_GATEWAY
			);
		}
	}//end itemOrBadGateway()

	/**
	 * The item, or the 404 or 502 that answers for it.
	 *
	 * @param string $itemId     UUID of the Lesson or Assessment.
	 * @param string $itemSchema 'lesson' or 'exam'.
	 *
	 * @return array<string, mixed>|JSONResponse
	 *
	 * @throws MultipleObjectsReturnedException When the id matches more than one object; LookupIntegrityMiddleware answers it.
	 */
	private function itemOrError(string $itemId, string $itemSchema): array|JSONResponse {
		$item = $this->itemOrBadGateway(itemId: $itemId, itemSchema: $itemSchema);
		if ($item === null) {
			return $this->notFound(itemSchema: $itemSchema);
		}

		return $item;
	}//end itemOrError()

	/**
	 * The 404 for an item that does not exist, named after its kind.
	 *
	 * @param string $itemSchema 'lesson' or 'exam'.
	 *
	 * @return JSONResponse
	 */
	private function notFound(string $itemSchema): JSONResponse {
		$notFoundLabel = 'Lesson not found';
		if ($itemSchema === self::ASSESSMENT_SCHEMA) {
			$notFoundLabel = 'Assessment not found';
		}

		return new JSONResponse(data: ['error' => $notFoundLabel], statusCode: Http::STATUS_NOT_FOUND);
	}//end notFound()
}//end class
