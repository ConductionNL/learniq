<?php

/**
 * Learniq Lesson Onboarding Controller
 *
 * Thin HTTP endpoints for office-file-lesson-onboarding: read and set the
 * teacher's onboarding folder, and import one detected file as a lesson draft
 * once the teacher confirms it (decision D17). Listing and dismissing the
 * detected files go straight to OpenRegister from the review page (ADR-022);
 * only the steps that need the file system or document parsing live here.
 *
 * Every method acts for the signed-in user only: the folder is resolved in
 * their own files, and the importer refuses a row that is not theirs.
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
 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-nothing-is-extracted-until-the-teacher-confirms-one-file-on-the-review-page
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use InvalidArgumentException;
use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\LessonOnboarding\LessonOnboardingImporter;
use OCA\Learniq\Service\LessonOnboarding\OnboardingFolderSetting;
use OCA\Learniq\Service\LessonOnboarding\OnboardingImportException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The onboarding folder setting and the confirmed import.
 */
class LessonOnboardingController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession The signed-in user.
	 * @param OnboardingFolderSetting $folderSetting The per-teacher folder.
	 * @param LessonOnboardingImporter $importer Turns a confirmed file into a lesson draft.
	 * @param LoggerInterface $logger Logs an unexpected failure.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly OnboardingFolderSetting $folderSetting,
		private readonly LessonOnboardingImporter $importer,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The signed-in user's onboarding folder.
	 *
	 * @return JSONResponse `{folderId, path}`, nulls when none is set.
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-a-teacher-picks-a-folder
	 */
	#[NoAdminRequired]
	public function folder(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(data: $this->folderSetting->describe(userId: $user->getUID()));
	}//end folder()

	/**
	 * Set, or with an empty path clear, the signed-in user's onboarding folder.
	 *
	 * @param string $path Path of a folder in the user's own files.
	 *
	 * @return JSONResponse The stored folder, or 400 when the path is not a folder.
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-a-file-is-not-a-folder
	 */
	#[NoAdminRequired]
	public function setFolder(string $path = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(data: $this->folderSetting->choose(userId: $user->getUID(), path: $path));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}
	}//end setFolder()

	/**
	 * Import one of the signed-in user's detected files into a course.
	 *
	 * @param string $id The LessonOnboardingFile uuid.
	 * @param string $courseId The target Course uuid.
	 *
	 * @return JSONResponse The created draft's summary, or the refusal.
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-another-teacher-s-row-cannot-be-imported
	 */
	#[NoAdminRequired]
	public function import(string $id, string $courseId = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if (trim($courseId) === '') {
			return new JSONResponse(data: ['error' => 'Choose a course first.'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		try {
			return new JSONResponse(
				data: $this->importer->import(userId: $user->getUID(), rowId: $id, courseId: trim($courseId))
			);
		} catch (OnboardingImportException $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage(), 'reason' => $e->getReason()],
				statusCode: $e->getStatus()
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'[LessonOnboardingController] Import of row {rowId} failed: {exception}',
				['rowId' => $id, 'exception' => get_class($e), 'message' => $e->getMessage()]
			);
			return new JSONResponse(
				data: ['error' => 'The import failed. Try again later.', 'reason' => 'failed'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end import()
}//end class
