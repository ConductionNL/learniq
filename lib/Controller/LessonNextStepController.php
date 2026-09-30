<?php

/**
 * Learniq Lesson Next Step Controller
 *
 * `GET /api/lessons/{lessonId}/next-step` answers which lesson the caller
 * goes to after a lesson, from the lesson's next step rules and the caller's
 * own results. With `preview=1` a course author gets the answer for a
 * simulated score instead (`score`), and nothing of their own is read.
 * `GET /api/courses/{courseId}/preview` lists a course's lessons in order
 * for an author who opens Preview as learner. Both preview answers are for
 * staff only: a learner gets 403.
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
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#requirement-preview-as-learner
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\DashboardRoleService;
use OCA\Learniq\Service\NextStepResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Next step and course preview endpoints.
 *
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
 */
class LessonNextStepController extends Controller {

	private const REGISTER = 'learniq';

	/**
	 * Learniq views (DashboardRoleService::resolveViews) that author courses
	 * and may preview them: the same staff views that may read a lesson's
	 * release status without an enrolment.
	 *
	 * @var string[]
	 */
	private const STAFF_VIEWS = ['admin', 'teacher'];

	/**
	 * Constructor.
	 *
	 * @param IRequest             $request              HTTP request.
	 * @param IUserSession         $userSession          The caller.
	 * @param ObjectService        $objectService        OpenRegister object access.
	 * @param NextStepResolver     $resolver             Resolves the next lesson.
	 * @param DashboardRoleService $dashboardRoleService Resolves the caller's Learniq views.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ObjectService $objectService,
		private readonly NextStepResolver $resolver,
		private readonly DashboardRoleService $dashboardRoleService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The caller's next lesson after a lesson.
	 *
	 * @param string $lessonId The lesson uuid.
	 * @param string $preview  '1' for a preview (authors only).
	 * @param string $score    The simulated score in a preview.
	 *
	 * @return JSONResponse `{nextLessonId, nextLessonName, rule}`, or 401 / 403 / 404.
	 *
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#scenario-a-learner-who-fails-is-sent-to-a-refresher
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#scenario-a-learner-cannot-preview
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function nextStep(string $lessonId, string $preview = '', string $score = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$isPreview = $preview === '1';
		$isStaff = $this->callerIsStaff(user: $user);
		if ($isPreview === true && $isStaff === false) {
			return new JSONResponse(data: ['error' => 'Only course authors can preview a course.'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$lesson = $this->read(schema: 'lesson', id: $lessonId);
		if ($lesson === null) {
			return new JSONResponse(data: ['error' => 'Lesson not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		if ($isStaff === false && $this->isEnrolled(learnerId: $user->getUID(), courseId: (string)($lesson['courseId'] ?? '')) === false) {
			return new JSONResponse(data: ['error' => 'Not enrolled in the course this lesson belongs to'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$simulated = null;
		if ($isPreview === true && is_numeric($score) === true) {
			$simulated = (float)$score;
		}

		$next = $this->resolver->resolve(lesson: $lesson, learnerId: $user->getUID(), simulatedScore: $simulated, preview: $isPreview);
		$name = null;
		if ($next['nextLessonId'] !== null) {
			$name = ($this->read(schema: 'lesson', id: $next['nextLessonId'])['name'] ?? null);
		}

		return new JSONResponse(data: ['nextLessonId' => $next['nextLessonId'], 'nextLessonName' => $name, 'rule' => $next['rule']]);
	}//end nextStep()

	/**
	 * A course's lessons in order, for an author opening Preview as learner.
	 *
	 * @param string $courseId The course uuid.
	 *
	 * @return JSONResponse `{courseId, name, lessons: [{id, name, order}]}`, or 401 / 403 / 404.
	 *
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#scenario-a-teacher-walks-the-course-as-a-learner
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#scenario-a-learner-cannot-preview
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function coursePreview(string $courseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->callerIsStaff(user: $user) === false) {
			return new JSONResponse(data: ['error' => 'Only course authors can preview a course.'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$course = $this->read(schema: 'course', id: $courseId);
		if ($course === null) {
			return new JSONResponse(data: ['error' => 'Course not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$rows = $this->objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => 'lesson', 'courseId' => $courseId], 'limit' => 500]
		);

		$lessons = [];
		foreach ((array)$rows as $row) {
			$lesson = $this->toArray(value: $row);
			if (($lesson['courseId'] ?? '') !== $courseId) {
				continue;
			}

			$lessons[] = [
				'id' => (string)($lesson['id'] ?? ''),
				'name' => (string)($lesson['name'] ?? ''),
				'order' => (int)($lesson['order'] ?? 0),
			];
		}

		usort($lessons, static fn (array $left, array $right): int => $left['order'] <=> $right['order']);

		return new JSONResponse(data: ['courseId' => $courseId, 'name' => (string)($course['name'] ?? ''), 'lessons' => $lessons]);
	}//end coursePreview()

	/**
	 * Whether the caller holds a Learniq staff view.
	 *
	 * @param IUser $user The caller.
	 *
	 * @return bool
	 */
	private function callerIsStaff(IUser $user): bool {
		$views = $this->dashboardRoleService->resolveViews(user: $user);

		return count(array_intersect($views, self::STAFF_VIEWS)) > 0;
	}//end callerIsStaff()

	/**
	 * Whether the learner has an enrolment in the course.
	 *
	 * @param string $learnerId The learner.
	 * @param string $courseId  The course uuid.
	 *
	 * @return bool
	 */
	private function isEnrolled(string $learnerId, string $courseId): bool {
		if ($courseId === '') {
			return false;
		}

		$rows = $this->objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => 'enrolment', 'learnerId' => $learnerId, 'courseId' => $courseId], 'limit' => 1]
		);

		return empty($rows) === false;
	}//end isEnrolled()

	/**
	 * One row by id through the caller's own access, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(string $schema, string $id): ?array {
		try {
			$object = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema);
		} catch (DoesNotExistException) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(value: $object);
	}//end read()

	/**
	 * An OpenRegister row as an array.
	 *
	 * @param mixed $value An ObjectEntity or an array.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $value): array {
		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$value = $value->jsonSerialize();
		}

		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end toArray()
}//end class
