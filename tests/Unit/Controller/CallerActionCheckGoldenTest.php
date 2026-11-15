<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\ContactHoursController;
use OCA\Learniq\Controller\DisplayScreenController;
use OCA\Learniq\Controller\SessionChangeBatchController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CallerActions;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Pins the caller check of DisplayScreenController, SessionChangeBatchController
 * and ContactHoursController::index(): no user is 401 without an action check;
 * a user the action matrix refuses is 403 with its message; an allowed user
 * passes, the action checked being the controller's own.
 */
class CallerActionCheckGoldenTest extends TestCase {

	/**
	 * The actions the matrix was asked about.
	 *
	 * @var list<string>
	 */
	private array $asked = [];

	/**
	 * A controller whose caller is $user and whose matrix refuses with $refusal.
	 *
	 * @param class-string $class The controller.
	 * @param IUser|null $user The signed-in user.
	 * @param string|null $refusal The refusal message, null to allow.
	 *
	 * @return object
	 */
	private function controller(string $class, ?IUser $user, ?string $refusal): object {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$auth = $this->createMock(ActionAuthService::class);
		$auth->method('requireAction')->willReturnCallback(
			function (IUser $user, string $action) use ($refusal): void {
				$this->asked[] = $action;
				if ($refusal !== null) {
					throw new OCSForbiddenException($refusal);
				}
			}
		);

		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			$name = ($type instanceof ReflectionNamedType) ? $type->getName() : '';
			$args[$parameter->getName()] = match ($name) {
				CallerActions::class => new CallerActions(userSession: $session, actionAuth: $auth),
				IUserSession::class => $session,
				ActionAuthService::class => $auth,
				default => $this->createMock($name),
			};
		}

		return new $class(...$args);
	}//end controller()

	/**
	 * Run the check of a controller.
	 *
	 * @param object $controller The controller.
	 *
	 * @return JSONResponse|null
	 */
	private static function check(object $controller): ?JSONResponse {
		if ($controller instanceof ContactHoursController) {
			return $controller->index();
		}

		return (new ReflectionMethod($controller, 'denied'))->invoke($controller);
	}//end check()

	/**
	 * The controllers and their actions.
	 *
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	public static function controllers(): array {
		return [
			'display screens' => [DisplayScreenController::class, 'display-screen.manage'],
			'session change batches' => [SessionChangeBatchController::class, 'timetable.bulk-change'],
			'contact hours' => [ContactHoursController::class, 'report.contact-hours'],
		];
	}//end controllers()

	/**
	 * No user is 401, without asking the matrix.
	 *
	 * @param class-string $class The controller.
	 *
	 * @return void
	 *
	 * @dataProvider controllers
	 */
	public function testNoUserIsUnauthenticated(string $class): void {
		$response = self::check(controller: $this->controller(class: $class, user: null, refusal: null));

		self::assertSame(401, $response?->getStatus());
		self::assertSame(['error' => 'Not authenticated'], $response?->getData());
		self::assertSame([], $this->asked);
	}//end testNoUserIsUnauthenticated()

	/**
	 * A refused user is 403 with the matrix's message.
	 *
	 * @param class-string $class The controller.
	 * @param string $action Its action.
	 *
	 * @return void
	 *
	 * @dataProvider controllers
	 */
	public function testARefusedUserIsForbidden(string $class, string $action): void {
		$response = self::check(controller: $this->controller(class: $class, user: $this->createMock(IUser::class), refusal: 'Not for you'));

		self::assertSame(403, $response?->getStatus());
		self::assertSame(['error' => 'Not for you'], $response?->getData());
		self::assertSame([$action], $this->asked);
	}//end testARefusedUserIsForbidden()

	/**
	 * An allowed user passes the check.
	 *
	 * @param class-string $class The controller.
	 * @param string $action Its action.
	 *
	 * @return void
	 *
	 * @dataProvider controllers
	 */
	public function testAnAllowedUserPasses(string $class, string $action): void {
		$response = self::check(controller: $this->controller(class: $class, user: $this->createMock(IUser::class), refusal: null));

		if ($class === ContactHoursController::class) {
			self::assertSame(400, $response?->getStatus());
		} else {
			self::assertNull($response);
		}

		self::assertSame([$action], $this->asked);
	}//end testAnAllowedUserPasses()
}//end class
