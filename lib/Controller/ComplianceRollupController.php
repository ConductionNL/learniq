<?php

/**
 * Learniq Compliance Roll-up Controller
 *
 * Two compliance operations that span many objects (learniq#951):
 *
 * - departments(): the per-department compliance roll-up, aggregated from team
 *   to department to directorate (ComplianceRollupService). Authorized via the
 *   `compliance.department-rollup` action.
 * - assignRegulation(): assign a published regulation's mandatory courses to
 *   the learners its audience scope covers (RegulationAssignmentService).
 *   Authorized via the `regulation.assign` action.
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
 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\ComplianceRollupService;
use OCA\Learniq\Service\RegulationAssignmentService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Per-department compliance roll-up and audience-scoped regulation assignment.
 */
class ComplianceRollupController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request       HTTP request.
	 * @param IUserSession                $userSession   Current user session.
	 * @param ActionAuthService           $actionAuth    ADR-023 action authorization.
	 * @param ComplianceRollupService     $rollup        Roll-up computation.
	 * @param RegulationAssignmentService $assignment    Audience-scoped assignment.
	 * @param ObjectService               $objectService OR object access.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly ComplianceRollupService $rollup,
		private readonly RegulationAssignmentService $assignment,
		private readonly ObjectService $objectService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The per-department compliance roll-up.
	 *
	 * @return JSONResponse { departments: list of roll-up rows }.
	 *
	 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
	 */
	#[NoAdminRequired]
	public function departments(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		// ADR-023: throws OCSForbiddenException (HTTP 403) when not allowed.
		$this->actionAuth->requireAction(user: $user, action: 'compliance.department-rollup');

		return new JSONResponse(data: ['departments' => $this->rollup->byDepartment()]);
	}//end departments()

	/**
	 * Assign a published regulation's mandatory courses to its audience.
	 *
	 * @param string $id Regulation UUID.
	 *
	 * @return JSONResponse { created, skipped, inScope, courses }, or an error.
	 *
	 * @spec openspec/parity/capabilities.json#comp-assign-from-hr-roles
	 */
	#[NoAdminRequired]
	public function assignRegulation(string $id=''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		// ADR-023: throws OCSForbiddenException (HTTP 403) when not allowed.
		$this->actionAuth->requireAction(user: $user, action: 'regulation.assign');

		$regulation = null;
		if ($id !== '') {
			$regulation = $this->objectService->find(id: $id, register: 'learniq', schema: 'regulation');
		}

		if ($regulation === null) {
			return new JSONResponse(data: ['error' => 'Regulation not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$data = $regulation->jsonSerialize();
		if (($data['lifecycle'] ?? '') !== 'published') {
			return new JSONResponse(data: ['error' => 'Only a published regulation can be assigned'], statusCode: Http::STATUS_CONFLICT);
		}

		return new JSONResponse(data: $this->assignment->assign(regulation: $data));
	}//end assignRegulation()
}//end class
