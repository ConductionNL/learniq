<?php

/**
 * Learniq Course Sharing Controller
 *
 * The share export: a course package meant to leave the school. Thin per
 * ADR-022: it checks the session and the `course-package.share` action, reads
 * the two confirmations, and hands everything else to CourseShareExportService.
 * A refusal comes back as 422 with every reason the sharing gate gave.
 *
 * Separate from CoursePackageExportController on purpose: the regular export
 * is the school's own lossless copy and stays as it was; sharing writes a
 * consent record, so it is a POST with its own action.
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
 * @spec openspec/changes/lesson-sharing-consent-gate/specs/course-management/spec.md#requirement-a-course-leaves-the-school-only-through-the-sharing-gate
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Exception\SharingBlockedException;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CourseShareExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * POST /api/course-management/course-package-share.
 */
class CourseSharingController extends Controller {

	public const ACTION_SHARE = 'course-package.share';

	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request      HTTP request.
	 * @param CourseShareExportService $shareService Gate, strip, record.
	 * @param IUserSession             $userSession  Nextcloud user session.
	 * @param ActionAuthService        $actionAuth   ADR-023 action authorization.
	 */
	public function __construct(
		IRequest $request,
		private readonly CourseShareExportService $shareService,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Share-export a course, or answer 422 with every reason it may not leave.
	 *
	 * Reads the two confirmations `noPupilData` and `rightsCleared` from the
	 * request body; anything but an explicit true counts as not confirmed.
	 *
	 * @param string $courseId UUID of the course.
	 *
	 * @return DataDownloadResponse|JSONResponse The package, or a JSON error.
	 *
	 * @spec openspec/changes/lesson-sharing-consent-gate/specs/course-management/spec.md#requirement-a-course-leaves-the-school-only-through-the-sharing-gate
	 */
	#[NoAdminRequired]
	public function share(string $courseId=''): DataDownloadResponse|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: self::ACTION_SHARE);

		if ($courseId === '') {
			return new JSONResponse(data: ['error' => 'courseId is required'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		try {
			$download = $this->shareService->export(
				courseId: $courseId,
				userId: $user->getUID(),
				noPupilData: $this->confirmed(key: 'noPupilData'),
				rightsCleared: $this->confirmed(key: 'rightsCleared')
			);
		} catch (SharingBlockedException $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage(), 'blockers' => $e->getBlockers()],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		} catch (\Throwable $e) {
			return new JSONResponse(
				data: ['error' => 'Sharing failed: ' . $e->getMessage()],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new DataDownloadResponse(
			data: $download['content'],
			filename: $download['filename'],
			contentType: $download['contentType']
		);

	}//end share()

	/**
	 * Whether the request confirms a statement: true, 'true', '1' or 1.
	 *
	 * @param string $key The request parameter.
	 *
	 * @return bool
	 */
	private function confirmed(string $key): bool {
		return filter_var($this->request->getParam($key, false), FILTER_VALIDATE_BOOLEAN) === true;

	}//end confirmed()
}//end class
