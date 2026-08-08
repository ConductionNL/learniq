<?php

/**
 * Scholiq Learning Record Import Controller
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
 * @package  OCA\Scholiq\Controller
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
 * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#requirement-a-coordinator-can-upload-another-institution-s-record-as-evidence-during-application-intake
 */

declare(strict_types=1);

namespace OCA\Scholiq\Controller;

use OCA\Scholiq\AppInfo\Application;
use OCA\Scholiq\Service\ActionAuthService;
use OCA\Scholiq\Service\LearningRecordImportIntakeService;
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
 * @spec openspec/changes/portable-learning-record/tasks.md#task-4-2
 */
class LearningRecordImportController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                          $request       HTTP request.
     * @param IUserSession                      $userSession   Nextcloud user session.
     * @param ActionAuthService                 $actionAuth    ADR-023 action authorization service.
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
     * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#scenario-a-coordinator-uploads-a-prior-scholiq-export-during-intake-and-sees-a-verified-coverage-report
     */
    #[NoAdminRequired]
    public function upload(string $applicationId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'learning-record.import');

        if ($applicationId === '') {
            return new JSONResponse(data: ['error' => 'applicationId is required'], statusCode: Http::STATUS_BAD_REQUEST);
        }

        $uploadedFile = $this->request->getUploadedFile('file');
        $sourceFormat = (string) $this->request->getParam('sourceFormat', 'scholiq-learning-record');

        $rejection = $this->rejectUpload(uploadedFile: $uploadedFile, sourceFormat: $sourceFormat);
        if ($rejection !== null) {
            return $rejection;
        }

        $sourceFilename = (string) ($uploadedFile['name'] ?? 'learning-record.json');
        $tmpPath        = (string) $uploadedFile['tmp_name'];
        $tenantId       = $this->intakeService->resolveTenantId(user: $user);

        $sourceRef = $this->intakeService->storeUpload(
            tmpPath: $tmpPath,
            ownerUid: $user->getUID(),
            tenantId: $tenantId
        );
        if ($sourceRef === null) {
            return new JSONResponse(
                data: ['error' => 'Could not store the uploaded file.'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        $imported = $this->intakeService->createImport(
            applicationId: $applicationId,
            sourceFilename: $sourceFilename,
            sourceFormat: $sourceFormat,
            uploadedBy: $user->getUID(),
            sourceRef: $sourceRef,
            tenantId: $tenantId
        );
        if ($imported === null) {
            return new JSONResponse(
                data: ['error' => 'Could not create the LearningRecordImport record.'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return new JSONResponse(data: $imported, statusCode: Http::STATUS_OK);
    }//end upload()

    /**
     * Validate the uploaded file and requested format, returning the error
     * response to send when the request cannot be accepted.
     *
     * Checks run in the order a caller can act on them: no file at all, then a
     * transport-level upload error, then an unsupported format, then a tmp file
     * that is not actually on disk.
     *
     * @param mixed  $uploadedFile The `file` field from the multipart request.
     * @param string $sourceFormat The requested source format.
     *
     * @return JSONResponse|null The rejection to return, or null when the upload is acceptable.
     *
     * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-coordinator-can-upload-another-institution-s-record-as-evidence-during-application-intake
     */
    private function rejectUpload(mixed $uploadedFile, string $sourceFormat): ?JSONResponse
    {
        if (isset($uploadedFile['tmp_name']) === false) {
            return new JSONResponse(
                data: ['error' => 'No file uploaded. POST a multipart/form-data request with a `file` field.'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            return new JSONResponse(
                data: ['error' => 'File upload error code '.$uploadedFile['error']],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        if (in_array($sourceFormat, ['scholiq-learning-record', 'elm-europass'], true) === false) {
            return new JSONResponse(data: ['error' => 'Invalid sourceFormat'], statusCode: Http::STATUS_BAD_REQUEST);
        }

        if (file_exists((string) $uploadedFile['tmp_name']) === false) {
            return new JSONResponse(
                data: ['error' => 'Uploaded file not found on server.'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return null;

    }//end rejectUpload()
}//end class
