<?php

/**
 * Learniq Programme Progress Controller
 *
 * The signed-in learner's progress through their programmes, for the
 * learner home widget: mandatory parts counted, optional parts listed apart
 * (enrolment-programme-mandatory-per-person). The only input is the session
 * user, so a caller reads their own progress and nobody else's.
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
 * @spec openspec/changes/enrolment-programme-mandatory-per-person/specs/programme-mandatory-parts/spec.md#requirement-progress-counts-mandatory-parts
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\Programme\ProgrammeProgress;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Programme progress of the signed-in learner.
 */
class ProgrammeProgressController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request     The request.
	 * @param IUserSession      $userSession The signed-in user.
	 * @param ProgrammeProgress $progress    Progress per programme.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ProgrammeProgress $progress,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The caller's programmes with their progress.
	 *
	 * @return JSONResponse 200 `{programmes: [...]}`, or 401 without a user.
	 *
	 * @spec openspec/changes/enrolment-programme-mandatory-per-person/specs/programme-mandatory-parts/spec.md#scenario-optional-parts-do-not-block-completion
	 */
	#[NoAdminRequired]
	public function mine(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(data: ['programmes' => $this->progress->forLearner(userId: $user->getUID())]);
	}//end mine()
}//end class
