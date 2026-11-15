<?php

/**
 * Learniq LearningRecordImportController unit tests.
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
 * @spec openspec/changes/archive/2026-07-16-portable-learning-record/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\LearningRecordImportController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\LearningRecordImportIntakeService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the upload endpoint's refusal paths.
 */
class LearningRecordImportControllerTest extends TestCase {

	/**
	 * Build a controller.
	 *
	 * @param IUser|null $user Signed-in user, or null.
	 * @param array<string,mixed>|null $uploadedFile The uploaded file array, or null when absent.
	 *
	 * @return LearningRecordImportController
	 */
	private function controller(?IUser $user, ?array $uploadedFile = null): LearningRecordImportController {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->willReturn($uploadedFile);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				return $default;
			}
		);

		return new LearningRecordImportController(
			request: $request,
			userSession: $userSession,
			actionAuth: $this->createMock(ActionAuthService::class),
			intakeService: $this->createMock(LearningRecordImportIntakeService::class),
		);
	}//end controller()

	/**
	 * An anonymous caller is refused before authorization or any file handling.
	 *
	 * This endpoint accepts an UPLOAD against a named Application, so the
	 * refusal has to happen before the request body is touched at all.
	 *
	 * @return void
	 */
	public function testUploadRefusesAnonymousCallers(): void {
		$response = $this->controller(null)->upload('application-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame('Not authenticated', ((array)$response->getData())['error'] ?? null);
	}//end testUploadRefusesAnonymousCallers()

	/**
	 * An empty applicationId is rejected — an import must name its target.
	 *
	 * Without this the upload would be stored against no Application at all.
	 *
	 * @return void
	 */
	public function testUploadRequiresAnApplicationId(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer-1');

		$response = $this->controller($user)->upload('');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('applicationId is required', ((array)$response->getData())['error'] ?? null);
	}//end testUploadRequiresAnApplicationId()

	/**
	 * A request carrying no file is refused with the specific missing-file error.
	 *
	 * Asserts the RESPONSE ITEM — the exact `error` string — not merely that a
	 * JSONResponse came back or that the status sits somewhere in the 4xx band.
	 * This endpoint has four distinct refusal reasons (no file, upload error
	 * code, invalid sourceFormat, tmp file missing) and three of them are also
	 * 400s, so a status-only assertion would pass on any of them and could not
	 * tell "no file was sent" from "the format was wrong".
	 *
	 * @return void
	 */
	public function testUploadWithNoFileIsRefusedWithTheMissingFileError(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer-1');

		$response = $this->controller($user, null)->upload('application-1');
		$data = (array)$response->getData();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(
			'No file uploaded. POST a multipart/form-data request with a `file` field.',
			$data['error'] ?? null
		);
	}//end testUploadWithNoFileIsRefusedWithTheMissingFileError()

	/**
	 * An unsupported sourceFormat is refused with its own distinct error.
	 *
	 * Pairs with the test above to prove the two 400s are actually
	 * distinguishable, which is the whole point of asserting on the item: a
	 * regression that collapsed every refusal into one generic message would
	 * fail here while a status-only test stayed green.
	 *
	 * @return void
	 */
	public function testUploadRejectsAnUnsupportedSourceFormat(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer-1');

		$tmp = tempnam(sys_get_temp_dir(), 'lri');
		file_put_contents($tmp, '{}');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->willReturn(
			['name' => 'record.json', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK]
		);
		$request->method('getParam')->willReturn('not-a-real-format');

		$controller = new LearningRecordImportController(
			request: $request,
			userSession: $userSession,
			actionAuth: $this->createMock(ActionAuthService::class),
			intakeService: $this->createMock(LearningRecordImportIntakeService::class),
		);

		$response = $controller->upload('application-1');
		$data = (array)$response->getData();

		unlink($tmp);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('Invalid sourceFormat', $data['error'] ?? null);
	}//end testUploadRejectsAnUnsupportedSourceFormat()

	/**
	 * The intake double the golden tests share.
	 *
	 * @var LearningRecordImportIntakeService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private LearningRecordImportIntakeService $intake;

	/**
	 * A signed-in officer and a controller over an accepted upload, with the
	 * action check and the intake doubles given.
	 *
	 * @param ActionAuthService $actionAuth The action check.
	 * @param array<string,mixed>|null $file The uploaded file array.
	 *
	 * @return LearningRecordImportController
	 */
	private function officerController(ActionAuthService $actionAuth, ?array $file): LearningRecordImportController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer-1');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->willReturn($file);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'sourceFormat' ? 'elm-europass' : $default)
		);

		$this->intake = $this->createMock(LearningRecordImportIntakeService::class);
		$this->intake->method('resolveTenantId')->willReturn('tenant-1');

		return new LearningRecordImportController(
			request: $request,
			userSession: $userSession,
			actionAuth: $actionAuth,
			intakeService: $this->intake,
		);
	}//end officerController()

	/**
	 * A temp file holding an upload, with its file array.
	 *
	 * @return array<string,mixed>
	 */
	private static function uploaded(): array {
		$tmp = tempnam(sys_get_temp_dir(), 'lri');
		file_put_contents($tmp, '{}');

		return ['name' => 'record.json', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK];
	}//end uploaded()

	/**
	 * ALLOWED: an authorised officer's upload is checked for the import
	 * action, stored under their uid and tenant, and recorded against the
	 * application.
	 *
	 * @return void
	 */
	public function testAnAuthorisedUploadIsStoredAndRecorded(): void {
		$file    = self::uploaded();
		$auth    = $this->createMock(ActionAuthService::class);
		$auth->expects(self::once())->method('requireAction')->with(self::isInstanceOf(IUser::class), 'learning-record.import');
		$controller = $this->officerController(actionAuth: $auth, file: $file);
		$this->intake->expects(self::once())->method('storeUpload')->with($file['tmp_name'], 'officer-1', 'tenant-1')->willReturn('ref-1');
		$this->intake->expects(self::once())->method('createImport')
			->with('application-1', 'record.json', 'elm-europass', 'officer-1', 'ref-1', 'tenant-1')
			->willReturn(['id' => 'import-1']);

		$response = $controller->upload('application-1');
		unlink($file['tmp_name']);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['id' => 'import-1'], $response->getData());
	}//end testAnAuthorisedUploadIsStoredAndRecorded()

	/**
	 * REFUSED: a caller without the import action gets the check's refusal,
	 * and nothing is read, stored or recorded.
	 *
	 * @return void
	 */
	public function testACallerWithoutTheImportActionIsRefusedBeforeAnything(): void {
		$auth = $this->createMock(ActionAuthService::class);
		$auth->method('requireAction')->willThrowException(new \RuntimeException('refused'));
		$controller = $this->officerController(actionAuth: $auth, file: self::uploaded());
		$this->intake->expects(self::never())->method('storeUpload');
		$this->intake->expects(self::never())->method('createImport');

		$this->expectExceptionMessage('refused');
		$controller->upload('application-1');
	}//end testACallerWithoutTheImportActionIsRefusedBeforeAnything()

	/**
	 * REFUSED: a transport error, a missing temp file, a failed store and a
	 * failed record each answer with their own error.
	 *
	 * @return void
	 */
	public function testEachLaterRefusalNamesItsOwnError(): void {
		$auth = $this->createMock(ActionAuthService::class);

		$response = $this->officerController(actionAuth: $auth, file: ['tmp_name' => '/x', 'error' => UPLOAD_ERR_PARTIAL])->upload('application-1');
		self::assertSame([Http::STATUS_BAD_REQUEST, ['error' => 'File upload error code ' . UPLOAD_ERR_PARTIAL]], [$response->getStatus(), $response->getData()]);

		$response = $this->officerController(actionAuth: $auth, file: ['tmp_name' => '/no/such/file', 'error' => UPLOAD_ERR_OK])->upload('application-1');
		self::assertSame([Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Uploaded file not found on server.']], [$response->getStatus(), $response->getData()]);

		$file       = self::uploaded();
		$controller = $this->officerController(actionAuth: $auth, file: $file);
		$this->intake->method('storeUpload')->willReturn(null);
		$this->intake->expects(self::never())->method('createImport');
		$response = $controller->upload('application-1');
		self::assertSame([Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Could not store the uploaded file.']], [$response->getStatus(), $response->getData()]);

		$controller = $this->officerController(actionAuth: $auth, file: array_merge($file, ['name' => null]));
		$this->intake->method('storeUpload')->willReturn('ref-1');
		$this->intake->expects(self::once())->method('createImport')->with('application-1', 'learning-record.json')->willReturn(null);
		$response = $controller->upload('application-1');
		unlink($file['tmp_name']);
		self::assertSame([Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Could not create the LearningRecordImport record.']], [$response->getStatus(), $response->getData()]);
	}//end testEachLaterRefusalNamesItsOwnError()
}//end class
