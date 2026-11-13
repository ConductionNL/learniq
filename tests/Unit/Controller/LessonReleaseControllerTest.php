<?php

/**
 * Learniq LessonReleaseController unit tests.
 *
 * Covers: an enrolled learner gets a real evaluator decision; a
 * non-enrolled, non-staff caller gets 403; a staff (admin/teacher-view)
 * caller gets a real decision regardless of their own enrolment; an unknown
 * item id returns 404; and the response never leaks the raw
 * releaseConditions configuration or another learner's data — only
 * `{available, reason, availableAt}`.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
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

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Controller\LessonReleaseController;
use OCA\Learniq\Service\AssessmentAccessFacts;
use OCA\Learniq\Service\LessonReleaseEvaluator;
use OCA\Learniq\Service\DashboardRoleService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LessonReleaseController::status() / assessmentStatus().
 */
class LessonReleaseControllerTest extends TestCase {

	/**
	 * In-memory fake OR datastore, keyed by schema slug.
	 *
	 * @var array<string, array<int, array<string,mixed>>>
	 */
	private array $db = [];

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * @var DashboardRoleService&MockObject
	 */
	private DashboardRoleService&MockObject $dashboardRoleService;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db = [];
		$this->userSession = $this->createMock(IUserSession::class);
		$this->dashboardRoleService = $this->createMock(DashboardRoleService::class);

	}//end setUp()

	/**
	 * Seed a record into the fake datastore.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string, mixed> $record Record data.
	 *
	 * @return void
	 */
	private function seed(string $schema, array $record): void {
		$this->db[$schema][] = $record;

	}//end seed()

	/**
	 * Sign the caller in as the given uid with the given Learniq views.
	 *
	 * @param string $uid NC user id.
	 * @param string[] $views DashboardRoleService::resolveViews() result.
	 *
	 * @return void
	 */
	private function signInAs(string $uid, array $views = ['student']): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->dashboardRoleService->method('resolveViews')->willReturn($views);

	}//end signInAs()

	/**
	 * Build the controller under test, with a real ObjectService double and
	 * an evaluator that always returns a fixed, recognisable decision.
	 *
	 * @param array{available: bool, reason: string|null, availableAt: string|null}|null $evaluatorResult
	 *
	 * @return LessonReleaseController
	 */
	private function controller(?array $evaluatorResult = null): LessonReleaseController {
		$objectService = $this->createMock(ObjectService::class);

		// OpenRegister's find() is find($id, $_extend, $files, $register, $schema, ...)
		// and returns ?ObjectEntity. willReturnCallback() hands the closure the
		// mock's arguments POSITIONALLY, so the closure must mirror that order.
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) {
				foreach (($this->db[$schema] ?? []) as $rec) {
					if (($rec['id'] ?? null) === $id) {
						return OrEntityFactory::make($rec, (string)$schema);
					}
				}

				return null;
			}
		);

		$objectService->method('findAll')->willReturnCallback(
			function (array $config) {
				$schema = $config['filters']['schema'];
				$filters = array_diff_key(($config['filters'] ?? []), ['register' => true, 'schema' => true]);

				$rows = array_values(
					array_filter(
						($this->db[$schema] ?? []),
						static function (array $rec) use ($filters) {
							foreach ($filters as $key => $value) {
								if (($rec[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);

				return OrEntityFactory::makeMany($rows, (string)$schema);
			}
		);

		$evaluator = $this->createMock(LessonReleaseEvaluator::class);
		$evaluator->method('evaluate')->willReturn(
			$evaluatorResult ?? ['available' => true, 'reason' => null, 'availableAt' => null]
		);
		$accessFacts = $this->createMock(AssessmentAccessFacts::class);
		$accessFacts->method('assessmentAccess')->willReturn(
			['reasonCode' => 'window-not-open', 'requiresAccessCode' => true]
		);

		return new LessonReleaseController(
			request: $this->createMock(IRequest::class),
			userSession: $this->userSession,
			objectService: $objectService,
			releaseEvaluator: $evaluator,
			dashboardRoleService: $this->dashboardRoleService,
			accessFacts: $accessFacts,
		);

	}//end controller()

	/**
	 * An enrolled learner receives the evaluator's real decision.
	 *
	 * @return void
	 */
	public function testEnrolledLearnerGetsRealDecision(): void {
		$this->seed('lesson', ['id' => 'lesson-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']);
		$this->seed('enrolment', ['id' => 'enrolment-1', 'learnerId' => 'learner-1', 'courseId' => 'course-1']);
		$this->signInAs('learner-1');

		$controller = $this->controller(['available' => false, 'reason' => 'Complete "Lesson A" first.', 'availableAt' => null]);
		$response = $controller->status('lesson-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertFalse($response->getData()['available']);
		self::assertSame('Complete "Lesson A" first.', $response->getData()['reason']);

	}//end testEnrolledLearnerGetsRealDecision()

	/**
	 * A non-enrolled, non-staff caller is denied with 403.
	 *
	 * @return void
	 */
	public function testNonEnrolledNonStaffCallerIsDenied(): void {
		$this->seed('lesson', ['id' => 'lesson-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']);
		$this->signInAs('learner-2', views: ['student']);

		$controller = $this->controller();
		$response = $controller->status('lesson-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testNonEnrolledNonStaffCallerIsDenied()

	/**
	 * A staff (admin/teacher view) caller gets a real decision even without
	 * holding a personal Enrolment for the course.
	 *
	 * @return void
	 */
	public function testStaffCallerGetsDecisionWithoutOwnEnrolment(): void {
		$this->seed('lesson', ['id' => 'lesson-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']);
		$this->signInAs('teacher-1', views: ['teacher', 'student']);

		$controller = $this->controller(['available' => true, 'reason' => null, 'availableAt' => null]);
		$response = $controller->status('lesson-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($response->getData()['available']);

	}//end testStaffCallerGetsDecisionWithoutOwnEnrolment()

	/**
	 * An admin-view caller is also treated as staff.
	 *
	 * @return void
	 */
	public function testAdminViewCallerIsTreatedAsStaff(): void {
		$this->seed('exam', ['id' => 'assessment-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']);
		$this->signInAs('admin-1', views: ['admin', 'teacher', 'student']);

		$controller = $this->controller();
		$response = $controller->assessmentStatus('assessment-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testAdminViewCallerIsTreatedAsStaff()

	/**
	 * An unauthenticated caller is rejected with 401.
	 *
	 * @return void
	 */
	public function testUnauthenticatedCallerRejected(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$controller = $this->controller();
		$response = $controller->status('lesson-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testUnauthenticatedCallerRejected()

	/**
	 * An unknown Lesson id returns 404.
	 *
	 * @return void
	 */
	public function testUnknownLessonReturnsNotFound(): void {
		$this->signInAs('learner-1');

		$controller = $this->controller();
		$response = $controller->status('does-not-exist');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testUnknownLessonReturnsNotFound()

	/**
	 * The response carries only {available, reason, availableAt} — no raw
	 * releaseConditions configuration or other data.
	 *
	 * @return void
	 */
	public function testResponseShapeIsMinimal(): void {
		$this->seed('lesson', ['id' => 'lesson-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']);
		$this->seed('enrolment', ['id' => 'enrolment-1', 'learnerId' => 'learner-1', 'courseId' => 'course-1']);
		$this->signInAs('learner-1');

		$controller = $this->controller(['available' => true, 'reason' => null, 'availableAt' => null]);
		$response = $controller->status('lesson-1');

		self::assertSame(['available', 'reason', 'availableAt'], array_keys($response->getData()));

	}//end testResponseShapeIsMinimal()

	/**
	 * An assessment's status carries the attempt-gate facts (why the window is
	 * shut, whether an access code is needed) next to the release decision;
	 * a lesson's status does not (learniq#946).
	 *
	 * @return void
	 */
	public function testAssessmentStatusCarriesTheAttemptGateFacts(): void {
		$this->seed('exam', ['id' => 'exam-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']);
		$this->seed('enrolment', ['id' => 'enrolment-1', 'learnerId' => 'learner-1', 'courseId' => 'course-1']);
		$this->signInAs('learner-1');

		$controller = $this->controller(['available' => false, 'reason' => 'This assessment is not open yet.', 'availableAt' => null]);
		$data = $controller->assessmentStatus('exam-1')->getData();

		self::assertTrue($data['requiresAccessCode']);
		self::assertSame('window-not-open', $data['reasonCode']);

	}//end testAssessmentStatusCarriesTheAttemptGateFacts()

	/**
	 * An unknown lesson id returns 404 when ObjectService THROWS.
	 *
	 * ObjectService::find() raises DoesNotExistException for an unknown id
	 * rather than returning null, so before the catch in resolveObject() the
	 * exception escaped status() and became a 500 with a stack trace.
	 *
	 * @return void
	 */
	public function testUnknownLessonThrowingFromObjectServiceReturns404(): void {
		$this->signInAs('learner-1');

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willThrowException(
			new \OCP\AppFramework\Db\DoesNotExistException('no such object')
		);

		$controller = new LessonReleaseController(
			request: $this->createMock(IRequest::class),
			userSession: $this->userSession,
			objectService: $objectService,
			releaseEvaluator: $this->createMock(LessonReleaseEvaluator::class),
			dashboardRoleService: $this->dashboardRoleService,
			accessFacts: $this->createMock(AssessmentAccessFacts::class),
		);

		$response = $controller->status('nope');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testUnknownLessonThrowingFromObjectServiceReturns404()

	/**
	 * A controller whose collaborators record every call into $calls.
	 *
	 * @param \ArrayObject<int,mixed> $calls The record.
	 * @param array<string,mixed>     $item  The item find() answers, keyed by schema.
	 *
	 * @return LessonReleaseController
	 */
	private function recordingController(\ArrayObject $calls, array $item): LessonReleaseController {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			static function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) use ($calls, $item) {
				$calls[] = 'find ' . $schema . ' ' . $id;
				if (isset($item[$schema]) === false) {
					return null;
				}

				return OrEntityFactory::make($item[$schema], (string)$schema);
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($calls) {
				$calls[] = ['findAll', $config];
				return OrEntityFactory::makeMany(($this->db['enrolment'] ?? []), 'enrolment');
			}
		);
		$this->dashboardRoleService->method('resolveViews')->willReturnCallback(
			function () use ($calls): array {
				$calls[] = 'views';
				return ($this->db['views'][0] ?? ['student']);
			}
		);
		$evaluator = $this->createMock(LessonReleaseEvaluator::class);
		$evaluator->method('evaluate')->willReturnCallback(
			static function (array $item, string $itemSchema, string $learnerId, array $enrolment) use ($calls): array {
				unset($item['@self'], $enrolment['@self']);
				$calls[] = ['evaluate', $item, $itemSchema, $learnerId, $enrolment];
				return ['available' => false, 'reason' => 'Later.', 'availableAt' => '2026-12-01', 'extra' => 'dropped'];
			}
		);
		$accessFacts = $this->createMock(AssessmentAccessFacts::class);
		$accessFacts->method('assessmentAccess')->willReturnCallback(
			static function (string $assessmentId, array $item) use ($calls): array {
				$calls[] = 'facts ' . $assessmentId;
				return ['reasonCode' => 'x', 'available' => 'overridden'];
			}
		);

		return new LessonReleaseController(
			request: $this->createMock(IRequest::class),
			userSession: $this->userSession,
			objectService: $objectService,
			releaseEvaluator: $evaluator,
			dashboardRoleService: $this->dashboardRoleService,
			accessFacts: $accessFacts,
		);
	}//end recordingController()

	/**
	 * Each answer is exact, the staff check runs before the enrolment read,
	 * the enrolment is read only for an item with a course, and the evaluator
	 * and the assessment facts get exactly what the item and caller say.
	 *
	 * @return void
	 */
	public function testEachReleaseAnswerAndItsCallsAreExact(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('learner-1');
		$this->userSession->method('getUser')->willReturn($user);

		$calls = new \ArrayObject();
		self::assertSame(['error' => 'Lesson not found'], $this->recordingController($calls, [])->status('l1')->getData());
		self::assertSame(['error' => 'Assessment not found'], $this->recordingController($calls, [])->assessmentStatus('e1')->getData());
		self::assertSame(['find lesson l1', 'find exam e1'], $calls->getArrayCopy());

		$calls = new \ArrayObject();
		$denied = $this->recordingController($calls, ['lesson' => ['id' => 'l1']])->status('l1');
		self::assertSame([403, ['error' => 'Not enrolled in the course this item belongs to']], [$denied->getStatus(), $denied->getData()]);
		self::assertSame(['find lesson l1', 'views'], $calls->getArrayCopy());

		$this->db['enrolment'] = [['id' => 'en-1', 'learnerId' => 'learner-1']];
		$calls = new \ArrayObject();
		$lesson = $this->recordingController($calls, ['lesson' => ['id' => 'l1', 'courseId' => 'c1']])->status('l1');
		self::assertSame(['available' => false, 'reason' => 'Later.', 'availableAt' => '2026-12-01'], $lesson->getData());
		self::assertSame(
			[
				'find lesson l1',
				'views',
				['findAll', ['filters' => ['register' => 'learniq', 'schema' => 'enrolment', 'learnerId' => 'learner-1', 'courseId' => 'c1'], 'limit' => 1]],
				['evaluate', ['id' => 'l1', 'courseId' => 'c1'], 'lesson', 'learner-1', ['id' => 'en-1', 'learnerId' => 'learner-1']],
			],
			$calls->getArrayCopy()
		);

		$this->db['enrolment'] = [];
		$this->db['views'] = [['teacher']];
		$calls = new \ArrayObject();
		$exam = $this->recordingController($calls, ['exam' => ['id' => 'e1', 'courseId' => 'c1']])->assessmentStatus('e1');
		self::assertSame(['available' => 'overridden', 'reason' => 'Later.', 'availableAt' => '2026-12-01', 'reasonCode' => 'x'], $exam->getData());
		self::assertSame('facts e1', $calls[4]);
		self::assertSame([], $calls[3][4]);
	}//end testEachReleaseAnswerAndItsCallsAreExact()
}//end class
