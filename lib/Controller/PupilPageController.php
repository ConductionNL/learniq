<?php

/**
 * Learniq Pupil Page Controller
 *
 * `GET /api/pupils/{learnerId}/page` answers what a member of staff's page
 * of one pupil opens with: the header, today's state, the "Wat nu?" next
 * step and the people around the pupil. PupilPageService decides who may
 * read which pupil.
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
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupils-page-opens-with-a-header-one-next-step-and-the-people-around-the-pupil
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Service\Attendance\RollCallException;
use OCA\Learniq\Service\Pupil\PupilPageService;
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
 * The pupil page endpoint.
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupils-page-opens-with-a-header-one-next-step-and-the-people-around-the-pupil
 */
class PupilPageController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest         $request     The request.
	 * @param IUserSession     $userSession The signed-in user.
	 * @param PupilPageService $pupilPage   The pupil page's data.
	 * @param LoggerInterface  $logger      PSR logger.
	 * @param IL10N            $l10n        Messages.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly PupilPageService $pupilPage,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The opening data of a pupil's page.
	 *
	 * @param string      $learnerId The pupil's Nextcloud user id.
	 * @param string|null $cohortId  The group the page is opened from; empty for the pupil's first group of the caller.
	 *
	 * @return JSONResponse The page data, or 401 / 403 / 404 / 503.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
	 *
	 * @no-admin-idor-exempt the per-pupil guard is in the service, not here: PupilPageService::open()
	 *  admits only a caller for whom RollCallAccess::cohortsFor() returns a group holding the pupil
	 *  (a teacher of that group or school-wide staff) and answers 403 otherwise, with the session user.
	 */
	#[NoAdminRequired]
	public function show(string $learnerId, ?string $cohortId=null): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Sign in to open this page.')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(data: $this->pupilPage->open(user: $user, learnerId: $learnerId, cohortId: $cohortId));
		} catch (RollCallException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: $exception->getStatus());
		} catch (Throwable $exception) {
			$this->logger->error(
				'[PupilPageController] The pupil page could not be read: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			$message = $this->l10n->t('The pupil page could not be read. Try again.');
			return new JSONResponse(data: ['error' => $message], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}//end show()
}//end class
