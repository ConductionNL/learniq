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
use OCA\Learniq\Service\RegulationCoverageService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
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
	 * @param RegulationCoverageService   $coverage      Coverage per regulation.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly ComplianceRollupService $rollup,
		private readonly RegulationAssignmentService $assignment,
		private readonly ObjectService $objectService,
		private readonly RegulationCoverageService $coverage,
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
	 * Coverage per active regulation, optionally for one department.
	 *
	 * The same audience as the department roll-up may read it: the
	 * `compliance.department-rollup` action. OpenRegister scopes the learners
	 * to the caller's tenant, as for byDepartment().
	 *
	 * @param string $department A department path; empty for all.
	 *
	 * @return JSONResponse { regulations: list of coverage rows }.
	 *
	 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#requirement-per-rule-coverage-table
	 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#requirement-access
	 */
	#[NoAdminRequired]
	public function regulations(string $department=''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		// ADR-023: throws OCSForbiddenException (HTTP 403) when not allowed.
		$this->actionAuth->requireAction(user: $user, action: 'compliance.department-rollup');

		$scope = trim($department);
		if ($scope === '') {
			$scope = null;
		}

		return new JSONResponse(data: ['regulations' => $this->coverage->byRegulation(department: $scope)]);
	}//end regulations()

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
			// ObjectService::find() THROWS for an unknown id; that is a 404, not a 500.
			try {
				$regulation = $this->objectService->find(id: $id, register: 'learniq', schema: 'regulation');
			} catch (DoesNotExistException) {
				$regulation = null;
			}
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
