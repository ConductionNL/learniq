<?php

/**
 * SchoolActivityController: staff-only behind activity.manage, and each
 * refusal answered with its status.
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
 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\SchoolActivityController;
use OCA\Learniq\Kernel\Contracts\SchoolActivityStaffInterface;
use OCA\Learniq\Service\ActionAuthService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Who may call, and how the staff side's answers come back.
 */
class SchoolActivityControllerTest extends TestCase {

	/**
	 * Every mark call's arguments.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $marks = [];

	/**
	 * The controller with its doubles.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 * @param bool $allowed  Whether activity.manage is granted.
	 *
	 * @return SchoolActivityController
	 */
	private function controller(bool $signedIn = true, bool $allowed = true): SchoolActivityController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('juf-a');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn ? $user : null);

		$auth = $this->createMock(ActionAuthService::class);
		$call = $auth->method('requireAction')->with($user, SchoolActivityController::ACTION);
		if ($allowed === false) {
			$call->willThrowException(new OCSForbiddenException('Not allowed.'));
		}

		$staff = $this->createMock(SchoolActivityStaffInterface::class);
		$staff->method('board')->willReturn([['id' => 'act-1', 'places' => 16]]);
		$staff->method('open')->willReturnCallback(
			static fn (string $id): array => match ($id) {
				'act-1' => ['id' => 'act-1', 'status' => 'open'],
				'act-2' => ['error' => SchoolActivityStaffInterface::REASON_NO_PLACES],
				default => ['error' => SchoolActivityStaffInterface::REASON_NOT_FOUND],
			}
		);
		$staff->method('roster')->willReturn(['error' => SchoolActivityStaffInterface::REASON_UNAVAILABLE]);
		$staff->method('mark')->willReturnCallback(
			function (string $activityId, string $sessionId, string $childRef, string $status, string $markedByRef): array {
				$this->marks[] = [$activityId, $sessionId, $childRef, $status, $markedByRef];
				if ($childRef === 'kind-3') {
					return ['error' => SchoolActivityStaffInterface::REASON_NOT_CONFIRMED];
				}

				return ['id' => 'att-1', 'status' => $status];
			}
		);

		return new SchoolActivityController($this->createMock(IRequest::class), $session, $auth, $staff);
	}//end controller()

	/**
	 * Staff read the board.
	 *
	 * @return void
	 */
	public function testStaffReadTheBoard(): void {
		$response = $this->controller()->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['results' => [['id' => 'act-1', 'places' => 16]]], $response->getData());
	}//end testStaffReadTheBoard()

	/**
	 * Nobody signed in gets 401; without activity.manage, 403 and no write.
	 *
	 * @return void
	 */
	public function testOnlyStaffMayCall(): void {
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(signedIn: false)->index()->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(allowed: false)->open('act-1')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(allowed: false)->attendance('act-1', 'week-3', 'kind-1', 'present')->getStatus());
		self::assertSame([], $this->marks);
	}//end testOnlyStaffMayCall()

	/**
	 * Opening answers the activity, 422 `no_places`, or 404.
	 *
	 * @return void
	 */
	public function testOpenAnswers(): void {
		$controller = $this->controller();

		self::assertSame(['id' => 'act-1', 'status' => 'open'], $controller->open('act-1')->getData());
		$refused = $controller->open('act-2');
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $refused->getStatus());
		self::assertSame(['error' => 'no_places'], $refused->getData());
		self::assertSame(Http::STATUS_NOT_FOUND, $controller->open('nope')->getStatus());
		self::assertSame(Http::STATUS_BAD_GATEWAY, $controller->roster('act-1')->getStatus());
	}//end testOpenAnswers()

	/**
	 * The marker is the session user; a waitlisted child is 422 `not_confirmed`.
	 *
	 * @return void
	 */
	public function testAttendanceIsMarkedAsTheSessionUser(): void {
		$controller = $this->controller();

		self::assertSame(['id' => 'att-1', 'status' => 'present'], $controller->attendance('act-1', 'week-3', 'kind-1', 'present')->getData());
		self::assertSame(['act-1', 'week-3', 'kind-1', 'present', 'juf-a'], $this->marks[0]);
		$refused = $controller->attendance('act-1', 'week-3', 'kind-3', 'present');
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $refused->getStatus());
		self::assertSame(['error' => 'not_confirmed'], $refused->getData());
	}//end testAttendanceIsMarkedAsTheSessionUser()
}//end class
