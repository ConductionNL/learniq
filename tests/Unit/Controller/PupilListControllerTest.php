<?php

/**
 * The pupil list endpoint: session, refusals and failures.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupil-list-shows-what-the-school-kind-acts-on-and-its-saved-views-count
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PupilListController;
use OCA\Learniq\Service\Attendance\RollCallException;
use OCA\Learniq\Service\Pupil\PupilListService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Controller\PupilListController
 * @uses   \OCA\Learniq\Service\Attendance\RollCallException
 */
class PupilListControllerTest extends TestCase {

	/**
	 * The controller with a signed-in user, or none.
	 *
	 * @param PupilListService $service  The service double.
	 * @param bool             $signedIn Whether a user is signed in.
	 *
	 * @return PupilListController
	 */
	private function controller(PupilListService $service, bool $signedIn=true): PupilListController {
		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PupilListController(request: $this->createMock(IRequest::class), userSession: $session, pupilList: $service, logger: new NullLogger(), l10n: $l10n);
	}//end controller()

	/**
	 * The list comes back as is, for the session user, with the view and the order.
	 *
	 * @return void
	 */
	public function testIndexAnswersTheList(): void {
		$service = $this->createMock(PupilListService::class);
		$service->expects($this->once())->method('forCohort')->with($this->isInstanceOf(IUser::class), 'groep-7', 'absent-today', 'signal-first')
			->willReturn(['results' => [], 'total' => 0]);

		$response = $this->controller(service: $service)->index(cohortId: 'groep-7', view: 'absent-today', sort: 'signal-first');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['results' => [], 'total' => 0], $response->getData());
	}//end testIndexAnswersTheList()

	/**
	 * No view and no order reach the service as empty strings.
	 *
	 * @return void
	 */
	public function testIndexWithoutAViewAsksForAll(): void {
		$service = $this->createMock(PupilListService::class);
		$service->expects($this->once())->method('forCohort')->with($this->isInstanceOf(IUser::class), null, '', '')->willReturn([]);

		$this->assertSame(200, $this->controller(service: $service)->index()->getStatus());
	}//end testIndexWithoutAViewAsksForAll()

	/**
	 * Without a session the service is not asked.
	 *
	 * @return void
	 */
	public function testIndexWithoutASessionIs401(): void {
		$service = $this->createMock(PupilListService::class);
		$service->expects($this->never())->method('forCohort');

		$response = $this->controller(service: $service, signedIn: false)->index();

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['error' => 'Sign in to open this page.'], $response->getData());
	}//end testIndexWithoutASessionIs401()

	/**
	 * A refusal answers with its own status, and its message where the list reads it.
	 *
	 * @return void
	 */
	public function testARefusalKeepsItsStatus(): void {
		$service = $this->createMock(PupilListService::class);
		$service->method('forCohort')->willThrowException(new RollCallException('You cannot take the register of this group.', 403));

		$response = $this->controller(service: $service)->index(cohortId: 'groep-8');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'You cannot take the register of this group.', 'message' => 'You cannot take the register of this group.'], $response->getData());
	}//end testARefusalKeepsItsStatus()

	/**
	 * Any other failure is 503 with a generic message.
	 *
	 * @return void
	 */
	public function testAFailureIs503(): void {
		$service = $this->createMock(PupilListService::class);
		$service->method('forCohort')->willThrowException(new RuntimeException('store down'));

		$response = $this->controller(service: $service)->index();

		$this->assertSame(503, $response->getStatus());
		$this->assertSame(['error' => 'The pupil list could not be read. Try again.'], $response->getData());
	}//end testAFailureIs503()
}//end class
