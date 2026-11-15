<?php

/**
 * Learniq RollCallController unit tests.
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
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-who-may-open-which-groups-register
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\RollCallController;
use OCA\Learniq\Service\Attendance\RollCallException;
use OCA\Learniq\Service\Attendance\RollCallService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The roll-call endpoints pass the caller to the service and answer with its verdict.
 */
class RollCallControllerTest extends TestCase {

	/**
	 * The controller with a signed-in user, or none.
	 *
	 * @param RollCallService $service  The service double.
	 * @param bool            $signedIn Whether a user is signed in.
	 *
	 * @return RollCallController
	 */
	private function controller(RollCallService $service, bool $signedIn=true): RollCallController {
		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('juf-7');
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new RollCallController(request: $this->createMock(IRequest::class), userSession: $session, rollCall: $service, logger: new NullLogger(), l10n: $l10n);
	}//end controller()

	/**
	 * Empty query values mean "the default", and the register is returned as is.
	 *
	 * @return void
	 */
	public function testShowPassesTheCallerAndDefaults(): void {
		$service = $this->createMock(RollCallService::class);
		$service->expects(self::once())->method('open')
			->with(self::isInstanceOf(IUser::class), null, '2026-10-02', null)
			->willReturn(['date' => '2026-10-02']);

		$response = $this->controller(service: $service)->show(cohortId: '', date: '2026-10-02', sessionId: '');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['date' => '2026-10-02'], $response->getData());
	}//end testShowPassesTheCallerAndDefaults()

	/**
	 * A refusal keeps its status and message; anything else is a 503 without details.
	 *
	 * @return void
	 */
	public function testRefusalsKeepTheirStatus(): void {
		$service = $this->createMock(RollCallService::class);
		$service->method('save')->willThrowException(new RollCallException('You cannot take the register of this group.', 403));
		$response = $this->controller(service: $service)->save(cohortId: 'c', date: '2026-10-02', sessionId: null, marks: [['learnerId' => 'x', 'status' => 'present']]);
		self::assertSame(403, $response->getStatus());
		self::assertSame(['error' => 'You cannot take the register of this group.'], $response->getData());

		$broken = $this->createMock(RollCallService::class);
		$broken->method('open')->willThrowException(new RuntimeException('SQLSTATE secret'));
		$response = $this->controller(service: $broken)->show();
		self::assertSame(503, $response->getStatus());
		self::assertStringNotContainsString('SQLSTATE', (string)json_encode($response->getData()));
	}//end testRefusalsKeepTheirStatus()

	/**
	 * Without a signed-in user nothing is read.
	 *
	 * @return void
	 */
	public function testASignedOutCallerIsRefused(): void {
		$service = $this->createMock(RollCallService::class);
		$service->expects(self::never())->method('open');

		self::assertSame(401, $this->controller(service: $service, signedIn: false)->show()->getStatus());
	}//end testASignedOutCallerIsRefused()
}//end class
