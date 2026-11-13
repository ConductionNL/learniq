<?php

/**
 * CoursePackageExportController: who may export, what is asked, and how a failure is answered.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\CoursePackageExportController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CoursePackageExportService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Controller\CoursePackageExportController
 */
class CoursePackageExportControllerTest extends TestCase {

	/**
	 * The export service double.
	 *
	 * @var CoursePackageExportService&MockObject
	 */
	private CoursePackageExportService $service;

	/**
	 * The headers a response carries, read without booting the server.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string, string>
	 */
	private static function headersOf(Response $response): array {
		return (array)(new \ReflectionProperty(Response::class, 'headers'))->getValue($response);
	}//end headersOf()

	/**
	 * The controller with a signed-in user, or none.
	 *
	 * @param bool $signedIn Whether a user is in the session.
	 * @param bool $allowed  Whether course-package.export is granted.
	 *
	 * @return CoursePackageExportController
	 */
	private function controller(bool $signedIn=true, bool $allowed=true): CoursePackageExportController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('docent-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);

		$auth = $this->createMock(ActionAuthService::class);
		$call = $auth->method('requireAction')->with($user, 'course-package.export');
		if ($allowed === false) {
			$call->willThrowException(new OCSForbiddenException('Not allowed.'));
		}

		$this->service = $this->createMock(CoursePackageExportService::class);

		return new CoursePackageExportController($this->createMock(IRequest::class), $this->service, $session, $auth);
	}//end controller()

	/**
	 * No session user is a 401, before any export.
	 *
	 * @return void
	 */
	public function testAnonymousIsUnauthorized(): void {
		$controller = $this->controller(signedIn: false);
		$this->service->expects($this->never())->method('exportCommonCartridge');

		$response = $controller->export('c1', 'common-cartridge');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnonymousIsUnauthorized()

	/**
	 * Without the action the refusal escapes as a 403.
	 *
	 * @return void
	 */
	public function testWithoutTheActionTheRefusalEscapes(): void {
		$this->expectException(OCSForbiddenException::class);
		$this->controller(allowed: false)->export('c1', 'common-cartridge');
	}//end testWithoutTheActionTheRefusalEscapes()

	/**
	 * A missing course and an unknown format are 400s.
	 *
	 * @return void
	 */
	public function testCourseAndFormatAreRequired(): void {
		$missing = $this->controller()->export('', 'common-cartridge');
		self::assertSame(Http::STATUS_BAD_REQUEST, $missing->getStatus());
		self::assertSame('courseId is required', $missing->getData()['error']);

		$controller = $this->controller();
		$this->service->expects($this->never())->method('exportScholiqJson');
		$format = $controller->export('c1', 'scorm');
		self::assertSame(Http::STATUS_BAD_REQUEST, $format->getStatus());
		self::assertStringContainsString('format must be', $format->getData()['error']);
	}//end testCourseAndFormatAreRequired()

	/**
	 * A Common Cartridge export downloads as a zip named after the course.
	 *
	 * @return void
	 */
	public function testCommonCartridgeDownloadsAZip(): void {
		$controller = $this->controller();
		$this->service->expects($this->once())->method('exportCommonCartridge')
			->with('c1', 'docent-1')->willReturn('ZIPBYTES');

		$response = $controller->export('c1', 'common-cartridge');

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame('ZIPBYTES', $response->render());
		self::assertSame('application/zip', self::headersOf($response)['Content-Type']);
		self::assertStringContainsString('course-c1_common-cartridge.zip', self::headersOf($response)['Content-Disposition']);
	}//end testCommonCartridgeDownloadsAZip()

	/**
	 * A JSON export downloads as JSON.
	 *
	 * @return void
	 */
	public function testJsonExportDownloadsJson(): void {
		$controller = $this->controller();
		$this->service->expects($this->once())->method('exportScholiqJson')
			->with('c2', 'docent-1')->willReturn('{"course":{}}');

		$response = $controller->export('c2', 'scholiq-json');

		self::assertSame('{"course":{}}', $response->render());
		self::assertSame('application/json', self::headersOf($response)['Content-Type']);
		self::assertStringContainsString('course-c2_scholiq.json', self::headersOf($response)['Content-Disposition']);
	}//end testJsonExportDownloadsJson()

	/**
	 * A domain refusal is a 422 with its reason; anything else a 500.
	 *
	 * @return void
	 */
	public function testFailuresAreAnswered(): void {
		$controller = $this->controller();
		$this->service->method('exportCommonCartridge')->willThrowException(new \RuntimeException('Course not found'));
		$this->service->method('exportScholiqJson')->willThrowException(new \TypeError('boom'));

		$refused = $controller->export('c1', 'common-cartridge');
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $refused->getStatus());
		self::assertSame('Course not found', $refused->getData()['error']);

		$broken = $controller->export('c1', 'scholiq-json');
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $broken->getStatus());
		self::assertSame('Export failed: boom', $broken->getData()['error']);
	}//end testFailuresAreAnswered()
}//end class
