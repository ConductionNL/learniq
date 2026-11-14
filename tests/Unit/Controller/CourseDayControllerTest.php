<?php

/**
 * Learniq CourseDayController unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\CourseDayController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CallerActions;
use OCA\Learniq\Service\CourseDay\CourseDayException;
use OCA\Learniq\Service\CourseDay\CourseDayStatusService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The planner's course day endpoints.
 *
 * @covers \OCA\Learniq\Controller\CourseDayController
 * @uses   \OCA\Learniq\Controller\CallerActionCheck
 * @uses   \OCA\Learniq\Service\CallerActions
 * @uses   \OCA\Learniq\Service\CourseDay\CourseDayException
 */
class CourseDayControllerTest extends TestCase {

	private CourseDayStatusService&MockObject $days;

	private function controller(bool $allowed=true, bool $loggedIn=true): CourseDayController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('sophie');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($loggedIn ? $user : null);

		$auth = $this->createMock(ActionAuthService::class);
		$call = $auth->method('requireAction')->with($this->anything(), CourseDayController::ACTION);
		if ($allowed === false) {
			$call->willThrowException(new OCSForbiddenException('Not allowed.'));
		}

		$this->days = $this->createMock(CourseDayStatusService::class);

		return new CourseDayController(
			request: $this->createMock(IRequest::class),
			caller: new CallerActions(userSession: $session, actionAuth: $auth),
			days: $this->days,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end controller()

	public function testAPlannerMovesADayByHand(): void {
		$controller = $this->controller();
		$this->days->expects($this->once())->method('move')->with('c-22', 'goes-ahead', 'sophie')->willReturn('goes-ahead');

		$response = $controller->move(id: 'c-22', status: 'goes-ahead');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['dayStatus' => 'goes-ahead'], $response->getData());
	}//end testAPlannerMovesADayByHand()

	public function testAnEmptyDecisionUndoesIt(): void {
		$controller = $this->controller();
		$this->days->expects($this->once())->method('decide')->with('c-22', null, 'sophie')->willReturn('open');

		self::assertSame(['dayStatus' => 'open'], $controller->decide(id: 'c-22')->getData());
	}//end testAnEmptyDecisionUndoesIt()

	public function testANoGoDecisionIsPassedOn(): void {
		$controller = $this->controller();
		$this->days->expects($this->once())->method('decide')->with('c-22', 'no-go', 'sophie')->willReturn('cancelled');

		self::assertSame(['dayStatus' => 'cancelled'], $controller->decide(id: 'c-22', decision: 'no-go')->getData());
	}//end testANoGoDecisionIsPassedOn()

	public function testAlternativesAndMovingParticipants(): void {
		$controller = $this->controller();
		$this->days->method('alternatives')->with('c-22')->willReturn([['id' => 'c-26', 'name' => '26 november', 'date' => '2026-11-26', 'free' => 9]]);
		$this->days->expects($this->once())->method('moveParticipants')->with('c-22', 'c-26', 'sophie')
			->willReturn(['bookings' => 1, 'enrolments' => 2, 'targetId' => 'c-26']);

		self::assertSame('c-26', $controller->alternatives(id: 'c-22')->getData()['days'][0]['id']);
		self::assertSame(['bookings' => 1, 'enrolments' => 2, 'targetId' => 'c-26'], $controller->moveParticipants(id: 'c-22', targetId: 'c-26')->getData());
	}//end testAlternativesAndMovingParticipants()

	public function testAUserWithoutTheActionIsRefusedBeforeAnything(): void {
		$controller = $this->controller(allowed: false);
		foreach (['move', 'decide', 'alternatives', 'moveParticipants'] as $method) {
			$this->days->expects($this->never())->method($method);
		}

		self::assertSame(Http::STATUS_FORBIDDEN, $controller->move(id: 'c-22', status: 'done')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $controller->decide(id: 'c-22', decision: 'go')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $controller->alternatives(id: 'c-22')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $controller->moveParticipants(id: 'c-22', targetId: 'c-26')->getStatus());
	}//end testAUserWithoutTheActionIsRefusedBeforeAnything()

	public function testNoSessionIsUnauthorised(): void {
		$controller = $this->controller(loggedIn: false);
		$this->days->expects($this->never())->method('move');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller->move(id: 'c-22', status: 'done')->getStatus());
	}//end testNoSessionIsUnauthorised()

	public function testARefusalKeepsItsStatusAndAFailureIsUnavailable(): void {
		$controller = $this->controller();
		$this->days->method('moveParticipants')->willThrowException(new CourseDayException(message: 'Choose another coming day of the same course with places.', status: 409));
		$this->days->method('decide')->willThrowException(new RuntimeException('database gone'));

		$refused = $controller->moveParticipants(id: 'c-22', targetId: 'f-01');
		self::assertSame(Http::STATUS_CONFLICT, $refused->getStatus());
		self::assertSame('Choose another coming day of the same course with places.', $refused->getData()['error']);

		$failed = $controller->decide(id: 'c-22', decision: 'go');
		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $failed->getStatus());
		self::assertStringNotContainsString('database', $failed->getData()['error']);
	}//end testARefusalKeepsItsStatusAndAFailureIsUnavailable()
}//end class
