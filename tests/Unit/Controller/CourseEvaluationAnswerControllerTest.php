<?php

/**
 * Tests for CourseEvaluationAnswerController.
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
 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#requirement-learner-answers-an-evaluation
 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#requirement-campaign-results-for-staff
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\CourseEvaluationAnswerController;
use OCA\Learniq\Service\CourseEvaluationAnswerService;
use OCA\Learniq\Service\DashboardRoleService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CourseEvaluationAnswerController.
 */
class CourseEvaluationAnswerControllerTest extends TestCase {

	/**
	 * Learner ids the service was asked about.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * The controller for a caller with the given views.
	 *
	 * @param string|null $uid   The caller, or null for no session.
	 * @param array       $views The caller's learniq views.
	 *
	 * @return CourseEvaluationAnswerController
	 */
	private function controller(?string $uid, array $views = ['student']): CourseEvaluationAnswerController {
		$this->asked = [];
		$service = $this->createMock(CourseEvaluationAnswerService::class);
		$service->method('openInvitations')->willReturnCallback(
			function (string $learnerId): array {
				$this->asked[] = $learnerId;
				return [['invitationId' => 'i-' . $learnerId]];
			}
		);
		$service->method('answer')->willReturnCallback(
			function (string $learnerId, string $invitationId, array $answers): array {
				$this->asked[] = $learnerId;
				if ($invitationId !== 'i-' . $learnerId) {
					return ['status' => 404, 'error' => 'Invitation not found'];
				}

				return ['status' => 201];
			}
		);
		$service->method('results')->willReturnCallback(
			static fn (string $campaignId): ?array => $campaignId === 'c-1' ? ['invitationCount' => 4, 'responseCount' => 3, 'meanOverallScore' => null, 'meanHidden' => true] : null
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

		return new CourseEvaluationAnswerController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			answers: $service,
			dashboardRoleService: $roles,
		);
	}//end controller()

	/**
	 * The list and the answer are always the session caller's own.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-a-learner-answers-an-invitation
	 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-an-uninvited-user-is-refused
	 */
	public function testTheCallerAnswersOnlyTheirOwn(): void {
		$controller = $this->controller(uid: 'jan');
		self::assertSame(['invitations' => [['invitationId' => 'i-jan']]], $controller->mine()->getData());

		$ok = $controller->answer(invitationId: 'i-jan', answers: ['q1' => 4]);
		self::assertSame(Http::STATUS_CREATED, $ok->getStatus());
		self::assertSame([], $ok->getData());

		$other = $controller->answer(invitationId: 'i-piet', answers: ['q1' => 4]);
		self::assertSame(Http::STATUS_NOT_FOUND, $other->getStatus());
		self::assertSame(['error' => 'Invitation not found'], $other->getData());
		self::assertSame(['jan', 'jan', 'jan'], $this->asked);
	}//end testTheCallerAnswersOnlyTheirOwn()

	/**
	 * Staff read the figures; a learner gets 403, an unknown campaign 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-small-groups-are-protected
	 */
	public function testOnlyStaffReadResults(): void {
		$staff = $this->controller(uid: 'tom', views: ['teacher', 'student']);
		self::assertSame(3, $staff->results(campaignId: 'c-1')->getData()['responseCount']);
		self::assertSame(Http::STATUS_NOT_FOUND, $staff->results(campaignId: 'c-gone')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(uid: 'jan')->results(campaignId: 'c-1')->getStatus());
	}//end testOnlyStaffReadResults()

	/**
	 * Without a session every endpoint answers 401.
	 *
	 * @return void
	 */
	public function testNoSession(): void {
		$controller = $this->controller(uid: null);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller->mine()->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller->answer(invitationId: 'i-jan')->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller->results(campaignId: 'c-1')->getStatus());
		self::assertSame([], $this->asked);
	}//end testNoSession()
}//end class
