<?php

/**
 * Learniq Exchange Gate Controller
 *
 * Serves learniq's exchange gate decision for one integriq job to people:
 * `GET /api/exchange-gates/{jobId}`, the HTTP binding of the gate contract.
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-serves-its-gate-decision-over-http-for-people
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\ExchangeGateService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Why an integriq job owned by learniq may or may not run, for people.
 *
 * The same decision the gate event gets, without the records. Integriq itself
 * never calls this route: a scheduled run has no session (ADR-041).
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-serves-its-gate-decision-over-http-for-people
 */
class ExchangeGateController extends Controller {

	private const INTEGRIQ_REGISTER = 'integriq';
	private const INTEGRIQ_JOB_SCHEMA = 'job';
	private const OWNER_APP = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param IRequest            $request       HTTP request.
	 * @param IUserSession        $userSession   Current user session.
	 * @param ActionAuthService   $actionAuth    ADR-023 action matrix.
	 * @param ObjectService       $objectService Reads integriq's job row.
	 * @param ExchangeGateService $gate          Decides for the job.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly ObjectService $objectService,
		private readonly ExchangeGateService $gate,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The gate decision for one integriq job owned by learniq.
	 *
	 * @param string $jobId The integriq job's uuid.
	 *
	 * @return JSONResponse `{jobId, decision, code, reason, checkedAt}`, or 401/403/404.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-serves-its-gate-decision-over-http-for-people
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $jobId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: 'exchange.gate-read');
		} catch (OCSForbiddenException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		$job = $this->ownedJob(jobId: $jobId);
		if ($job === null) {
			return new JSONResponse(data: ['error' => 'No exchange job of learniq with that id'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$scope = $job['exchangeScope'] ?? [];
		if (is_array($scope) === false) {
			$scope = [];
		}

		$decision = $this->gate->evaluate(
			jobId: $jobId,
			target: (string)($job['exchangeTarget'] ?? ''),
			direction: (string)($job['exchangeDirection'] ?? ''),
			ownerRef: (string)($job['ownerRef'] ?? ''),
			scope: $scope
		);

		return new JSONResponse(
			data: [
				'jobId' => $jobId,
				'decision' => $decision['decision'],
				'code' => $decision['code'],
				'reason' => $decision['reason'],
				'checkedAt' => $decision['checkedAt'],
			]
		);
	}//end show()

	/**
	 * Integriq's job row, when it exists and learniq owns it.
	 *
	 * @param string $jobId The job's uuid.
	 *
	 * @return array<string, mixed>|null The job data, or null.
	 */
	private function ownedJob(string $jobId): ?array {
		try {
			$job = $this->objectService->find(
				id: $jobId,
				register: self::INTEGRIQ_REGISTER,
				schema: self::INTEGRIQ_JOB_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			unset($exception);
			return null;
		}

		if ($job === null) {
			return null;
		}

		$data = (array)$job->jsonSerialize();
		if (($data['ownerApp'] ?? '') !== self::OWNER_APP) {
			return null;
		}

		return $data;
	}//end ownedJob()
}//end class
