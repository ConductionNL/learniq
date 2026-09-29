<?php

/**
 * SessionChangeBatchController: session, action matrix, answers.
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
 * @spec openspec/changes/timetabling-bulk-change-weeks/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Learniq\Controller\SessionChangeBatchController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\SessionChangeBatchService;
use OCA\Learniq\Timetabling\SessionSeries;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Who may open the door, and what comes back.
 */
class SessionChangeBatchControllerTest extends TestCase {

	/**
	 * The controller with its doubles.
	 *
	 * @param SessionChangeBatchService $service  The service double.
	 * @param bool                      $allowed  Whether the action matrix allows.
	 * @param bool                      $loggedIn Whether a user is signed in.
	 * @param array<string, mixed>      $params   Request parameters.
	 * @param SessionSeries|null        $series   The series double.
	 *
	 * @return SessionChangeBatchController
	 */
	private function controller(SessionChangeBatchService $service, bool $allowed=true, bool $loggedIn=true, array $params=[], ?SessionSeries $series=null): SessionChangeBatchController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default=null) => ($params[$key] ?? $default));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('coordinator-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($loggedIn ? $user : null);

		$auth = $this->createMock(ActionAuthService::class);
		$call = $auth->method('requireAction')->with($this->anything(), SessionChangeBatchController::ACTION);
		if ($allowed === false) {
			$call->willThrowException(new OCSForbiddenException('Not allowed.'));
		}

		return new SessionChangeBatchController($request, $session, $auth, $service, $series ?? $this->createMock(SessionSeries::class));
	}//end controller()

	/**
	 * A coordinator applies a batch and gets it back with its results.
	 *
	 * @return void
	 */
	public function testACoordinatorAppliesABatch(): void {
		$service = $this->createMock(SessionChangeBatchService::class);
		$service->expects($this->once())->method('apply')
			->with(
				$this->callback(static fn (array $input): bool => $input['kind'] === 'cancel' && $input['sessionIds'] === ['s1', 's2']),
				'coordinator-1'
			)
			->willReturn(['id' => 'b1', 'appliedCount' => 2]);

		$response = $this->controller($service, params: ['kind' => 'cancel', 'sessionIds' => ['s1', 's2'], 'changeReasonKind' => 'other'])->create();

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame(2, $response->getData()['appliedCount']);
	}//end testACoordinatorAppliesABatch()

	/**
	 * A user without the action gets 403 and nothing is applied.
	 *
	 * @return void
	 */
	public function testAUserWithoutTheActionIsRefused(): void {
		$service = $this->createMock(SessionChangeBatchService::class);
		$service->expects($this->never())->method('apply');
		$series = $this->createMock(SessionSeries::class);
		$series->expects($this->never())->method('series');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller($service, allowed: false)->create()->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller($service, allowed: false, series: $series)->series('s1')->getStatus());
	}//end testAUserWithoutTheActionIsRefused()

	/**
	 * No session is 401; incomplete input is 400.
	 *
	 * @return void
	 */
	public function testNoSessionAndBadInput(): void {
		$service = $this->createMock(SessionChangeBatchService::class);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller($service, loggedIn: false)->create()->getStatus());

		$service->method('apply')->willThrowException(new InvalidArgumentException('A change needs a reason.'));
		$response = $this->controller($service)->create();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('A change needs a reason.', $response->getData()['error']);
	}//end testNoSessionAndBadInput()

	/**
	 * The series answers the lessons, or 404 for a lesson the caller cannot read.
	 *
	 * @return void
	 */
	public function testSeries(): void {
		$service = $this->createMock(SessionChangeBatchService::class);
		$series = $this->createMock(SessionSeries::class);
		$series->method('series')->willReturnCallback(static fn (string $id) => ($id === 's1') ? [['id' => 's1']] : null);

		self::assertSame(['sessions' => [['id' => 's1']]], $this->controller($service, series: $series)->series('s1', '2026-04-01')->getData());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller($service, series: $series)->series('nope')->getStatus());
	}//end testSeries()
}//end class
