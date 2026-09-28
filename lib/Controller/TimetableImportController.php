<?php

/**
 * Learniq Timetable Import Controller
 *
 * `POST /api/timetable/imports`: asks integriq to deliver a rostering system's
 * timetable into planninq (decision D10), then scans the delivered lessons for
 * conflicts. Before data-exchange-to-integriq this ran when a timetable-import
 * DataExchangeJob moved to running; that schema is gone, so the request is
 * made here directly.
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
 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-a-timetable-import-job-delivers-into-planninq-when-planninq-is-the-source-req-003
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Timetabling\PlanninqTimetableImport;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use RuntimeException;

/**
 * One timetable delivery into planninq, requested by an administrator.
 *
 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-a-timetable-import-job-delivers-into-planninq-when-planninq-is-the-source-req-003
 */
class TimetableImportController extends Controller {

	/**
	 * The scope keys a request may carry, as PlanninqTimetableImport reads them.
	 *
	 * @var array<int, string>
	 */
	private const SCOPE_KEYS = ['rosterSource', 'groupMap', 'teacherMap', 'from', 'to'];

	/**
	 * Constructor.
	 *
	 * @param IRequest                $request       HTTP request.
	 * @param IUserSession            $userSession   Current user session.
	 * @param ActionAuthService       $actionAuth    ADR-023 action matrix.
	 * @param PlanninqTimetableImport $planninq      Delivers through integriq into planninq.
	 * @param ISecureRandom           $secureRandom  Correlation id for the delivery.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly PlanninqTimetableImport $planninq,
		private readonly ISecureRandom $secureRandom,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Deliver one timetable into planninq.
	 *
	 * @return JSONResponse `{correlationId, state, result}` (201), or 401/403/409.
	 *
	 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-a-timetable-import-job-delivers-into-planninq-when-planninq-is-the-source-req-003
	 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-conflict-detection-runs-on-the-adapters-lessons-req-004
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: 'exchange.request');
		} catch (OCSForbiddenException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		if ($this->planninq->applies() === false) {
			return new JSONResponse(
				data: ['code' => 'planninq-required', 'reason' => 'Planninq owns the timetable; install planninq to import one.'],
				statusCode: Http::STATUS_CONFLICT
			);
		}

		$scope = [];
		foreach (self::SCOPE_KEYS as $key) {
			$value = $this->request->getParam($key);
			if ($value !== null && $value !== '') {
				$scope[$key] = $value;
			}
		}

		$job = ['id' => $this->secureRandom->generate(32, ISecureRandom::CHAR_ALPHANUMERIC), 'scope' => $scope, 'tenant_id' => ''];

		try {
			$outcome = $this->planninq->deliver(job: $job, profile: null);
		} catch (RuntimeException $exception) {
			return new JSONResponse(
				data: ['code' => 'delivery-refused', 'reason' => $exception->getMessage()],
				statusCode: Http::STATUS_CONFLICT
			);
		}

		if ($outcome['state'] !== 'fail') {
			$this->planninq->scanConflicts(job: $job);
		}

		return new JSONResponse(
			data: ['correlationId' => $job['id'], 'state' => $outcome['state'], 'result' => ($outcome['fields']['result'] ?? null)],
			statusCode: Http::STATUS_CREATED
		);
	}//end create()
}//end class
