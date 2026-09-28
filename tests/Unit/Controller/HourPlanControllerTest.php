<?php

/**
 * Tests for HourPlanController.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/timetabling-multi-year-hour-plan/specs/school-structure/spec.md#scenario-a-learner-cannot-read-the-activity-list
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\HourPlanController;
use OCA\Learniq\Service\HourPlanActivityService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Only staff groups read the activities; the year must be readable.
 */
class HourPlanControllerTest extends TestCase {

	/**
	 * Build the controller for a caller.
	 *
	 * @param string|null       $uid    The caller, or null for none.
	 * @param array<int,string> $groups The caller's groups.
	 *
	 * @return HourPlanController
	 */
	private function controller(?string $uid, array $groups = []): HourPlanController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $u, string $g): bool => in_array($g, $groups, true));
		$service = $this->createMock(HourPlanActivityService::class);
		$service->method('intakeYearOf')->willReturnCallback(static fn (string $y): ?string => preg_match('/^\d{4}-\d{4}$/', $y) === 1 ? $y : null);
		$service->method('forYear')->willReturn(['academicYear' => '2026-2027', 'activities' => [['cohortId' => 'c-mv2a']], 'cohortsWithoutPlan' => []]);

		return new HourPlanController($this->createMock(IRequest::class), $session, $groupManager, $service);
	}//end controller()

	/**
	 * A learner is refused.
	 *
	 * @return void
	 */
	public function testLearnerIsRefused(): void {
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(uid: 'pupil', groups: ['learners'])->activities(academicYear: '2026-2027')->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(uid: null)->activities(academicYear: '2026-2027')->getStatus());
	}//end testLearnerIsRefused()

	/**
	 * A teacher gets the activities; an unreadable year is a 400.
	 *
	 * @return void
	 */
	public function testStaffReadsTheActivities(): void {
		$response = $this->controller(uid: 'coord', groups: ['team-leads'])->activities(academicYear: '2026-2027');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('c-mv2a', $response->getData()['activities'][0]['cohortId']);

		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller(uid: 'coord', groups: ['instructors'])->activities(academicYear: '2026')->getStatus());
	}//end testStaffReadsTheActivities()
}//end class
