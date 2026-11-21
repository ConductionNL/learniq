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
use Psr\Log\NullLogger;
use RuntimeException;

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
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default));

		return new TodayFirstController(request: $request, userSession: $session, firstToday: $service, logger: new NullLogger(), l10n: $l10n);
	}//end controller()

	/**
	 * The card comes back as the service answers it, for the view asked.
	 *
	 * @return void
	 */
	public function testShowAnswersTheCardForTheViewAsked(): void {
		$service = $this->createMock(FirstTodayService::class);
		$service->expects(self::once())->method('forUser')
			->with(self::isInstanceOf(IUser::class), 'mentor')
			->willReturn(['role' => 'mentor', 'rule' => 'unauthorised-absence', 'count' => 1]);

		$response = $this->controller(service: $service, params: ['view' => 'mentor'])->show();

		self::assertSame(200, $response->getStatus());
		self::assertSame('unauthorised-absence', $response->getData()['rule']);
	}//end testShowAnswersTheCardForTheViewAsked()

	/**
	 * A view that is no string is read as the default view.
	 *
	 * @return void
	 */
	public function testANonStringViewIsTheDefault(): void {
		$service = $this->createMock(FirstTodayService::class);
		$service->expects(self::once())->method('forUser')->with(self::anything(), '')->willReturn(['role' => null, 'rule' => null, 'count' => 0]);

		self::assertSame(200, $this->controller(service: $service, params: ['view' => ['x']])->show()->getStatus());
	}//end testANonStringViewIsTheDefault()

	/**
	 * Without a session there is no card.
	 *
	 * @return void
	 */
	public function testSignedOutIsRefused(): void {
		$service = $this->createMock(FirstTodayService::class);
		$service->expects(self::never())->method('forUser');

		self::assertSame(401, $this->controller(service: $service, signedIn: false)->show()->getStatus());
	}//end testSignedOutIsRefused()

	/**
	 * A failed read answers 503 with a sentence, never the cause.
	 *
	 * @return void
	 */
	public function testAFailedReadAnswers503(): void {
		$service = $this->createMock(FirstTodayService::class);
		$service->method('forUser')->willThrowException(new RuntimeException('database gone'));

		$response = $this->controller(service: $service)->show();

		self::assertSame(503, $response->getStatus());
		self::assertSame('What comes first today could not be read. Try again.', $response->getData()['error']);
	}//end testAFailedReadAnswers503()
}//end class
