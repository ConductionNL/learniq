<?php

/**
 * The pupil page endpoint passes the caller to the service and answers its verdict.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupils-page-opens-with-a-header-one-next-step-and-the-people-around-the-pupil
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PupilPageController;
use OCA\Learniq\Service\Attendance\RollCallException;
use OCA\Learniq\Service\Pupil\PupilPageService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Controller\PupilPageController
 * @uses   \OCA\Learniq\Service\Attendance\RollCallException
 */
class PupilPageControllerTest extends TestCase {

	/**
	 * The controller with a signed-in user, or none.
	 *
	 * @param PupilPageService $service  The service double.
	 * @param bool             $signedIn Whether a user is signed in.
	 *
	 * @return PupilPageController
	 */
	private function controller(PupilPageService $service, bool $signedIn=true): PupilPageController {
		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('daan');
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PupilPageController(request: $this->createMock(IRequest::class), userSession: $session, pupilPage: $service, logger: new NullLogger(), l10n: $l10n);
	}//end controller()

	/**
	 * The page data comes back as is, for the session user.
	 *
	 * @return void
	 */
	public function testShowAnswersThePage(): void {
		$service = $this->createMock(PupilPageService::class);
		$service->expects($this->once())->method('open')->with($this->isInstanceOf(IUser::class), 'vera', 'groep-7')
			->willReturn(['learnerId' => 'vera']);

		$response = $this->controller(service: $service)->show(learnerId: 'vera', cohortId: 'groep-7');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['learnerId' => 'vera'], $response->getData());
	}//end testShowAnswersThePage()

	/**
	 * Without a session the service is not asked.
	 *
	 * @return void
	 */
	public function testShowWithoutASessionIs401(): void {
		$service = $this->createMock(PupilPageService::class);
		$service->expects($this->never())->method('open');

		$response = $this->controller(service: $service, signedIn: false)->show(learnerId: 'vera');

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['error' => 'Sign in to open this page.'], $response->getData());
	}//end testShowWithoutASessionIs401()

	/**
	 * A refusal answers with its own status and message.
	 *
	 * @return void
	 */
	public function testARefusalKeepsItsStatus(): void {
		$service = $this->createMock(PupilPageService::class);
		$service->method('open')->willThrowException(new RollCallException('You cannot open the page of this pupil.', 403));

		$response = $this->controller(service: $service)->show(learnerId: 'vera');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'You cannot open the page of this pupil.'], $response->getData());
	}//end testARefusalKeepsItsStatus()

	/**
	 * Any other failure is 503 with a generic message.
	 *
	 * @return void
	 */
	public function testAFailureIs503(): void {
		$service = $this->createMock(PupilPageService::class);
		$service->method('open')->willThrowException(new RuntimeException('store down'));

		$response = $this->controller(service: $service)->show(learnerId: 'vera');

		$this->assertSame(503, $response->getStatus());
		$this->assertSame(['error' => 'The pupil page could not be read. Try again.'], $response->getData());
	}//end testAFailureIs503()
}//end class
