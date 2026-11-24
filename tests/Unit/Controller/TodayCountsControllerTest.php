<?php

/**
 * The role tiles endpoint on Today: the answer, the view, and the refusals.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-each-role-tile-counts-what-that-role-acts-on-and-opens-the-list-it-counts
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\TodayCountsController;
use OCA\Learniq\Service\Today\TodayCountsService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Controller\TodayCountsController
 */
class TodayCountsControllerTest extends TestCase {

	/**
	 * The controller with a signed-in user, or none.
	 *
	 * @param TodayCountsService   $service  The service double.
	 * @param bool                 $signedIn Whether a user is signed in.
	 * @param array<string, mixed> $params   The query parameters.
	 *
	 * @return TodayCountsController
	 */
	private function controller(TodayCountsService $service, bool $signedIn=true, array $params=[]): TodayCountsController {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(originalClassName: IUser::class);
		}

		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default));

		return new TodayCountsController(request: $request, userSession: $session, counts: $service, l10n: $l10n);
	}//end controller()

	/**
	 * The tiles come back as the service answers them, for the view asked.
	 *
	 * @return void
	 */
	public function testAnswersTheTilesForTheViewAsked(): void {
		$tiles = ['role' => 'mentor', 'tiles' => [['id' => 'conference-times', 'count' => 1, 'chosen' => 16, 'total' => 27]]];
		$service = $this->createMock(originalClassName: TodayCountsService::class);
		$service->expects(self::once())->method('tilesFor')->with(self::isInstanceOf(className: IUser::class), 'mentor')->willReturn($tiles);

		$response = $this->controller(service: $service, params: ['view' => 'mentor'])->index();

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: $tiles, actual: $response->getData());
	}//end testAnswersTheTilesForTheViewAsked()

	/**
	 * A view that is no string is read as the default view.
	 *
	 * @return void
	 */
	public function testAViewThatIsNoStringIsTheDefault(): void {
		$service = $this->createMock(originalClassName: TodayCountsService::class);
		$service->expects(self::once())->method('tilesFor')->with(self::anything(), '')->willReturn(['role' => null, 'tiles' => []]);

		self::assertSame(expected: Http::STATUS_OK, actual: $this->controller(service: $service, params: ['view' => ['x']])->index()->getStatus());
	}//end testAViewThatIsNoStringIsTheDefault()

	/**
	 * Nobody signed in: 401, and nothing is read.
	 *
	 * @return void
	 */
	public function testRefusesWithoutASignedInUser(): void {
		$service = $this->createMock(originalClassName: TodayCountsService::class);
		$service->expects(self::never())->method('tilesFor');

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller(service: $service, signedIn: false)->index()->getStatus());
	}//end testRefusesWithoutASignedInUser()

	/**
	 * A failed read: 503 with a message.
	 *
	 * @return void
	 */
	public function testAFailedReadAnswers503(): void {
		$service = $this->createMock(originalClassName: TodayCountsService::class);
		$service->method('tilesFor')->willReturn(null);

		$response = $this->controller(service: $service)->index();

		self::assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
		self::assertSame(expected: 'The counts on Today could not be read. Try again.', actual: $response->getData()['error']);
	}//end testAFailedReadAnswers503()
}//end class
