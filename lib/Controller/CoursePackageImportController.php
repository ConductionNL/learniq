<?php

/**
 * Learniq Course Package Import Controller
 *
 * Thin HTTP endpoint for uploading and importing an IMS Common Cartridge 1.3
 * or Moodle backup (`.mbz`) course package. All heavy lifting is delegated
 * to CoursePackageImportService — this controller is intentionally thin per
 * ADR-022.
 *
 * Legitimate PHP per ADR-031 §"NC framework requirement — thin controller":
 * the import requires file upload handling (`$_FILES`) which cannot be
 * expressed declaratively, and CoursePackageImportService is itself an
 * ADR-031 "external-format import" exception.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
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
 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CallerActions;
use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\CoursePackageImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Handles course-package upload and import.
 *
 * Single endpoint: POST /api/course-management/course-package-import
 *
 * Multipart form field:
 *   - file : the `.imscc`/`.zip` (Common Cartridge) or `.mbz` (Moodle backup) file (required)
 *
 * Returns JSON: the created `CoursePackageImportReport`, or `{ error: "message" }` on failure.
 */
class CoursePackageImportController extends Controller {
	/**
	 * Nextcloud user session.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * ADR-023 action authorization service.
	 *
	 * @var ActionAuthService
	 */
	private readonly ActionAuthService $actionAuth;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param CoursePackageImportService $importService Course-package import service.
	 * @param CallerActions $caller The user session and the ADR-023 action matrix.
	 * @param CallerTenantResolver $tenants Resolves the tenant: the per-user binding, else the default tenant.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly CoursePackageImportService $importService,
		CallerActions $caller,
		private readonly CallerTenantResolver $tenants,
	) {
		$this->userSession = $caller->userSession;
		$this->actionAuth = $caller->actionAuth;
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Import a Common Cartridge or Moodle backup course package.
	 *
	 * CSRF is required (mutating endpoint). The caller's tenant is resolved
	 * from the authenticated user's per-user tenant binding, same pattern as
	 * `QtiImportController::import()` / `AuditPackExportController::export()`.
	 *
	 * @return JSONResponse The created `CoursePackageImportReport`, or an error.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
	 */
	#[NoAdminRequired]
	public function import(): JSONResponse {
		$user = (new ImportCaller(userSession: $this->userSession, actionAuth: $this->actionAuth))->admit(action: 'course-package.import');
		if ($user instanceof JSONResponse) {
			return $user;
		}

		$upload = UploadedPackage::fromRequest(request: $this->request);
		if ($upload instanceof JSONResponse) {
			return $upload;
		}

		return $this->importPackage(upload: $upload, user: $user);
	}//end import()

	/**
	 * Import the checked upload for the caller's tenant: the report, or 500.
	 *
	 * @param array<string,mixed> $upload The checked `file` field.
	 * @param IUser               $user   The caller.
	 *
	 * @return JSONResponse
	 */
	private function importPackage(array $upload, IUser $user): JSONResponse {
		$sourceFilename = (string)($upload['name'] ?? 'package');

		// Resolve the caller's tenant — same pattern as QtiImportController::import().
		$tenantId = $this->tenants->resolve(user: $user);

		try {
			$report = $this->importService->import(
				packagePath: $upload['tmp_name'],
				sourceFilename: $sourceFilename,
				importedBy: $user->getUID(),
				tenantId: $tenantId,
			);
		} catch (\Throwable $e) {
			return UploadedPackage::importFailed(exception: $e);
		}

		return new JSONResponse(data: $report, statusCode: Http::STATUS_OK);
	}//end importPackage()
}//end class
