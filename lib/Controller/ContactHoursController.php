<?php

/**
 * Learniq Contact Hours Controller
 *
 * `GET /api/reports/contact-hours?from=&to=&cohortId=`: owed, given and
 * attended contact hours for a window, for staff behind the ADR-023 action
 * `report.contact-hours` (instructors, team leads, compliance officers).
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
 * @spec openspec/changes/timetabling-contact-hours/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\ContactHoursService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * The contact hours report.
 *
 * @spec openspec/changes/timetabling-contact-hours/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
 */
class ContactHoursController extends Controller {

	public const ACTION = 'report.contact-hours';

	/**
	 * Constructor.
	 *
	 * @param IRequest            $request     HTTP request.
	 * @param IUserSession        $userSession Current user session.
	 * @param ActionAuthService   $actionAuth  ADR-023 action matrix.
	 * @param ContactHoursService $report      Computes the report.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly ContactHoursService $report,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The report for a window.
	 *
	 * @param string|null $from     First day (Y-m-d).
	 * @param string|null $to       Last day (Y-m-d).
	 * @param string|null $cohortId One group, or none for all.
	 *
	 * @return JSONResponse The report, or 400/401/403/503.
	 *
	 * @spec openspec/changes/timetabling-contact-hours/specs/attendance/spec.md#requirement-a-coordinator-compares-owed-given-and-attended-contact-hours
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(?string $from=null, ?string $to=null, ?string $cohortId=null): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: self::ACTION);
		} catch (OCSForbiddenException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		$pattern = '/^\d{4}-\d{2}-\d{2}$/';
		if (preg_match($pattern, (string)$from) !== 1 || preg_match($pattern, (string)$to) !== 1 || (string)$from > (string)$to) {
			return new JSONResponse(data: ['error' => 'Choose a first and a last day.'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		try {
			$data = $this->report->forPeriod(from: (string)$from, to: (string)$to, cohortId: $cohortId);
		} catch (RuntimeException $exception) {
			return new JSONResponse(data: ['error' => 'The timetable could not be read.'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse(data: $data);
	}//end index()
}//end class
