<?php

/**
 * Learniq Lesson Note Controller
 *
 * POST /api/lesson-notes: a teacher's note on one lesson, or on the same
 * lesson in the next weeks, saved in one request (LessonNoteSeries). Each note
 * is saved as the caller, so LessonNoteAuthorGuard decides per lesson.
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
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\Timetable\LessonNoteSeries;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Saves a lesson note series.
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
 */
class LessonNoteController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest         $request     The request.
	 * @param IUserSession     $userSession The caller.
	 * @param LessonNoteSeries $series      Saves the note on the lesson and its repeats.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly LessonNoteSeries $series,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Save the note on `lesson` and its repeats in the next `weeks`.
	 *
	 * @return JSONResponse 201 with the count; 400 without a lesson or text; 401; 403 with the guard's reason.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$lesson = $this->request->getParam('lesson');
		$text = trim((string)($this->request->getParam('text') ?? ''));
		if (is_array($lesson) === false || (string)($lesson['cohortId'] ?? '') === '' || $text === '') {
			return new JSONResponse(data: ['error' => 'Name the lesson and write the note.'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$result = $this->series->save(
			lesson: $lesson,
			form: [
				'topic' => $this->request->getParam('topic'),
				'text' => $text,
				'audience' => $this->request->getParam('audience'),
			],
			weeks: (int)($this->request->getParam('weeks') ?? 0)
		);
		if (isset($result['error']) === true) {
			return new JSONResponse(data: $result, statusCode: Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(data: $result, statusCode: Http::STATUS_CREATED);
	}//end create()
}//end class
