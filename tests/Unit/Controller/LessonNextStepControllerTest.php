<?php

/**
 * The next step and course preview endpoints.
 *
 * Runs the real NextStepResolver and LessonReleaseEvaluator over a store that
 * filters the way OpenRegister does.
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
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#requirement-preview-as-learner
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\LessonNextStepController;
use OCA\Learniq\Service\DashboardRoleService;
use OCA\Learniq\Service\LessonReleaseEvaluator;
use OCA\Learniq\Service\NextStepResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LessonNextStepController.
 */
class LessonNextStepControllerTest extends TestCase {

	/**
	 * The store behind the ObjectService double.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * The controller for a caller with the given Learniq views.
	 *
	 * @param string|null   $uid   The caller, or null without a session.
	 * @param array<string> $views The caller's views.
	 *
	 * @return LessonNextStepController
	 */
	private function controller(?string $uid, array $views = ['student']): LessonNextStepController {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['course'] = [['id' => 'c-1', 'name' => 'Safe lifting']];
		$this->store->rows['lesson'] = [
			['id' => 'l-module-2', 'courseId' => 'c-1', 'name' => 'Module 2', 'order' => 3],
			[
				'id' => 'l-quiz',
				'courseId' => 'c-1',
				'name' => 'Quiz',
				'order' => 1,
				'tenant_id' => 't1',
				'nextStepRules' => [['when' => ['kind' => 'score-below', 'assessmentId' => 'a-quiz', 'belowScore' => 60], 'goToLessonId' => 'l-refresher']],
				'defaultNextLessonId' => 'l-module-2',
			],
			['id' => 'l-refresher', 'courseId' => 'c-1', 'name' => 'Refresher', 'order' => 2],
			['id' => 'l-other', 'courseId' => 'c-2', 'name' => 'Elsewhere', 'order' => 1],
		];
		$this->store->rows['enrolment'] = [['id' => 'e-1', 'learnerId' => 'jan', 'courseId' => 'c-1']];
		$this->store->rows['assessment-result'] = [
			['id' => 'r-1', 'assessmentId' => 'a-quiz', 'learnerId' => 'jan', 'lifecycle' => 'graded', 'tenant_id' => 't1', 'responses' => [['autoScore' => 45]]],
		];

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ?ObjectEntity {
				if ($id === 'l-null') {
					return null;
				}

				foreach (($this->store->rows[(string)$schema] ?? []) as $row) {
					if ($row['id'] === $id) {
						return OrEntityFactory::make($row, (string)$schema);
					}
				}

				throw new DoesNotExistException('gone');
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$roles = $this->createMock(DashboardRoleService::class);
		$roles->method('resolveViews')->willReturn($views);

		return new LessonNextStepController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			objectService: $objects,
			resolver: new NextStepResolver(evaluator: new LessonReleaseEvaluator(objectService: $objects)),
			dashboardRoleService: $roles,
		);
	}//end controller()

	/**
	 * An enrolled learner who scored 45 is offered the refresher, by name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#scenario-a-learner-who-fails-is-sent-to-a-refresher
	 */
	public function testAnEnrolledLearnerGetsTheirNextStep(): void {
		$response = $this->controller(uid: 'jan')->nextStep(lessonId: 'l-quiz');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['nextLessonId' => 'l-refresher', 'nextLessonName' => 'Refresher', 'rule' => 0], $response->getData());
	}//end testAnEnrolledLearnerGetsTheirNextStep()

	/**
	 * A learner cannot preview (403, even when enrolled), and cannot ask for
	 * the next step of a course they are not in.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#scenario-a-learner-cannot-preview
	 */
	public function testALearnerCannotPreview(): void {
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(uid: 'jan')->nextStep(lessonId: 'l-quiz', preview: '1', score: '80')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(uid: 'jan')->coursePreview(courseId: 'c-1')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(uid: 'piet')->nextStep(lessonId: 'l-quiz')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(uid: 'jan')->nextStep(lessonId: 'l-other')->getStatus());
	}//end testALearnerCannotPreview()

	/**
	 * An author previews with a simulated score: 45 goes to the refresher, 80
	 * to Module 2, and no result of the author is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#scenario-a-teacher-walks-the-course-as-a-learner
	 */
	public function testAnAuthorPreviewsWithASimulatedScore(): void {
		$controller = $this->controller(uid: 'tom', views: ['teacher']);

		self::assertSame('l-refresher', $controller->nextStep(lessonId: 'l-quiz', preview: '1', score: '45')->getData()['nextLessonId']);
		self::assertSame(['nextLessonId' => 'l-module-2', 'nextLessonName' => 'Module 2', 'rule' => null], $controller->nextStep(lessonId: 'l-quiz', preview: '1', score: '80')->getData());
		self::assertSame('l-module-2', $controller->nextStep(lessonId: 'l-quiz', preview: '1', score: 'x')->getData()['nextLessonId']);
		self::assertSame([], array_filter($this->store->reads, static fn (array $read): bool => ($read['config']['filters']['schema'] ?? '') === 'assessment-result'));
	}//end testAnAuthorPreviewsWithASimulatedScore()

	/**
	 * The course preview lists the course's lessons in order, and only them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#scenario-a-teacher-walks-the-course-as-a-learner
	 */
	public function testTheCoursePreviewListsTheLessonsInOrder(): void {
		$response = $this->controller(uid: 'tom', views: ['admin'])->coursePreview(courseId: 'c-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('Safe lifting', $response->getData()['name']);
		self::assertSame(['Quiz', 'Refresher', 'Module 2'], array_column($response->getData()['lessons'], 'name'));
	}//end testTheCoursePreviewListsTheLessonsInOrder()

	/**
	 * No session is 401, an unknown lesson or course 404, and a lesson with
	 * no next step answers null.
	 *
	 * @return void
	 */
	public function testMissingCallerAndObjects(): void {
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(uid: null)->nextStep(lessonId: 'l-quiz')->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(uid: null)->coursePreview(courseId: 'c-1')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller(uid: 'jan')->nextStep(lessonId: 'l-gone')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller(uid: 'jan')->nextStep(lessonId: 'l-null')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller(uid: 'tom', views: ['teacher'])->coursePreview(courseId: 'c-gone')->getStatus());

		$end = $this->controller(uid: 'jan')->nextStep(lessonId: 'l-module-2');
		self::assertSame(['nextLessonId' => null, 'nextLessonName' => null, 'rule' => null], $end->getData());
	}//end testMissingCallerAndObjects()
}//end class
