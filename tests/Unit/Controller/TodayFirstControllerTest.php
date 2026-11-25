<?php

/**
 * The First today endpoint: session, view and failures.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\TodayFirstController;
use OCA\Learniq\Service\Today\FirstTodayService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Controller\TodayFirstController
 * @uses   \OCA\Learniq\Service\Today\FirstTodayCard
 */
class TodayFirstControllerTest extends TestCase {

	/**
	 * The controller with a signed-in user, or none.
	 *
	 * @param FirstTodayService    $service  The service double.
	 * @param bool                 $signedIn Whether a user is signed in.
	 * @param array<string, mixed> $params   The query parameters.
	 *
	 * @return TodayFirstController
	 */
	private function controller(FirstTodayService $service, bool $signedIn=true, array $params=[]): TodayFirstController {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(originalClassName: IUser::class);
		}

		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$l10n->method('n')->willReturnArgument(1);
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default));

		return new TodayFirstController(request: $request, userSession: $session, firstToday: $service, l10n: $l10n);
	}//end controller()

	/**
	 * The card comes back as the service answers it, for the view asked.
	 *
	 * @return void
	 */
	public function testShowAnswersTheCardForTheViewAsked(): void {
		$service = $this->createMock(originalClassName: FirstTodayService::class);
		$service->expects(self::once())->method('cardFor')
			->with(self::isInstanceOf(className: IUser::class), 'mentor')
			->willReturn(['role' => 'mentor', 'rule' => 'unauthorised-absence', 'count' => 1]);

		$response = $this->controller(service: $service, params: ['view' => 'mentor'])->show();

		self::assertSame(expected: 200, actual: $response->getStatus());
		self::assertSame(expected: 'unauthorised-absence', actual: $response->getData()['rule']);
	}//end testShowAnswersTheCardForTheViewAsked()

	/**
	 * A rule Today has a card for comes back with the card's sentence, line and page; no rule comes back without.
	 *
	 * @return void
	 */
	public function testShowAddsWhatTheCardSaysAndOpens(): void {
		$service = $this->createMock(originalClassName: FirstTodayService::class);
		$service->method('cardFor')->willReturnOnConsecutiveCalls(
			['role' => 'mentor', 'rule' => 'unauthorised-absence', 'count' => 2],
			['role' => 'mentor', 'rule' => null, 'count' => 0]
		);
		$controller = $this->controller(service: $service, params: ['view' => 'mentor']);

		$card = $controller->show()->getData();
		$none = $controller->show()->getData();

		self::assertSame(expected: 'AttendanceFlags', actual: $card['page']);
		self::assertNotSame(expected: '', actual: $card['title']);
		self::assertSame(expected: 'In the last two weeks, and not yet handled.', actual: $card['reason']);
		self::assertArrayNotHasKey(key: 'title', array: $none);
	}//end testShowAddsWhatTheCardSaysAndOpens()

	/**
	 * A view that is no string is read as the default view.
	 *
	 * @return void
	 */
	public function testANonStringViewIsTheDefault(): void {
		$service = $this->createMock(originalClassName: FirstTodayService::class);
		$service->expects(self::once())->method('cardFor')->with(self::anything(), '')->willReturn(['role' => null, 'rule' => null, 'count' => 0]);

		self::assertSame(expected: 200, actual: $this->controller(service: $service, params: ['view' => ['x']])->show()->getStatus());
	}//end testANonStringViewIsTheDefault()

	/**
	 * Without a session there is no card.
	 *
	 * @return void
	 */
	public function testSignedOutIsRefused(): void {
		$service = $this->createMock(originalClassName: FirstTodayService::class);
		$service->expects(self::never())->method('cardFor');

		self::assertSame(expected: 401, actual: $this->controller(service: $service, signedIn: false)->show()->getStatus());
	}//end testSignedOutIsRefused()

	/**
	 * A card that could not be read answers 503 with a sentence, never the cause.
	 *
	 * @return void
	 */
	public function testAFailedReadAnswers503(): void {
		$service = $this->createMock(originalClassName: FirstTodayService::class);
		$service->method('cardFor')->willReturn(null);

		$response = $this->controller(service: $service)->show();

		self::assertSame(expected: 503, actual: $response->getStatus());
		self::assertSame(expected: 'What comes first today could not be read. Try again.', actual: $response->getData()['error']);
	}//end testAFailedReadAnswers503()

	/**
	 * The role tiles come back as the service answers them, for the view asked.
	 *
	 * @return void
	 */
	public function testCountsAnswersTheTilesForTheViewAsked(): void {
		$tiles = ['role' => 'mentor', 'tiles' => [['id' => 'conference-times', 'count' => 1, 'chosen' => 16, 'total' => 27]]];
		$service = $this->createMock(originalClassName: FirstTodayService::class);
		$service->expects(self::once())->method('tilesFor')->with(self::isInstanceOf(className: IUser::class), 'mentor')->willReturn($tiles);

		$response = $this->controller(service: $service, params: ['view' => 'mentor'])->counts();

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: $tiles, actual: $response->getData());
	}//end testCountsAnswersTheTilesForTheViewAsked()

	/**
	 * The role tiles: a view that is no string is the default view.
	 *
	 * @return void
	 */
	public function testCountsReadsAViewThatIsNoStringAsTheDefault(): void {
		$service = $this->createMock(originalClassName: FirstTodayService::class);
		$service->expects(self::once())->method('tilesFor')->with(self::anything(), '')->willReturn(['role' => null, 'tiles' => []]);

		self::assertSame(expected: Http::STATUS_OK, actual: $this->controller(service: $service, params: ['view' => ['x']])->counts()->getStatus());
	}//end testCountsReadsAViewThatIsNoStringAsTheDefault()

	/**
	 * The role tiles: nobody signed in answers 401 and reads nothing.
	 *
	 * @return void
	 */
	public function testCountsRefusesWithoutASignedInUser(): void {
		$service = $this->createMock(originalClassName: FirstTodayService::class);
		$service->expects(self::never())->method('tilesFor');

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller(service: $service, signedIn: false)->counts()->getStatus());
	}//end testCountsRefusesWithoutASignedInUser()

	/**
	 * The role tiles: a failed read answers 503 with a message.
	 *
	 * @return void
	 */
	public function testCountsAnswers503WhenTheReadFailed(): void {
		$service = $this->createMock(originalClassName: FirstTodayService::class);
		$service->method('tilesFor')->willReturn(null);

		$response = $this->controller(service: $service)->counts();

		self::assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
		self::assertSame(expected: 'The counts on Today could not be read. Try again.', actual: $response->getData()['error']);
	}//end testCountsAnswers503WhenTheReadFailed()

	/**
	 * The BPV hours per company come back as the service answers them; nobody signed in gets 401; a failed read 503.
	 *
	 * @return void
	 */
	public function testBpvHoursAnswersTheCompaniesOr401Or503(): void {
		$companies = ['companies' => [['company' => 'Van Dijk Machinebouw', 'students' => 2, 'weeks' => 2, 'hours' => 56.0]]];
		$service = $this->createMock(originalClassName: FirstTodayService::class);
		$service->expects(self::once())->method('hoursPerCompanyFor')->with(self::isInstanceOf(className: IUser::class))->willReturn($companies);

		$response = $this->controller(service: $service)->bpvHours();
		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: $companies, actual: $response->getData());

		$none = $this->createMock(originalClassName: FirstTodayService::class);
		$none->expects(self::never())->method('hoursPerCompanyFor');
		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller(service: $none, signedIn: false)->bpvHours()->getStatus());

		$failed = $this->createMock(originalClassName: FirstTodayService::class);
		$failed->method('hoursPerCompanyFor')->willReturn(null);
		$response = $this->controller(service: $failed)->bpvHours();
		self::assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
		self::assertSame(expected: 'The BPV hours on Today could not be read. Try again.', actual: $response->getData()['error']);
	}//end testBpvHoursAnswersTheCompaniesOr401Or503()
}//end class
