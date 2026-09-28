<?php

/**
 * Learniq Portal Catalogue Controller
 *
 * The receiving end of portaliq's catalogue forwards
 * (enrolment-catalogue-self-signup): a pupil lists the course catalogue,
 * signs up for a course or programme, or withdraws an own sign-up from the
 * portal. Same receiver pattern as PortalSubmissionController (learniq #1096
 * and #1142): the `X-Portal-Subject` assertion is the only credential, the
 * methods are `#[PublicPage]` only because the forward carries no Nextcloud
 * session, and there is no session fallback.
 *
 * Order: verify (401, throttled) -> audience `student` (403) -> `learnerRef`
 * stamped by portaliq (403) -> the pupil's profile and account (403
 * `not_available`) -> CatalogueSignUpService, which enforces
 * every rule and writes as the pupil. A downstream failure answers 502.
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
 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Service\Catalogue\CatalogueMessages;
use OCA\Learniq\Service\Catalogue\CatalogueSignUpService;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalLearnerResolver;
use OCA\Learniq\Service\Portal\PortalOutcome;
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
 * Receives portaliq's catalogue forwards for one pupil.
 *
 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
 */
class PortalCatalogueController extends Controller {

	/**
	 * The only audience these endpoints serve.
	 */
	private const AUDIENCE = 'student';

	/**
	 * The brute-force bucket for rejected assertions, shared with the other receivers.
	 */
	private const THROTTLE_ACTION = 'learniq_portal_assertion';

	/**
	 * Constructor.
	 *
	 * @param IRequest                $request  The request.
	 * @param PortalAssertionVerifier $verifier Verifies X-Portal-Subject.
	 * @param PortalLearnerResolver   $learners learnerRef to the pupil.
	 * @param CatalogueSignUpService  $signUps  The catalogue, sign-up and withdraw rules.
	 * @param CatalogueMessages       $messages Plain refusal reasons.
	 * @param LoggerInterface         $logger   PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifier $verifier,
		private readonly PortalLearnerResolver $learners,
		private readonly CatalogueSignUpService $signUps,
		private readonly CatalogueMessages $messages,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The catalogue for the pupil. Body: optional `search`.
	 *
	 * @return JSONResponse 200 `{courses, programmes}`, or 401 / 403 / 502.
	 *
	 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function catalogue(): JSONResponse {
		return $this->receive(
			step: fn (PortalLearner $learner): PortalOutcome => $this->signUps->catalogue(learner: $learner, search: $this->param(name: 'search'))
		);
	}//end catalogue()

	/**
	 * Sign the pupil up. Body: `courseId`, or `programmeId`.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function signUp(): JSONResponse {
		return $this->receive(step: fn (PortalLearner $learner): PortalOutcome => $this->signUpFor(learner: $learner));
	}//end signUp()

	/**
	 * Withdraw the pupil's own sign-up. Body: `enrolmentId`.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-withdraws-their-own-sign-up
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function withdraw(): JSONResponse {
		return $this->receive(
			step: fn (PortalLearner $learner): PortalOutcome => $this->signUps->withdraw(learner: $learner, enrolmentId: $this->param(name: 'enrolmentId'))
		);
	}//end withdraw()

	/**
	 * A course sign-up, or a programme sign-up when the body names one.
	 *
	 * @param PortalLearner $learner The pupil.
	 *
	 * @return PortalOutcome
	 */
	private function signUpFor(PortalLearner $learner): PortalOutcome {
		$programmeId = $this->param(name: 'programmeId');
		if ($programmeId !== '') {
			return $this->signUps->signUpProgramme(learner: $learner, programmeId: $programmeId);
		}

		return $this->signUps->signUpCourse(learner: $learner, courseId: $this->param(name: 'courseId'));
	}//end signUpFor()

	/**
	 * The receiver order around one step.
	 *
	 * @param callable(PortalLearner): PortalOutcome $step The step for the resolved pupil.
	 *
	 * @return JSONResponse
	 */
	private function receive(callable $step): JSONResponse {
		$claims = $this->verifier->verify(jwt: (string)$this->request->getHeader(PortalAssertionVerifier::HEADER));
		if ($claims === null) {
			$response = new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => self::THROTTLE_ACTION]);
			return $response;
		}

		$learnerRef = $this->param(name: 'learnerRef');
		if (($claims['audience'] ?? '') !== self::AUDIENCE || $learnerRef === '') {
			return new JSONResponse(data: ['error' => 'forbidden'], statusCode: Http::STATUS_FORBIDDEN);
		}

		try {
			$learner = $this->learners->resolve(learnerRef: $learnerRef);
			if ($learner === null) {
				return new JSONResponse(
					data: ['error' => 'not_available', 'message' => $this->messages->message(reason: 'no-account', user: null)],
					statusCode: Http::STATUS_FORBIDDEN
				);
			}

			$outcome = $step($learner);
			$body = $outcome->body;
			if ($outcome->reason !== null) {
				$body['message'] = $this->messages->message(reason: $outcome->reason, user: $learner->user);
			}
		} catch (Throwable $exception) {
			// Never leak internals from a public route (ADR-005).
			$this->logger->error(
				'[PortalCatalogueController] A portal catalogue step failed: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new JSONResponse(data: ['error' => 'downstream_error'], statusCode: Http::STATUS_BAD_GATEWAY);
		}//end try

		return new JSONResponse(data: $body, statusCode: $outcome->status);
	}//end receive()

	/**
	 * A request parameter as a trimmed string, '' when absent or not a string.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string
	 */
	private function param(string $name): string {
		$value = $this->request->getParam($name);
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end param()
}//end class
