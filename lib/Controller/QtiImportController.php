<?php

/**
 * Learniq QTI Import Controller
 *
 * Thin HTTP endpoint for uploading and importing a QTI 2.x / 3.0 or Common Cartridge
 * ZIP package into a specified ItemBank. All heavy lifting is delegated to
 * QtiImportService — this controller is intentionally thin per ADR-022.
 *
 * Legitimate PHP per ADR-031 §"NC framework requirement — thin controller": the QTI
 * import requires file upload handling (`$_FILES`) which cannot be expressed
 * declaratively, and QtiImportService is itself an ADR-031 "external-format import"
 * exception. This controller provides the Nextcloud HTTP surface only.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use Closure;
use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CallerActions;
use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\QtiImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Handles QTI package upload and import into an ItemBank.
 *
 * Single endpoint: POST /api/assessment/qti-import
 *
 * Multipart form fields:
 *   - file       : the QTI / CC .zip file (required)
 *   - itemBankId : UUID of the target ItemBank (required)
 *
 * Returns JSON:
 *   - { itemCount: N, itemIds: [uuid, ...] } on success
 *   - { error: "message" }                   on failure
 */
class QtiImportController extends Controller {
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
	 * @param QtiImportService $qtiImportService QTI import service.
	 * @param CallerActions $caller The user session and the ADR-023 action matrix.
	 * @param CallerTenantResolver $tenants Resolves the tenant: the per-user binding, else the default tenant.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly QtiImportService $qtiImportService,
		CallerActions $caller,
		private readonly CallerTenantResolver $tenants,
	) {
		$this->userSession = $caller->userSession;
		$this->actionAuth = $caller->actionAuth;
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Import a QTI 2.x / 3.0 or Common Cartridge ZIP package into an ItemBank.
	 *
	 * CSRF is required (mutating endpoint — wave-12 WF2). The caller must supply a
	 * valid Nextcloud CSRF token in the request; AJAX clients set this automatically
	 * via the `requesttoken` header or form field.
	 *
	 * The caller's tenant is resolved from the authenticated user's per-user tenant
	 * binding (same pattern as AuditPackExportController). Items are written scoped to
	 * that tenant so cross-tenant ItemBank poisoning is not possible even if an ItemBank
	 * UUID from another tenant is supplied.
	 *
	 * @param string $itemBankId UUID of the target ItemBank.
	 *
	 * @return JSONResponse JSON with created item UUIDs and count, or an error.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
	 */
	#[NoAdminRequired]
	public function import(string $itemBankId = ''): JSONResponse {
		$user = $this->admitImporter(itemBankId: $itemBankId);
		if ($user instanceof JSONResponse) {
			return $user;
		}

		// Resolve the caller's tenant — prevents cross-tenant ItemBank poisoning (wave-12 WF2).
		// Same pattern as AuditPackExportController::export().
		$tenantId = $this->tenants->resolve(user: $user);

		$upload = UploadedPackage::fromRequest(request: $this->request);
		if ($upload instanceof JSONResponse) {
			return $upload;
		}

		return $this->importItems(
			import: fn (): array => $this->qtiImportService->import(packagePath: $upload['tmp_name'], itemBankId: $itemBankId, tenantId: $tenantId)
		);
	}//end import()

	/**
	 * The caller allowed to import, or the refusal: 401 without a session,
	 * 400 without an itemBankId (after the action check, which throws its own).
	 *
	 * @param string $itemBankId UUID of the target ItemBank.
	 *
	 * @return IUser|JSONResponse
	 */
	private function admitImporter(string $itemBankId): IUser|JSONResponse {
		$user = (new ImportCaller(userSession: $this->userSession, actionAuth: $this->actionAuth))->admit(action: 'qti.import');
		if ($user instanceof JSONResponse || $itemBankId !== '') {
			return $user;
		}

		return new JSONResponse(
			data: ['error' => 'itemBankId is required'],
			statusCode: Http::STATUS_BAD_REQUEST
		);
	}//end admitImporter()

	/**
	 * Run the import: the created item ids, 422 when the package is refused,
	 * 500 on any other failure.
	 *
	 * @param Closure():array<int,string> $import The import of the uploaded package.
	 *
	 * @return JSONResponse
	 */
	private function importItems(Closure $import): JSONResponse {
		try {
			$createdIds = $import();
		} catch (\RuntimeException $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		} catch (\Throwable $e) {
			return UploadedPackage::importFailed(exception: $e);
		}

		return $this->itemsResponse(createdIds: $createdIds);
	}//end importItems()

	/**
	 * 200 with the created items.
	 *
	 * @param array<int,string> $createdIds The created Item ids.
	 *
	 * @return JSONResponse
	 */
	private function itemsResponse(array $createdIds): JSONResponse {
		return new JSONResponse(
			data: [
				'itemCount' => count($createdIds),
				'itemIds' => $createdIds,
			],
			statusCode: Http::STATUS_OK
		);
	}//end itemsResponse()
}//end class
