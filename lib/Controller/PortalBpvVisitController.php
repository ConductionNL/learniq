<?php

/**
 * Learniq PortalBpvVisitController
 *
 * Receives portaliq's forward when a workplace trainer accepts or answers a
 * visit date the school coach proposed. The signed `X-Portal-Subject`
 * assertion is the only credential: the trainer is the subject it names,
 * never a value the form sent.
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
 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-school-coach-plans-visits-and-the-company-agrees-the-date
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Learniq\Kernel\Contracts\PortalVisitAnswerInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Receives the trainer's answer to a proposed visit date.
 *
 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-school-coach-plans-visits-and-the-company-agrees-the-date
 */
class PortalBpvVisitController extends Controller {

	/**
	 * The only audience this endpoint serves.
	 */
	private const AUDIENCE = 'praktijkopleider';

	/**
	 * The brute-force bucket for rejected assertions, shared with the other
	 * portal receivers.
	 */
	private const THROTTLE_ACTION = 'learniq_portal_assertion';

	/**
	 * The fields the form may send, as the action whitelists them.
	 *
	 * @var array<int, string>
	 */
	private const FIELDS = [
		'bpvVisitId',
		'outcome',
		'trainerResponse',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest                   $request     The request.
	 * @param PortalAssertionVerifierInterface    $verifier    Verifies X-Portal-Subject.
	 * @param PortalVisitAnswerInterface $answers Checks and stores the answer.
	 * @param LoggerInterface            $logger      PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifierInterface $verifier,
		private readonly PortalVisitAnswerInterface $answers,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Store the trainer's answer to a proposed visit date.
	 *
	 * @return JSONResponse 200 `{bpvVisitId, lifecycle, plannedAt}`, or 401 / 403 / 404 / 409 / 422 / 502.
	 *
	 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-school-coach-plans-visits-and-the-company-agrees-the-date
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function answer(): JSONResponse {
		$claims = $this->verifier->verify(jwt: (string)$this->request->getHeader(PortalAssertionVerifierInterface::HEADER));
		if ($claims === null) {
			$response = new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => self::THROTTLE_ACTION]);
			return $response;
		}

		// The trainer is the subject portaliq resolved, never a value the form
		// sent: `practicalTrainerId` is the scope claim the action declares.
		$trainerRef = $this->stringParam(name: 'practicalTrainerId');
		if (($claims['audience'] ?? '') !== self::AUDIENCE || $trainerRef === '') {
			return new JSONResponse(data: ['error' => 'forbidden'], statusCode: Http::STATUS_FORBIDDEN);
		}

		try {
			$outcome = $this->answers->answer(trainerRef: $trainerRef, body: $this->body());
		} catch (Throwable $exception) {
			// Never leak internals from a public route (ADR-005).
			$this->logger->error(
				'[PortalBpvVisitController] A portal visit answer failed: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new JSONResponse(data: ['error' => 'downstream_error'], statusCode: Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(data: $outcome->body, statusCode: $outcome->status);
	}//end answer()

	/**
	 * The whitelisted fields the form sent.
	 *
	 * @return array<string, mixed>
	 */
	private function body(): array {
		$body = [];
		foreach (self::FIELDS as $field) {
			$value = $this->request->getParam($field);
			if ($value !== null) {
				$body[$field] = $value;
			}
		}

		return $body;
	}//end body()

	/**
	 * A request parameter as a trimmed string, '' when absent or not a string.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string
	 */
	private function stringParam(string $name): string {
		$value = $this->request->getParam($name);
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end stringParam()
}//end class
