<?php

/**
 * QtiExportController: who may export an item bank and how a failure is answered.
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

use OCA\Learniq\Controller\QtiExportController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\QtiExportService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Controller\QtiExportController
 */
class QtiExportControllerTest extends TestCase {

	/**
	 * The export service double.
	 *
	 * @var QtiExportService&MockObject
	 */
	private QtiExportService $service;

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
	 * @param bool $allowed  Whether qti.export is granted.
	 *
	 * @return QtiExportController
	 */
	private function controller(bool $signedIn=true, bool $allowed=true): QtiExportController {
		$user = $this->createMock(IUser::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);

		$auth = $this->createMock(ActionAuthService::class);
		$call = $auth->method('requireAction')->with($user, 'qti.export');
		if ($allowed === false) {
			$call->willThrowException(new OCSForbiddenException('Not allowed.'));
		}

		$this->service = $this->createMock(QtiExportService::class);

		return new QtiExportController($this->createMock(IRequest::class), $this->service, $session, $auth);
	}//end controller()

	/**
	 * No session user is a 401; no item bank a 400; neither exports.
	 *
	 * @return void
	 */
	public function testRequestsWithoutAUserOrBankAreRefused(): void {
		$anonymous = $this->controller(signedIn: false);
		$this->service->expects($this->never())->method('export');
		self::assertSame(Http::STATUS_UNAUTHORIZED, $anonymous->export('bank-1')->getStatus());

		$controller = $this->controller();
		$this->service->expects($this->never())->method('export');
		$response = $controller->export('');
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('itemBankId is required', $response->getData()['error']);
	}//end testRequestsWithoutAUserOrBankAreRefused()

	/**
	 * Without the action the refusal escapes as a 403.
	 *
	 * @return void
	 */
	public function testWithoutTheActionTheRefusalEscapes(): void {
		$this->expectException(OCSForbiddenException::class);
		$this->controller(allowed: false)->export('bank-1');
	}//end testWithoutTheActionTheRefusalEscapes()

	/**
	 * The package downloads as a zip named after the bank.
	 *
	 * @return void
	 */
	public function testThePackageDownloads(): void {
		$controller = $this->controller();
		$this->service->expects($this->once())->method('export')->with('bank-1')->willReturn('PK-BYTES');

		$response = $controller->export('bank-1');

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame('PK-BYTES', $response->render());
		self::assertSame('application/zip', self::headersOf($response)['Content-Type']);
		self::assertStringContainsString('item-bank_bank-1_qti21.zip', self::headersOf($response)['Content-Disposition']);
	}//end testThePackageDownloads()

	/**
	 * A domain refusal is a 422 with its reason; anything else a 500.
	 *
	 * @return void
	 */
	public function testFailuresAreAnswered(): void {
		$controller = $this->controller();
		$this->service->method('export')->willReturnCallback(
			static function (string $id): string {
				if ($id === 'empty') {
					throw new \RuntimeException('Item bank has no items');
				}

				throw new \LogicException('disk full');
			}
		);

		$refused = $controller->export('empty');
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $refused->getStatus());
		self::assertSame('Item bank has no items', $refused->getData()['error']);

		$broken = $controller->export('other');
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $broken->getStatus());
		self::assertSame('Export failed: disk full', $broken->getData()['error']);
	}//end testFailuresAreAnswered()
}//end class
