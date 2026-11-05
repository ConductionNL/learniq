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
use OCA\Learniq\Service\RegulationAssignPreview;
use OCA\Learniq\Service\RegulationAudienceResolver;
use OCA\Learniq\Service\RegulationCoverageService;
use OCA\Learniq\Support\FindOrNull;
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
	use FindOrNull;

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
	 * @param RegulationAssignPreview     $preview       Assignment preview per audience.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly ComplianceRollupService $rollup,
		private readonly RegulationAssignmentService $assignment,
		private readonly ObjectService $objectService,
		private readonly RegulationCoverageService $coverage,
		private readonly RegulationAssignPreview $preview,
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

		$scope = $this->audienceOf(kind: $audienceKind, value: $audience);
		if ($scope === false || ($dueDate !== '' && $this->isDate(value: $dueDate) === false)) {
			return new JSONResponse(data: ['error' => 'Unknown audience or due date'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$due = null;
		if ($dueDate !== '') {
			$due = $dueDate;
		}

		return new JSONResponse(data: $this->assignment->assign(regulation: $regulation, audience: $scope, dueDate: $due));
	}//end assignRegulation()

	/**
	 * What assigning a published regulation to one audience would do; writes nothing.
	 *
	 * @param string $id           Regulation UUID.
	 * @param string $audienceKind section, team or hr-function; empty for the audience lists only.
	 * @param string $audience     The audience value within that kind.
	 *
	 * @return JSONResponse { courseName, audiences, total?, alreadyEnrolled?, toEnrol?, rows? }, or an error.
	 *
	 * @spec openspec/changes/regulation-assign-by-audience/specs/compliance-rule-coverage/spec.md#requirement-the-assignment-is-previewed-per-audience
	 */
	#[NoAdminRequired]
	public function assignPreview(string $id='', string $audienceKind='', string $audience=''): JSONResponse {
		$regulation = $this->assignableRegulation(id: $id);
		if ($regulation instanceof JSONResponse) {
			return $regulation;
		}

		$scope = $this->audienceOf(kind: $audienceKind, value: $audience);
		if ($scope === false) {
			return new JSONResponse(data: ['error' => 'Unknown audience'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $this->preview->preview(regulation: $regulation, audience: $scope));
	}//end assignPreview()

	/**
	 * The published regulation the caller may assign, or the error response.
	 *
	 * Checks the `regulation.assign` action before the regulation is read.
	 *
	 * @param string $id Regulation UUID.
	 *
	 * @return array<string,mixed>|JSONResponse
	 */
	private function assignableRegulation(string $id): array|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		// ADR-023: throws OCSForbiddenException (HTTP 403) when not allowed.
		$this->actionAuth->requireAction(user: $user, action: 'regulation.assign');

		$regulation = null;
		if ($id !== '') {
			$regulation = $this->regulation(id: $id);
		}

		if ($regulation === null) {
			return new JSONResponse(data: ['error' => 'Regulation not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$data = $regulation->jsonSerialize();
		if (($data['lifecycle'] ?? '') !== 'published') {
			return new JSONResponse(data: ['error' => 'Only a published regulation can be assigned'], statusCode: Http::STATUS_CONFLICT);
		}

		return $data;
	}//end assignableRegulation()

	/**
	 * The audience to narrow to: null for none, false when it is not valid.
	 *
	 * @param string $kind  The audience kind.
	 * @param string $value The audience value.
	 *
	 * @return array{kind: string, value: string}|false|null
	 */
	private function audienceOf(string $kind, string $value): array|false|null {
		$kind = trim($kind);
		$value = trim($value);
		if ($kind === '' && $value === '') {
			return null;
		}

		if (in_array($kind, RegulationAudienceResolver::AUDIENCE_KINDS, true) === false || $value === '') {
			return false;
		}

		return ['kind' => $kind, 'value' => $value];
	}//end audienceOf()

	/**
	 * Whether a value is a calendar date written Y-m-d.
	 *
	 * @param string $value The value.
	 *
	 * @return bool
	 */
	private function isDate(string $value): bool {
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

		return $date !== false && $date->format('Y-m-d') === $value;
	}//end isDate()

	/**
	 * The Regulation, read with the caller's rights, or null when it does not exist.
	 *
	 * @param string $id Regulation UUID.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity|null
	 *
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException When the id matches more than one object; LookupIntegrityMiddleware answers it.
	 */
	private function regulation(string $id): ?\OCA\OpenRegister\Db\ObjectEntity {
		return $this->findOrNull(find: fn () => $this->objectService->find(id: $id, register: 'learniq', schema: 'regulation'));
	}//end regulation()
}//end class
