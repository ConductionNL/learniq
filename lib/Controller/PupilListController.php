<?php

/**
 * Learniq Pupil List Controller
 *
 * `GET /api/pupil-list` answers the staff list of the pupils of one group:
 * a row per pupil with today's state and what the school kind acts on, and
 * how many pupils each saved view holds. PupilListService decides who may
 * read which group.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupil-list-shows-what-the-school-kind-acts-on-and-its-saved-views-count
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Service\Attendance\RollCallException;
use OCA\Learniq\Service\Pupil\PupilListService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The pupil list endpoint.
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupil-list-shows-what-the-school-kind-acts-on-and-its-saved-views-count
 */
class PupilListController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest         $request     The request.
	 * @param IUserSession     $userSession The signed-in user.
	 * @param PupilListService $pupilList   The pupil list's data.
	 * @param LoggerInterface  $logger      PSR logger.
	 * @param IL10N            $l10n        Messages.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly PupilListService $pupilList,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The pupils of a group, as list rows with the saved views' counts.
	 *
	 * Query parameters: `cohortId` (the group; empty for the caller's first
	 * group), `view` (a saved view narrowing the rows: absent-today,
	 * no-conference-time, with-care or with-signal) and `sort` (`signal-first`
	 * for pupils with a signal first; else by name).
	 *
	 * @return JSONResponse The list, or 401 / 403 / 503.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T1
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T2
	 *
	 * @no-admin-idor-exempt the per-group guard is in the service, not here: PupilListService::forCohort()
	 *  reads only a group RollCallAccess::cohortsFor() returns for the session user (a teacher of that
	 *  group or school-wide staff) and RollCallAccess::chosenCohort() answers 403 for any other group.
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Sign in to open this page.')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(data: $this->pupilList->forCohort(user: $user, query: $this->query()));
		} catch (RollCallException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage(), 'message' => $exception->getMessage()], statusCode: $exception->getStatus());
		} catch (Throwable $exception) {
			$this->logger->error(
				'[PupilListController] The pupil list could not be read: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			$message = $this->l10n->t('The pupil list could not be read. Try again.');
			return new JSONResponse(data: ['error' => $message], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}//end index()

	/**
	 * The group, view and sort the request asks for.
	 *
	 * @return array{cohortId: string|null, view: string, sort: string}
	 */
	private function query(): array {
		$cohortId = $this->request->getParam('cohortId');
		if (is_string($cohortId) === false) {
			$cohortId = null;
		}

		return [
			'cohortId' => $cohortId,
			'view' => (string)$this->request->getParam('view', ''),
			'sort' => (string)$this->request->getParam('sort', ''),
		];
	}//end query()
}//end class
