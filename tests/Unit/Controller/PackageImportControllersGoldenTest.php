<?php

/**
 * Golden tests for the QTI and course-package import endpoints.
 *
 * QtiImportController::import() and CoursePackageImportController::import()
 * receive an uploaded ZIP the same way: an authenticated caller allowed the
 * import action, the multipart `file` field present, uploaded without error
 * and still on disk. This pins, for both, every refusal and its order
 * against the action check and the tenant lookup, and the success and
 * failure responses of the import itself.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\CoursePackageImportController;
use OCA\Learniq\Controller\QtiImportController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CallerActions;
use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\CoursePackageImportService;
use OCA\Learniq\Service\QtiImportService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden tests for the two package import endpoints.
 */
class PackageImportControllersGoldenTest extends TestCase {

	/**
	 * Calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * A real temporary file standing in for the upload.
	 *
	 * @var string
	 */
	private string $tmp = '';

	/**
	 * Create the temporary upload.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->tmp = (string)tempnam(sys_get_temp_dir(), 'pkg');
	}//end setUp()

	/**
	 * Remove the temporary upload.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		@unlink($this->tmp);
		parent::tearDown();
	}//end tearDown()

	/**
	 * The caller doubles: session, action check, tenant lookup and request.
	 *
	 * @param bool                     $signedIn Whether there is a session user.
	 * @param bool                     $allowed  Whether the action check passes.
	 * @param array<string,mixed>|null $upload   The uploaded `file` field.
	 *
	 * @return array{0:IRequest,1:CallerActions,2:CallerTenantResolver}
	 */
	private function caller(bool $signedIn, bool $allowed, ?array $upload): array {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('teacher-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);

		$auth = $this->createMock(ActionAuthService::class);
		$auth->method('requireAction')->willReturnCallback(
			function (IUser $who, string $action) use ($allowed): void {
				$this->calls[] = ['requireAction', $who->getUID(), $action];
				if ($allowed === false) {
					throw new RuntimeException('forbidden action');
				}
			}
		);

		$tenants = $this->createMock(CallerTenantResolver::class);
		$tenants->method('resolve')->willReturnCallback(
			function (IUser $who): string {
				$this->calls[] = ['tenant', $who->getUID()];
				return 'tenant-1';
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->willReturnCallback(
			function (string $key) use ($upload): ?array {
				$this->calls[] = ['upload', $key];
				return $upload;
			}
		);

		return [$request, new CallerActions(userSession: $session, actionAuth: $auth), $tenants];
	}//end caller()

	/**
	 * The QTI endpoint over a service that returns or throws.
	 *
	 * @param array{0:IRequest,1:CallerActions,2:CallerTenantResolver} $caller  The caller doubles.
	 * @param array<int,string>|\Throwable                              $outcome What the import returns or throws.
	 *
	 * @return QtiImportController
	 */
	private function qti(array $caller, array|\Throwable $outcome): QtiImportController {
		$service = $this->createMock(QtiImportService::class);
		$service->method('import')->willReturnCallback(
			function (string $packagePath, string $itemBankId, string $tenantId) use ($outcome): array {
				$this->calls[] = ['import', $packagePath, $itemBankId, $tenantId];
				if ($outcome instanceof \Throwable) {
					throw $outcome;
				}

				return $outcome;
			}
		);

		return new QtiImportController($caller[0], $service, $caller[1], $caller[2]);
	}//end qti()

	/**
	 * The course-package endpoint over a service that returns or throws.
	 *
	 * @param array{0:IRequest,1:CallerActions,2:CallerTenantResolver} $caller  The caller doubles.
	 * @param array<string,mixed>|\Throwable                            $outcome What the import returns or throws.
	 *
	 * @return CoursePackageImportController
	 */
	private function coursePackage(array $caller, array|\Throwable $outcome): CoursePackageImportController {
		$service = $this->createMock(CoursePackageImportService::class);
		$service->method('import')->willReturnCallback(
			function (string $packagePath, string $sourceFilename, string $importedBy, string $tenantId) use ($outcome): array {
				$this->calls[] = ['import', $packagePath, $sourceFilename, $importedBy, $tenantId];
				if ($outcome instanceof \Throwable) {
					throw $outcome;
				}

				return $outcome;
			}
		);

		return new CoursePackageImportController($caller[0], $service, $caller[1], $caller[2]);
	}//end coursePackage()

	/**
	 * Status and body of a response.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private static function shape(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}//end shape()

	/**
	 * The upload refusals, shared by both endpoints.
	 *
	 * @return array<string,array{0:array<string,mixed>|null,1:int,2:string}>
	 */
	private function uploadRefusals(): array {
		return [
			'no file' => [null, 400, 'No file uploaded. POST a multipart/form-data request with a `file` field.'],
			'no tmp_name' => [['name' => 'x.zip', 'error' => 0], 400, 'No file uploaded. POST a multipart/form-data request with a `file` field.'],
			'upload error' => [['tmp_name' => $this->tmp, 'error' => UPLOAD_ERR_PARTIAL], 400, 'File upload error code 3'],
			'gone from disk' => [['tmp_name' => $this->tmp . '-gone', 'error' => UPLOAD_ERR_OK], 500, 'Uploaded file not found on server.'],
		];
	}//end uploadRefusals()

	/**
	 * QTI: no session, then the action check, then the bank id, the tenant, and the upload checks.
	 *
	 * @return void
	 */
	public function testQtiRefusalsInOrder(): void {
		self::assertSame([401, ['error' => 'Not authenticated']], self::shape($this->qti($this->caller(false, true, null), [])->import('bank-1')));
		self::assertSame([], $this->calls);

		try {
			$this->qti($this->caller(true, false, null), [])->import('bank-1');
			self::fail('the action check must throw');
		} catch (RuntimeException $e) {
			self::assertSame('forbidden action', $e->getMessage());
		}

		self::assertSame([['requireAction', 'teacher-1', 'qti.import']], $this->calls);

		$this->calls = [];
		self::assertSame([400, ['error' => 'itemBankId is required']], self::shape($this->qti($this->caller(true, true, null), [])->import()));
		self::assertSame([['requireAction', 'teacher-1', 'qti.import']], $this->calls);

		foreach ($this->uploadRefusals() as $name => [$upload, $status, $error]) {
			$this->calls = [];
			self::assertSame([$status, ['error' => $error]], self::shape($this->qti($this->caller(true, true, $upload), [])->import('bank-1')), $name);
			self::assertSame([['requireAction', 'teacher-1', 'qti.import'], ['tenant', 'teacher-1'], ['upload', 'file']], $this->calls, $name);
		}
	}//end testQtiRefusalsInOrder()

	/**
	 * QTI: the import's answer, a refused package (422) and a failure (500).
	 *
	 * @return void
	 */
	public function testQtiImportOutcomes(): void {
		$upload = ['tmp_name' => $this->tmp, 'error' => UPLOAD_ERR_OK, 'name' => 'items.zip'];

		$done = $this->qti($this->caller(true, true, $upload), ['i-1', 'i-2'])->import('bank-1');
		self::assertSame([200, ['itemCount' => 2, 'itemIds' => ['i-1', 'i-2']]], self::shape($done));
		self::assertSame(['import', $this->tmp, 'bank-1', 'tenant-1'], $this->calls[3]);

		$refused = $this->qti($this->caller(true, true, $upload), new RuntimeException('Not a QTI package'))->import('bank-1');
		self::assertSame([422, ['error' => 'Not a QTI package']], self::shape($refused));

		$failed = $this->qti($this->caller(true, true, $upload), new \LogicException('disk full'))->import('bank-1');
		self::assertSame([500, ['error' => 'Import failed: disk full']], self::shape($failed));
	}//end testQtiImportOutcomes()

	/**
	 * Course package: no session, then the action check, the upload checks, and only then the tenant.
	 *
	 * @return void
	 */
	public function testCoursePackageRefusalsInOrder(): void {
		self::assertSame([401, ['error' => 'Not authenticated']], self::shape($this->coursePackage($this->caller(false, true, null), [])->import()));
		self::assertSame([], $this->calls);

		try {
			$this->coursePackage($this->caller(true, false, null), [])->import();
			self::fail('the action check must throw');
		} catch (RuntimeException $e) {
			self::assertSame('forbidden action', $e->getMessage());
		}

		self::assertSame([['requireAction', 'teacher-1', 'course-package.import']], $this->calls);

		foreach ($this->uploadRefusals() as $name => [$upload, $status, $error]) {
			$this->calls = [];
			self::assertSame([$status, ['error' => $error]], self::shape($this->coursePackage($this->caller(true, true, $upload), [])->import()), $name);
			self::assertSame([['requireAction', 'teacher-1', 'course-package.import'], ['upload', 'file']], $this->calls, $name);
		}
	}//end testCoursePackageRefusalsInOrder()

	/**
	 * Course package: the report is relayed, the file name defaults, and any failure is a 500.
	 *
	 * @return void
	 */
	public function testCoursePackageImportOutcomes(): void {
		$upload = ['tmp_name' => $this->tmp, 'error' => UPLOAD_ERR_OK, 'name' => 'course.imscc'];

		$done = $this->coursePackage($this->caller(true, true, $upload), ['entries' => [['outcome' => 'imported']]])->import();
		self::assertSame([200, ['entries' => [['outcome' => 'imported']]]], self::shape($done));
		self::assertSame(
			[['requireAction', 'teacher-1', 'course-package.import'], ['upload', 'file'], ['tenant', 'teacher-1'], ['import', $this->tmp, 'course.imscc', 'teacher-1', 'tenant-1']],
			$this->calls
		);

		$this->calls = [];
		$unnamed = ['tmp_name' => $this->tmp, 'error' => UPLOAD_ERR_OK];
		$this->coursePackage($this->caller(true, true, $unnamed), [])->import();
		self::assertSame(['import', $this->tmp, 'package', 'teacher-1', 'tenant-1'], $this->calls[3]);

		$failed = $this->coursePackage($this->caller(true, true, $upload), new RuntimeException('bad manifest'))->import();
		self::assertSame([500, ['error' => 'Import failed: bad manifest']], self::shape($failed));
	}//end testCoursePackageImportOutcomes()
}//end class
