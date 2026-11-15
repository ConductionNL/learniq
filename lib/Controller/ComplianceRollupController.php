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

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\ComplianceRollupServiceInterface;
use OCA\Learniq\Kernel\Contracts\RegulationAssignmentServiceInterface;
use OCA\Learniq\Kernel\Contracts\RegulationCoverageServiceInterface;
use OCA\Learniq\Service\ActionAuthService;
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
	use AssignableRegulation;

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request       HTTP request.
	 * @param IUserSession                $userSession   Current user session.
	 * @param ActionAuthService           $actionAuth    ADR-023 action authorization.
	 * @param ComplianceRollupServiceInterface     $rollup        Roll-up computation.
	 * @param RegulationAssignmentServiceInterface $assignment    Audience-scoped assignment.
	 * @param ObjectService               $objectService OR object access.
	 * @param RegulationCoverageServiceInterface   $coverage      Coverage per regulation.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly ComplianceRollupServiceInterface $rollup,
		private readonly RegulationAssignmentServiceInterface $assignment,
		private readonly ObjectService $objectService,
		private readonly RegulationCoverageServiceInterface $coverage,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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
	 * Assign a published regulation's mandatory courses to its audience, or to
	 * one part of it (`audienceKind` + `audience`), with an optional `dueDate`.
	 *
	 * @param string $id           Regulation UUID.
	 * @param string $audienceKind section, team or hr-function; empty for the whole audience.
	 * @param string $audience     The audience value within that kind.
	 * @param string $dueDate      Due date (Y-m-d) of the enrolments created; empty for none.
	 *
	 * @return JSONResponse { created, skipped, inScope, courses }, or an error.
	 *
	 * @spec openspec/parity/capabilities.json#comp-assign-from-hr-roles
	 * @spec openspec/changes/regulation-assign-by-audience/specs/compliance-rule-coverage/spec.md#requirement-an-assignment-can-be-narrowed-to-one-audience
	 */
	#[NoAdminRequired]
	public function assignRegulation(string $id='', string $audienceKind='', string $audience='', string $dueDate=''): JSONResponse {
		$regulation = $this->assignableRegulation(id: $id);
		if ($regulation instanceof JSONResponse) {
			return $regulation;
		}

		$scope = $this->assignment->audienceOf(kind: $audienceKind, value: $audience);
		$due = $this->assignment->dueDateOf(value: $dueDate);
		if ($scope === false || $due === false) {
			return new JSONResponse(data: ['error' => 'Unknown audience or due date'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $this->assignment->assign(regulation: $regulation, audience: $scope, dueDate: $due));
	}//end assignRegulation()

}//end class
