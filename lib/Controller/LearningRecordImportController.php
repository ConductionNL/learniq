<?php

/**
 * Learniq Learning Record Import Controller
 *
 * Thin HTTP endpoint for uploading another institution's exported learning
 * record (or a bare ELM/Europass credential set) as evidence during
 * `Application` admissions intake. All parsing is delegated to
 * `LearningRecordImportService` — this controller is intentionally thin
 * per ADR-022.
 *
 * Legitimate PHP per ADR-031 §"NC framework requirement — thin controller":
 * the upload requires multipart file-upload handling (`$_FILES`), which
 * cannot be expressed declaratively — the same reasoning
 * `CoursePackageImportController`'s own docblock already gives.
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
 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-coordinator-can-upload-another-institution-s-record-as-evidence-during-application-intake
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\LearningRecordImportIntakeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Handles prior-institution learning-record upload during Application intake.
 *
 * Single endpoint: POST /api/applications/{applicationId}/learning-record-imports
 *
 * Multipart form fields:
 *   - file         : the uploaded JSON bundle (required)
 *   - sourceFormat : `scholiq-learning-record` | `elm-europass` (required)
 *
 * @spec openspec/changes/archive/2026-07-16-portable-learning-record/tasks.md#task-4-2
 */
class LearningRecordImportController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param IUserSession $userSession Nextcloud user session.
	 * @param ActionAuthService $actionAuth ADR-023 action authorization service.
	 * @param LearningRecordImportIntakeService $intakeService Storage + object creation for an accepted upload.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly LearningRecordImportIntakeService $intakeService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Upload and parse a prior-institution learning record for one Application.
	 *
	 * @param string $applicationId UUID of the Application this import is evidence for.
	 *
	 * @return JSONResponse The created (now `parsed`, or `uploaded`+errorMessage) LearningRecordImport, or an error.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-coordinator-uploads-a-prior-scholiq-export-during-intake-and-sees-a-verified-coverage-report
	 */
	#[NoAdminRequired]
	public function upload(string $applicationId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'learning-record.import');

		if ($applicationId === '') {
			return new JSONResponse(data: ['error' => 'applicationId is required'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$upload = new LearningRecordUpload();
		$upload->uploader      = $user;
		$upload->applicationId = $applicationId;

		return $this->intake(upload: $upload);
	}//end upload()

	/**
	 * Check the uploaded file and format of an authorised upload, then store it.
	 *
	 * @param LearningRecordUpload $upload The authorised upload: its uploader and Application.
	 *
	 * @return JSONResponse The created LearningRecordImport, or the refusal.
	 */
	private function intake(LearningRecordUpload $upload): JSONResponse {
		$uploadedFile = $this->request->getUploadedFile('file');
		$sourceFormat = (string)$this->request->getParam('sourceFormat', 'scholiq-learning-record');
		$rejection    = $this->rejectUpload(uploadedFile: $uploadedFile, sourceFormat: $sourceFormat);
		if ($rejection !== null) {
			return $rejection;
		}

		$upload->sourceFormat   = $sourceFormat;
		$upload->sourceFilename = (string)($uploadedFile['name'] ?? 'learning-record.json');
		$upload->tmpPath        = (string)$uploadedFile['tmp_name'];

		return $this->store(upload: $upload);
	}//end intake()

	/**
	 * Store the uploaded file in the uploader's tenant, then record the import.
	 *
	 * @param LearningRecordUpload $upload The accepted upload.
	 *
	 * @return JSONResponse The created LearningRecordImport, or a 500.
	 */
	private function store(LearningRecordUpload $upload): JSONResponse {
		$upload->tenantId = $this->intakeService->resolveTenantId(user: $upload->uploader);

		$sourceRef = $this->intakeService->storeUpload(
			tmpPath: $upload->tmpPath,
			ownerUid: $upload->uploader->getUID(),
			tenantId: $upload->tenantId
		);
		if ($sourceRef === null) {
			return self::error(error: 'Could not store the uploaded file.', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$upload->sourceRef = $sourceRef;

		return $this->record(upload: $upload);
	}//end store()

	/**
	 * Create the LearningRecordImport for a stored upload.
	 *
	 * @param LearningRecordUpload $upload The stored upload.
	 *
	 * @return JSONResponse The created LearningRecordImport, or a 500.
	 */
	private function record(LearningRecordUpload $upload): JSONResponse {
		$imported = $this->intakeService->createImport(
			applicationId: $upload->applicationId,
			sourceFilename: $upload->sourceFilename,
			sourceFormat: $upload->sourceFormat,
			uploadedBy: $upload->uploader->getUID(),
			sourceRef: $upload->sourceRef,
			tenantId: $upload->tenantId
		);
		if ($imported === null) {
			return self::error(error: 'Could not create the LearningRecordImport record.', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(data: $imported, statusCode: Http::STATUS_OK);
	}//end record()

	/**
	 * Validate the uploaded file and requested format, returning the error
	 * response to send when the request cannot be accepted.
	 *
	 * Checks run in the order a caller can act on them: no file at all, then a
	 * transport-level upload error, then an unsupported format, then a tmp file
	 * that is not actually on disk.
	 *
	 * @param mixed $uploadedFile The `file` field from the multipart request.
	 * @param string $sourceFormat The requested source format.
	 *
	 * @return JSONResponse|null The rejection to return, or null when the upload is acceptable.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-coordinator-can-upload-another-institution-s-record-as-evidence-during-application-intake
	 */
	private function rejectUpload(mixed $uploadedFile, string $sourceFormat): ?JSONResponse {
		if (isset($uploadedFile['tmp_name']) === false) {
			return new JSONResponse(
				data: ['error' => 'No file uploaded. POST a multipart/form-data request with a `file` field.'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
			return new JSONResponse(
				data: ['error' => 'File upload error code ' . $uploadedFile['error']],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return self::rejectContent(uploadedFile: $uploadedFile, sourceFormat: $sourceFormat);
	}//end rejectUpload()

	/**
	 * The later two checks: a supported format, then a temp file still on disk.
	 *
	 * @param array<string,mixed> $uploadedFile The uploaded `file` field, sent without error.
	 * @param string              $sourceFormat The requested source format.
	 *
	 * @return JSONResponse|null The rejection to return, or null when the upload is acceptable.
	 */
	private static function rejectContent(array $uploadedFile, string $sourceFormat): ?JSONResponse {
		if (in_array($sourceFormat, ['scholiq-learning-record', 'elm-europass'], true) === false) {
			return new JSONResponse(data: ['error' => 'Invalid sourceFormat'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		if (file_exists((string)$uploadedFile['tmp_name']) === false) {
			return new JSONResponse(
				data: ['error' => 'Uploaded file not found on server.'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return null;
	}//end rejectContent()

	/**
	 * An `{error}` response.
	 *
	 * @param string $error  The message.
	 * @param int    $status The HTTP status.
	 *
	 * @return JSONResponse
	 */
	private static function error(string $error, int $status): JSONResponse {
		return new JSONResponse(data: ['error' => $error], statusCode: $status);
	}//end error()
}//end class
