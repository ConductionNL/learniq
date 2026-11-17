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
 * @spec openspec/specs/school-structure/spec.md#scenario-a-learner-cannot-read-the-activity-list
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use DateTime;
use OCA\Learniq\Controller\HourPlanController;
use OCA\Learniq\Service\Calendar\SchoolYear;
use OCA\Learniq\Service\HourPlanActivityService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
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
	 * @param string            $today  Today, `Y-m-d`.
	 * @param string            $start  The school's `school_year_start`.
	 * @param string|null       $asked  The year forYear must be asked for, or null for any.
	 *
	 * @return HourPlanController
	 */
	private function controller(?string $uid, array $groups = [], string $today = '2026-10-21', string $start = '08-01', ?string $asked = null): HourPlanController {
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
		$forYear = $service->method('forYear');
		if ($asked !== null) {
			$forYear = $service->expects(self::once())->method('forYear')->with($asked);
		}

		$forYear->willReturn(['academicYear' => '2026-2027', 'activities' => [['cohortId' => 'c-mv2a']], 'cohortsWithoutPlan' => []]);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($key === SchoolYear::KEY ? $start : $default)
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime($today));

		return new HourPlanController($this->createMock(IRequest::class), $session, $groupManager, $service, new SchoolYear($config), $time);
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

	/**
	 * Without a year, the next school year is read, counted from the school's own start.
	 *
	 * @return void
	 */
	public function testNoYearReadsTheNextSchoolYearFromTheSchoolsStart(): void {
		$response = $this->controller(uid: 'coord', groups: ['team-leads'], today: '2026-08-20', start: '09-01', asked: '2026-2027')->activities();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('2025-2026', $response->getData()['currentAcademicYear']);

		$response = $this->controller(uid: 'coord', groups: ['team-leads'], today: '2026-08-20', asked: '2027-2028')->activities(academicYear: '');
		self::assertSame('2026-2027', $response->getData()['currentAcademicYear']);
	}//end testNoYearReadsTheNextSchoolYearFromTheSchoolsStart()

	/**
	 * A year asked for is read as asked, whatever today is.
	 *
	 * @return void
	 */
	public function testAnAskedYearIsReadAsAsked(): void {
		$response = $this->controller(uid: 'coord', groups: ['instructors'], start: '09-01', asked: '2024-2025')->activities(academicYear: '2024-2025');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('2026-2027', $response->getData()['currentAcademicYear']);
	}//end testAnAskedYearIsReadAsAsked()
}//end class
