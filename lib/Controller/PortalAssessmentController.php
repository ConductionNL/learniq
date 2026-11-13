<?php

/**
 * Learniq Portal Assessment Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The receiving end of portaliq's timed task (ConductionNL/portaliq#749): five
 * server-to-server forwards (`available`, `start`, `answer`, `submit`,
 * `result`) through which a pupil takes a test in the portal.
 *
 * Authentication is the `X-Portal-Subject` assertion and nothing else. The
 * forward carries no Nextcloud credentials, so Nextcloud's security
 * middleware would refuse it before this controller unless the methods are
 * `#[PublicPage]`; that attribute only lifts the session requirement. Every
 * method verifies the assertion first, with no session fallback, exactly as
 * filinq's PortalSigningReceiverController and shillinq's
 * PortalPaymentInitiationController do. A failed verification is throttled.
 *
 * Order: verify (401) -> audience `student` (403) -> `learnerRef` in the body,
 * stamped by portaliq from the subject's own account (403) -> the pupil's
 * profile and Nextcloud account (403 `not_available`) -> the step. Every rule
 * of the step is enforced in the services; writes run as the pupil. A
 * downstream failure answers 502 without internals.
 *
 * Rate limits are per route and generous: every forward arrives from
 * portaliq's own address, so a whole class shares one limit.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\PortalAttemptServiceInterface;
use OCA\Learniq\Kernel\Contracts\PortalLearnerResolverInterface;
use OCA\Learniq\Kernel\Contracts\PortalMessagesInterface;
use OCA\Learniq\Kernel\Contracts\PortalResultReaderInterface;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Portal\PortalForwardFailure;
use OCA\Learniq\Portal\PortalForwardGate;
use OCA\Learniq\Portal\PortalLearnerForward;
use OCA\Learniq\Portal\PortalLearnerStep;
use OCA\Learniq\Service\Portal\PortalLearner;
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
 * Receives portaliq's timed-task forwards for one pupil.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */
class PortalAssessmentController extends Controller {

	/**
	 * The brute-force bucket for rejected assertions.
	 */
	private const THROTTLE_ACTION = 'learniq_portal_assertion';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalAssertionVerifier $verifier Verifies X-Portal-Subject.
	 * @param PortalLearnerResolverInterface $learners learnerRef to the pupil.
	 * @param PortalAttemptServiceInterface $attempts available, start, answer, submit.
	 * @param PortalResultReaderInterface $results result.
	 * @param PortalMessagesInterface $messages Pupil-facing refusal messages.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifier $verifier,
		private readonly PortalLearnerResolverInterface $learners,
		private readonly PortalAttemptServiceInterface $attempts,
		private readonly PortalResultReaderInterface $results,
		private readonly PortalMessagesInterface $messages,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The tests the pupil may start or continue.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function available(): JSONResponse {
		return $this->respond(step: fn (PortalLearner $learner) => $this->attempts->available(learner: $learner));
	}//end available()

	/**
	 * Start or resume an attempt.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function start(): JSONResponse {
		return $this->respond(
			step: fn (PortalLearner $learner) => $this->attempts->start(
				learner: $learner,
				taskId: $this->stringParam(name: 'taskId'),
				accessCode: $this->request->getParam('accessCode')
			)
		);
	}//end start()

	/**
	 * Save the answer to one question.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 3000, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function answer(): JSONResponse {
		return $this->respond(
			step: fn (PortalLearner $learner) => $this->attempts->answer(
				learner: $learner,
				attemptId: $this->stringParam(name: 'attemptId'),
				itemId: $this->stringParam(name: 'itemId'),
				response: $this->request->getParam('response')
			)
		);
	}//end answer()

	/**
	 * Hand the attempt in.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function submit(): JSONResponse {
		return $this->respond(
			step: fn (PortalLearner $learner) => $this->attempts->submit(learner: $learner, attemptId: $this->stringParam(name: 'attemptId'))
		);
	}//end submit()

	/**
	 * The released result of one attempt.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-result-is-shown-only-once-the-teacher-released-it
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function result(): JSONResponse {
		return $this->respond(
			step: fn (PortalLearner $learner) => $this->results->result(learner: $learner, attemptId: $this->stringParam(name: 'attemptId'))
		);
	}//end result()

	/**
	 * A request parameter as a string, '' when absent or not a string.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string
	 */
	private function stringParam(string $name): string {
		return PortalForwardGate::stringParam(request: $this->request, name: $name);
	}//end stringParam()

	/**
	 * Verify, resolve the pupil, run the step, answer.
	 *
	 * @param callable(PortalLearner): \OCA\Learniq\Service\Portal\PortalOutcome $step The step.
	 *
	 * @return JSONResponse
	 */
	private function respond(callable $step): JSONResponse {
		$failure = new PortalForwardFailure(logger: $this->logger, message: '[PortalAssessmentController] A portal assessment step failed: {msg}');
		$forward = new PortalLearnerForward(gate: new PortalForwardGate(verifier: $this->verifier, request: $this->request), learners: $this->learners);

		return $forward->respond(step: new PortalLearnerStep(run: $step(...), messages: $this->messages->message(...)), failure: $failure);
	}//end respond()
}//end class
