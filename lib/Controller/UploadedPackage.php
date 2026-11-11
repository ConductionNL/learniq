<?php

/**
 * Learniq uploaded package checks.
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

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * The multipart `file` an import endpoint receives, checked: present,
 * uploaded without error and still on disk; and the answer when the import
 * of it fails.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
 */
final class UploadedPackage {

	/**
	 * The uploaded `file` field, or the refusal: 400 when it is missing or
	 * failed to upload, 500 when it is no longer on disk.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return array<string,mixed>|JSONResponse
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
	 */
	public static function fromRequest(IRequest $request): array|JSONResponse {
		return self::checked(uploadedFile: $request->getUploadedFile('file'));
	}//end fromRequest()

	/**
	 * 500 with the failure's message.
	 *
	 * @param Throwable $exception What failed.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
	 */
	public static function importFailed(Throwable $exception): JSONResponse {
		return self::error(error: 'Import failed: ' . $exception->getMessage(), status: Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end importFailed()

	/**
	 * The uploaded file once it passes every check, else the refusal.
	 *
	 * @param mixed $uploadedFile The `file` field as the request returns it.
	 *
	 * @return array<string,mixed>|JSONResponse
	 */
	private static function checked(mixed $uploadedFile): array|JSONResponse {
		if (isset($uploadedFile['tmp_name']) === false) {
			return self::error(error: 'No file uploaded. POST a multipart/form-data request with a `file` field.', status: Http::STATUS_BAD_REQUEST);
		}

		if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
			return self::error(error: 'File upload error code ' . $uploadedFile['error'], status: Http::STATUS_BAD_REQUEST);
		}

		if (file_exists($uploadedFile['tmp_name']) === false) {
			return self::error(error: 'Uploaded file not found on server.', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $uploadedFile;
	}//end checked()

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
