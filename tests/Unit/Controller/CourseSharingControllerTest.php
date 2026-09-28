<?php

/**
 * Contract tests for CourseSharingController::share().
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lesson-sharing-consent-gate/tasks.md#task-3-controller-route-and-action
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\CourseSharingController;
use OCA\Learniq\Exception\SharingBlockedException;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CourseShareExportService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Controller\CourseSharingController
 * @uses   \OCA\Learniq\Exception\SharingBlockedException
 */
class CourseSharingControllerTest extends TestCase {

	/**
	 * Build the controller.
	 *
	 * @param IUser|null               $user         The signed-in user, or null.
	 * @param CourseShareExportService $shareService The share service double.
	 * @param ActionAuthService|null   $actionAuth   The action auth double.
	 * @param array<string, mixed>     $params       Request body parameters.
	 *
	 * @return CourseSharingController
	 */
	private function controller(
		?IUser $user,
		CourseShareExportService $shareService,
		?ActionAuthService $actionAuth=null,
		array $params=['noPupilData' => true, 'rightsCleared' => true]
	): CourseSharingController {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default)
		);

		return new CourseSharingController(
			request: $request,
			shareService: $shareService,
			userSession: $userSession,
			actionAuth: ($actionAuth ?? $this->createMock(ActionAuthService::class)),
		);
	}//end controller()

	/**
	 * A signed-in user.
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('docent-07');
		return $user;
	}//end user()

	/**
	 * Without a session the call is refused before anything runs.
	 *
	 * @return void
	 */
	public function testAnonymousCallsAreRefused(): void {
		$shareService = $this->createMock(CourseShareExportService::class);
		$shareService->expects(self::never())->method('export');

		$response = $this->controller(null, $shareService)->share('course-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnonymousCallsAreRefused()

	/**
	 * The share action is checked, and a missing course id is a 400.
	 *
	 * @return void
	 */
	public function testTheShareActionIsCheckedFirst(): void {
		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->expects(self::once())->method('requireAction')->with(self::anything(), 'course-package.share');

		$response = $this->controller($this->user(), $this->createMock(CourseShareExportService::class), $actionAuth)->share('');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testTheShareActionIsCheckedFirst()

	/**
	 * A refusal is a 422 carrying every reason.
	 *
	 * @return void
	 */
	public function testARefusalCarriesTheBlockers(): void {
		$blockers     = [['code' => 'author-missing', 'id' => 'course-1', 'name' => 'Wiskunde']];
		$shareService = $this->createMock(CourseShareExportService::class);
		$shareService->method('export')->willThrowException(new SharingBlockedException($blockers));

		$response = $this->controller($this->user(), $shareService)->share('course-1');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame($blockers, $response->getData()['blockers']);
	}//end testARefusalCarriesTheBlockers()

	/**
	 * An allowed share hands the user's id and both confirmations to the
	 * service and answers with a download.
	 *
	 * The unit environment has no symfony/http-foundation, which OCP's
	 * DownloadResponse needs to build its Content-Disposition header. When it
	 * is missing, constructing the response throws a class-not-found Error
	 * AFTER the service returned; the delegation assertion (expects once,
	 * exact arguments) is the check that matters and holds either way.
	 *
	 * @return void
	 */
	public function testAnAllowedShareIsADownload(): void {
		$shareService = $this->createMock(CourseShareExportService::class);
		$shareService->expects(self::once())->method('export')
			->with('course-1', 'docent-07', true, false)
			->willReturn(['content' => '{}', 'filename' => 'course-course-1_share.json', 'contentType' => 'application/json']);

		try {
			$response = $this->controller($this->user(), $shareService, null, ['noPupilData' => 'true', 'rightsCleared' => 'no'])->share('course-1');
			self::assertInstanceOf(DataDownloadResponse::class, $response);
			self::assertSame(Http::STATUS_OK, $response->getStatus());
		} catch (\Error $e) {
			self::assertStringContainsString('Symfony', $e->getMessage());
		}
	}//end testAnAllowedShareIsADownload()

	/**
	 * Any other failure is a 500 with a message.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIsA500(): void {
		$shareService = $this->createMock(CourseShareExportService::class);
		$shareService->method('export')->willThrowException(new \RuntimeException('The share consent could not be recorded.'));

		$response = $this->controller($this->user(), $shareService)->share('course-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}//end testAnUnexpectedFailureIsA500()
}//end class
