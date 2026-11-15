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
 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\CatalogueMessagesInterface;
use OCA\Learniq\Kernel\Contracts\CatalogueSignUpServiceInterface;
use OCA\Learniq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Learniq\Kernel\Contracts\PortalLearnerResolverInterface;
use OCA\Learniq\Portal\PortalForwardFailure;
use OCA\Learniq\Portal\PortalForwardGate;
use OCA\Learniq\Portal\PortalLearnerForward;
use OCA\Learniq\Portal\PortalLearnerStep;
use OCA\Learniq\Service\Portal\PortalLearner;
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
 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
 */
class PortalCatalogueController extends Controller {

	/**
	 * The brute-force bucket for rejected assertions, shared with the other receivers.
	 */
	private const THROTTLE_ACTION = 'learniq_portal_assertion';

	/**
	 * Constructor.
	 *
	 * @param IRequest                $request  The request.
	 * @param PortalAssertionVerifierInterface $verifier Verifies X-Portal-Subject.
	 * @param PortalLearnerResolverInterface   $learners learnerRef to the pupil.
	 * @param CatalogueSignUpServiceInterface  $signUps  The catalogue, sign-up and withdraw rules.
	 * @param CatalogueMessagesInterface       $messages Plain refusal reasons.
	 * @param LoggerInterface         $logger   PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifierInterface $verifier,
		private readonly PortalLearnerResolverInterface $learners,
		private readonly CatalogueSignUpServiceInterface $signUps,
		private readonly CatalogueMessagesInterface $messages,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The catalogue for the pupil. Body: optional `search`.
	 *
	 * @return JSONResponse 200 `{courses, programmes}`, or 401 / 403 / 502.
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
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
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
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
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-withdraws-their-own-sign-up
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
	 * A request parameter as a trimmed string, '' when absent or not a string.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string
	 */
	private function param(string $name): string {
		return PortalForwardGate::stringParam(request: $this->request, name: $name);
	}//end param()

	/**
	 * The receiver order around one step.
	 *
	 * @param callable(PortalLearner): PortalOutcome $step The step for the resolved pupil.
	 *
	 * @return JSONResponse
	 */
	private function receive(callable $step): JSONResponse {
		$failure = new PortalForwardFailure(logger: $this->logger, message: '[PortalCatalogueController] A portal catalogue step failed: {msg}');
		$forward = new PortalLearnerForward(gate: new PortalForwardGate(verifier: $this->verifier, request: $this->request), learners: $this->learners);

		return $forward->respond(step: new PortalLearnerStep(run: $step(...), messages: $this->messages->message(...)), failure: $failure);
	}//end receive()
}//end class
