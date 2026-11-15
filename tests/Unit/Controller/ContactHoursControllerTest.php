<?php

/**
 * ContactHoursController: staff only, a valid window, and the report.
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
 * @spec openspec/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\ContactHoursController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\ContactHoursService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Who may read the report.
 */
class ContactHoursControllerTest extends TestCase {

	/**
	 * The controller with its doubles.
	 *
	 * @param ContactHoursService $service  The report double.
	 * @param bool                $allowed  Whether report.contact-hours is granted.
	 * @param bool                $loggedIn Whether a user is signed in.
	 *
	 * @return ContactHoursController
	 */
	private function controller(ContactHoursService $service, bool $allowed=true, bool $loggedIn=true): ContactHoursController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($loggedIn ? $this->createMock(IUser::class) : null);
		$auth = $this->createMock(ActionAuthService::class);
		$call = $auth->method('requireAction')->with($this->anything(), ContactHoursController::ACTION);
		if ($allowed === false) {
			$call->willThrowException(new OCSForbiddenException('Not allowed.'));
		}

		return new ContactHoursController($this->createMock(IRequest::class), $session, $auth, $service);
	}//end controller()

	/**
	 * A coordinator gets the report for a window and a group.
	 *
	 * @return void
	 */
	public function testACoordinatorReadsTheReport(): void {
		$service = $this->createMock(ContactHoursService::class);
		$service->expects($this->once())->method('forPeriod')->with('2026-09-01', '2027-01-29', 'mv2a')->willReturn(['cohorts' => []]);

		self::assertSame(['cohorts' => []], $this->controller($service)->index('2026-09-01', '2027-01-29', 'mv2a')->getData());
	}//end testACoordinatorReadsTheReport()

	/**
	 * A learner, who is in no staff group, is refused; so is an anonymous caller.
	 *
	 * @return void
	 */
	public function testLearnerIsRefused(): void {
		$service = $this->createMock(ContactHoursService::class);
		$service->expects($this->never())->method('forPeriod');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller($service, allowed: false)->index('2026-09-01', '2027-01-29')->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller($service, loggedIn: false)->index('2026-09-01', '2027-01-29')->getStatus());
	}//end testLearnerIsRefused()

	/**
	 * A missing or reversed window is 400; an unreadable timetable is 503.
	 *
	 * @return void
	 */
	public function testWindowAndSourceErrors(): void {
		$service = $this->createMock(ContactHoursService::class);
		$service->method('forPeriod')->willThrowException(new \RuntimeException('planninq silent'));

		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller($service)->index(null, '2027-01-29')->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller($service)->index('2027-02-01', '2027-01-29')->getStatus());
		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $this->controller($service)->index('2026-09-01', '2027-01-29')->getStatus());
	}//end testWindowAndSourceErrors()
}//end class
