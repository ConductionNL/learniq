<?php

/**
 * Learniq Exchange Request Controller
 *
 * `POST /api/exchange/requests`: the export request screen asks integriq for
 * an exchange job through learniq, which picks the scope and the mapping.
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Exception\ExchangeRequestRefusedException;
use OCA\Learniq\Exception\IntegriqUnavailableException;
use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\IntegriqExchangeClientInterface;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CallerActions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * A person asks for one export (contract.md, `POST /apps/learniq/api/exchange/requests`).
 *
 * The body names a target and, optionally, a learner or a cohort; learniq
 * chooses the schema and the integriq mapping, so nobody can ask for fields a
 * mapping does not read. An OSO or SWV request for one learner opens the
 * parents' review, as the automatic flows do.
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
 */
class ExchangeRequestController extends Controller {

	private const LEARNIQ_REGISTER = 'learniq';
	private const DOSSIER_REVIEW_SCHEMA = 'dossier-review';

	/**
	 * Target to its learniq schema and integriq mapping.
	 *
	 * @var array<string, array{schema: string, mapping: string|null}>
	 */
	private const TARGETS = [
		'bron-rod' => ['schema' => 'learner-profile', 'mapping' => 'learniq-bron-rod-export-learner'],
		'oso' => ['schema' => 'learner-profile', 'mapping' => 'learniq-oso-export-dossier'],
		'leerplicht' => ['schema' => 'attendance-flag', 'mapping' => 'learniq-leerplicht-export-melding'],
		'swv' => ['schema' => 'support-request', 'mapping' => 'learniq-swv-export-zorgvraag'],
		'uwlr' => ['schema' => 'learner-profile', 'mapping' => 'learniq-uwlr-export-pupil'],
		'edu-v' => ['schema' => 'learner-profile', 'mapping' => 'learniq-edu-v-export-onderwijsdeelnemers'],
		'basispoort' => ['schema' => 'learner-profile', 'mapping' => 'learniq-basispoort-sync-learner'],
		'hr' => ['schema' => 'learner-profile', 'mapping' => null],
	];

	/**
	 * Targets whose file a parent reviews.
	 *
	 * @var array<int, string>
	 */
	private const REVIEWED_TARGETS = ['oso', 'swv'];

	/**
	 * Current user session.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * ADR-023 action matrix.
	 *
	 * @var ActionAuthService
	 */
	private readonly ActionAuthService $actionAuth;

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request       HTTP request.
	 * @param CallerActions $caller The user session and the ADR-023 action matrix.
	 * @param IntegriqExchangeClientInterface $integriq      Asks integriq for the job.
	 * @param ObjectService          $objectService Opens the parents' review.
	 */
	public function __construct(
		IRequest $request,
		CallerActions $caller,
		private readonly IntegriqExchangeClientInterface $integriq,
		private readonly ObjectService $objectService,
	) {
		$this->userSession = $caller->userSession;
		$this->actionAuth = $caller->actionAuth;
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Ask integriq for one exchange job.
	 *
	 * @return JSONResponse `{jobId}` (201), or 400/401/403/409/503.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
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

		$target = (string)$this->request->getParam('target', '');
		if (isset(self::TARGETS[$target]) === false) {
			return new JSONResponse(data: ['error' => 'Unknown or missing target'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$learnerId = trim((string)$this->request->getParam('learnerId', ''));
		$jobId = $this->requestExport(user: $user, request: ['target' => $target, 'scope' => $this->scopeFor(target: $target, learnerId: $learnerId)]);
		if ($jobId instanceof JSONResponse) {
			return $jobId;
		}

		$this->openDossierReview(jobId: $jobId, request: ['target' => $target, 'learnerId' => $learnerId]);

		return new JSONResponse(data: ['jobId' => $jobId], statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * The export scope: the target's schema, the learner filter and an optional cohort.
	 *
	 * @param string $target    The exchange target.
	 * @param string $learnerId The learner to export, or ''.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
	 */
	private function scopeFor(string $target, string $learnerId): array {
		$schema = self::TARGETS[$target]['schema'];
		$scope = ['schema' => $schema, 'filters' => $this->filters(schema: $schema, learnerId: $learnerId)];
		$cohortId = trim((string)$this->request->getParam('cohortId', ''));
		if ($cohortId !== '') {
			$scope['cohortId'] = $cohortId;
		}

		return $scope;
	}//end scopeFor()

	/**
	 * Ask integriq for the export job; the error response when it is unavailable or refuses.
	 *
	 * @param IUser                $user    The requesting user.
	 * @param array<string, mixed> $request target and scope.
	 *
	 * @return string|JSONResponse The job id, or the response to answer with.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
	 */
	private function requestExport(IUser $user, array $request): string|JSONResponse {
		$target = $request['target'];
		try {
			return $this->integriq->requestJob(
				target: $target,
				direction: 'export',
				ownerRef: 'user/' . $user->getUID(),
				scope: $request['scope'],
				mappingSlug: self::TARGETS[$target]['mapping'],
				requestedBy: $user->getUID(),
				name: 'Export ' . $target
			);
		} catch (IntegriqUnavailableException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (ExchangeRequestRefusedException $exception) {
			return new JSONResponse(
				data: ['code' => $exception->getRefusalCode(), 'reason' => $exception->getMessage()],
				statusCode: Http::STATUS_CONFLICT
			);
		}
	}//end requestExport()

	/**
	 * Open a pending dossier review for a reviewed target's per-learner export.
	 *
	 * @param string               $jobId   The export job.
	 * @param array<string, mixed> $request target and learnerId.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
	 */
	private function openDossierReview(string $jobId, array $request): void {
		if ($request['learnerId'] !== '' && in_array($request['target'], self::REVIEWED_TARGETS, true) === true) {
			$this->objectService->saveObject(
				register: self::LEARNIQ_REGISTER,
				schema: self::DOSSIER_REVIEW_SCHEMA,
				object: ['exchangeJobId' => $jobId, 'target' => $request['target'], 'learnerUserId' => $request['learnerId'], 'status' => 'pending']
			);
		}
	}//end openDossierReview()

	/**
	 * The scope filters for one learner, named as the schema names its field.
	 *
	 * @param string $schema    The learniq schema.
	 * @param string $learnerId The learner's account id, or empty for everyone.
	 *
	 * @return array<string, string> The filters.
	 */
	private function filters(string $schema, string $learnerId): array {
		if ($learnerId === '') {
			return [];
		}

		if ($schema === 'learner-profile') {
			return ['ncUserId' => $learnerId];
		}

		return ['learnerId' => $learnerId];
	}//end filters()
}//end class
