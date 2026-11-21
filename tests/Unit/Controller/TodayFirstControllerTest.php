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
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Controller\TodayFirstController
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
}//end class
