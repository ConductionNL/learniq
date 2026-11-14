<?php

/**
 * Learniq Regulation Assign Preview Controller
 *
 * What assigning a published regulation to one audience would do, before it
 * is done (board LqCfGroepInschrijven). Authorized via the `regulation.assign`
 * action, like the assignment itself.
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
 * @spec openspec/changes/regulation-assign-by-audience/specs/compliance-rule-coverage/spec.md#requirement-the-assignment-is-previewed-per-audience
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\RegulationAssignmentServiceInterface;
use OCA\Learniq\Kernel\Contracts\RegulationAssignPreviewInterface;
use OCA\Learniq\Service\ActionAuthService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The preview of an audience-narrowed regulation assignment.
 */
class RegulationAssignPreviewController extends Controller {
	use AssignableRegulation;

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request       HTTP request.
	 * @param IUserSession                $userSession   Current user session.
	 * @param ActionAuthService           $actionAuth    ADR-023 action authorization.
	 * @param RegulationAssignmentServiceInterface $assignment    Reads the audience a request names.
	 * @param RegulationAssignPreviewInterface     $preview       The preview.
	 * @param ObjectService               $objectService OR object access.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly RegulationAssignmentServiceInterface $assignment,
		private readonly RegulationAssignPreviewInterface $preview,
		private readonly ObjectService $objectService,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

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
	public function preview(string $id='', string $audienceKind='', string $audience=''): JSONResponse {
		$regulation = $this->assignableRegulation(id: $id);
		if ($regulation instanceof JSONResponse) {
			return $regulation;
		}

		$scope = $this->assignment->audienceOf(kind: $audienceKind, value: $audience);
		if ($scope === false) {
			return new JSONResponse(data: ['error' => 'Unknown audience'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $this->preview->preview(regulation: $regulation, audience: $scope));
	}//end preview()
}//end class
