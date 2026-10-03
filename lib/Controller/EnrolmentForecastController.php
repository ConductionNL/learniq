<?php

/**
 * Learniq Enrolment Forecast Controller
 *
 * `POST /api/enrolment-forecasts/{id}/compute`: compute a scenario and store
 * the result on it, for team leads and compliance officers behind the ADR-023
 * action `report.enrolment-forecast`. The scenario is read and written as the
 * caller, so its own authorization also applies.
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
 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use InvalidArgumentException;
use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\EnrolmentForecastService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Compute a forecast scenario.
 *
 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
 */
class EnrolmentForecastController extends Controller {

	public const ACTION = 'report.enrolment-forecast';
	private const REGISTER = 'learniq';
	private const SCHEMA = 'enrolment-forecast';

	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request       HTTP request.
	 * @param IUserSession             $userSession   Current user session.
	 * @param ActionAuthService        $actionAuth    ADR-023 action matrix.
	 * @param EnrolmentForecastService $forecasts     Computes the forecast.
	 * @param ObjectService            $objectService Reads and writes the scenario as the caller.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly EnrolmentForecastService $forecasts,
		private readonly ObjectService $objectService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Compute the scenario and store its result.
	 *
	 * @param string $id The scenario.
	 *
	 * @return JSONResponse The result, or 400/401/403/404.
	 *
	 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
	 */
	#[NoAdminRequired]
	public function compute(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: self::ACTION);
		} catch (OCSForbiddenException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		$forecast = $this->load(id: $id);
		if ($forecast === null) {
			return new JSONResponse(data: ['error' => 'This scenario cannot be found, or you cannot see it.'], statusCode: Http::STATUS_NOT_FOUND);
		}

		try {
			$result = $this->forecasts->compute(forecast: $forecast);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$forecast['result'] = $result;
		$this->objectService->saveObject(object: $forecast, register: self::REGISTER, schema: self::SCHEMA);

		return new JSONResponse(data: $result);
	}//end compute()

	/**
	 * The scenario, read as the caller.
	 *
	 * @param string $id The scenario.
	 *
	 * @return array<string, mixed>|null
	 */
	private function load(string $id): ?array {
		try {
			$row = $this->objectService->find(id: $id, register: self::REGISTER, schema: self::SCHEMA);
		} catch (Throwable $exception) {
			unset($exception);
			return null;
		}

		if ($row === null) {
			return null;
		}

		$data = (array)$row->jsonSerialize();
		$data['id'] = (string)($data['id'] ?? $id);

		return $data;
	}//end load()
}//end class
