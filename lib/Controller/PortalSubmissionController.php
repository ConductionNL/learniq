<?php

/**
 * Learniq Portal Submission Controller
 *
 * The receiving end of portaliq's `handIn` forward
 * (portal-assignment-hand-in-endpoint): a pupil hands in a draft submission
 * made in the portal. Same receiver pattern as PortalAssessmentController
 * (learniq #1096): the `X-Portal-Subject` assertion is the only credential,
 * the methods are `#[PublicPage]` only because the forward carries no
 * Nextcloud session, and there is no session fallback.
 *
 * Order: verify (401, throttled) -> audience `student` (403) -> `learnerRef`
 * in the body, stamped by portaliq from the subject's own account (403) ->
 * the pupil's profile and Nextcloud account (403 `not_available`) -> the
 * hand-in. Every rule of the hand-in is enforced in PortalSubmissionHandIn and
 * SubmissionWindowGuard; the write runs as the pupil. A downstream failure
 * answers 502 without internals.
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
 * @spec openspec/specs/assignments/spec.md#requirement-a-pupil-hands-in-a-portal-draft-through-learniqs-own-endpoint
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Portal\PortalForwardFailure;
use OCA\Learniq\Portal\PortalForwardGate;
use OCA\Learniq\Portal\PortalLearnerForward;
use OCA\Learniq\Portal\PortalLearnerStep;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalLearnerResolver;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCA\Learniq\Service\Portal\PortalMessages;
use OCA\Learniq\Service\Portal\PortalSubmissionHandIn;
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
 * Receives portaliq's hand-in forward for one pupil.
 *
 * @spec openspec/specs/assignments/spec.md#requirement-a-pupil-hands-in-a-portal-draft-through-learniqs-own-endpoint
 */
class PortalSubmissionController extends Controller {

	/**
	 * The brute-force bucket for rejected assertions, shared with the test receiver.
	 */
	private const THROTTLE_ACTION = 'learniq_portal_assertion';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalAssertionVerifier $verifier Verifies X-Portal-Subject.
	 * @param PortalLearnerResolver $learners learnerRef to the pupil.
	 * @param PortalSubmissionHandIn $handIns The hand-in rules and the transition.
	 * @param PortalMessages $messages Pupil-facing refusal messages.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifier $verifier,
		private readonly PortalLearnerResolver $learners,
		private readonly PortalSubmissionHandIn $handIns,
		private readonly PortalMessages $messages,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Hand in the pupil's draft submission named by `submissionId`.
	 *
	 * Rate limit: generous, as on the test receiver, because every forward
	 * arrives from portaliq's own address and a whole class shares the limit.
	 *
	 * @return JSONResponse 200 `{submissionId, lifecycle}`, or 401 / 403 / 404 / 409 / 422 / 502.
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-a-pupil-hands-in-a-portal-draft-through-learniqs-own-endpoint
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function handIn(): JSONResponse {
		return $this->respond(
			step: fn (PortalLearner $learner): PortalOutcome => $this->handIns->handIn(
				learner: $learner,
				submissionId: $this->stringParam(name: 'submissionId')
			)
		);
	}//end handIn()

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
	 * Receive the forward for one pupil and run the step for them: the
	 * shared portal flow (admit, resolve the pupil, relay the outcome, 502 on
	 * any failure).
	 *
	 * @param callable(PortalLearner):PortalOutcome $step The step for the resolved pupil.
	 *
	 * @return JSONResponse
	 */
	private function respond(callable $step): JSONResponse {
		$failure = new PortalForwardFailure(logger: $this->logger, message: '[PortalSubmissionController] A portal hand-in failed: {msg}');
		$forward = new PortalLearnerForward(gate: new PortalForwardGate(verifier: $this->verifier, request: $this->request), learners: $this->learners);

		return $forward->respond(step: new PortalLearnerStep(run: $step(...), messages: $this->messages->message(...)), failure: $failure);
	}//end respond()
}//end class
