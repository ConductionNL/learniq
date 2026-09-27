<?php

/**
 * Learniq Portal Assessment Controller
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
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Service\Portal\PortalAttemptService;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalLearnerResolver;
use OCA\Learniq\Service\Portal\PortalMessages;
use OCA\Learniq\Service\Portal\PortalResultReader;
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
 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */
class PortalAssessmentController extends Controller {

	/**
	 * The only audience these endpoints serve.
	 */
	private const AUDIENCE = 'student';

	/**
	 * The brute-force bucket for rejected assertions.
	 */
	private const THROTTLE_ACTION = 'learniq_portal_assertion';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalAssertionVerifier $verifier Verifies X-Portal-Subject.
	 * @param PortalLearnerResolver $learners learnerRef to the pupil.
	 * @param PortalAttemptService $attempts available, start, answer, submit.
	 * @param PortalResultReader $results result.
	 * @param PortalMessages $messages Pupil-facing refusal messages.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifier $verifier,
		private readonly PortalLearnerResolver $learners,
		private readonly PortalAttemptService $attempts,
		private readonly PortalResultReader $results,
		private readonly PortalMessages $messages,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The tests the pupil may start or continue.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
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
	 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
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
	 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
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
	 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
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
	 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-a-portal-result-is-shown-only-once-the-teacher-released-it
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
	 * Verify, resolve the pupil, run the step, answer.
	 *
	 * @param callable(PortalLearner): \OCA\Learniq\Service\Portal\PortalOutcome $step The step.
	 *
	 * @return JSONResponse
	 */
	private function respond(callable $step): JSONResponse {
		$claims = $this->verifier->verify(jwt: (string)$this->request->getHeader(PortalAssertionVerifier::HEADER));
		if ($claims === null) {
			$response = new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => self::THROTTLE_ACTION]);
			return $response;
		}

		$learnerRef = $this->stringParam(name: 'learnerRef');
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
				'[PortalAssessmentController] A portal assessment step failed: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new JSONResponse(data: ['error' => 'downstream_error'], statusCode: Http::STATUS_BAD_GATEWAY);
		}//end try

		return new JSONResponse(data: $body, statusCode: $outcome->status);
	}//end respond()

	/**
	 * A request parameter as a string, '' when absent or not a string.
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
