<?php

/**
 * Learniq Portal Work Group Controller
 *
 * The receiving end of portaliq's work group forwards
 * (enrolment-self-join-work-group): a pupil lists the work groups of their
 * classes, joins or moves to a group, or leaves one, from the portal. Same receiver pattern as PortalSubmissionController (learniq #1096
 * and #1142): the `X-Portal-Subject` assertion is the only credential, the
 * methods are `#[PublicPage]` only because the forward carries no Nextcloud
 * session, and there is no session fallback.
 *
 * Order: verify (401, throttled) -> audience `student` (403) -> `learnerRef`
 * stamped by portaliq (403) -> the pupil's profile and account (403
 * `not_available`) -> WorkGroupMembershipService, which enforces
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
 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalLearnerResolver;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCA\Learniq\Service\WorkGroup\WorkGroupMembershipService;
use OCA\Learniq\Service\WorkGroup\WorkGroupMessages;
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
 * Receives portaliq's work group forwards for one pupil.
 *
 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
 */
class PortalWorkGroupController extends Controller {

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
	 * @param WorkGroupMembershipService $memberships The overview and the join, move and leave rules.
	 * @param WorkGroupMessages          $messages    Plain refusal reasons.
	 * @param LoggerInterface         $logger   PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifier $verifier,
		private readonly PortalLearnerResolver $learners,
		private readonly WorkGroupMembershipService $memberships,
		private readonly WorkGroupMessages $messages,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The work groups of the pupil's classes.
	 *
	 * @return JSONResponse 200 `{sets}`, or 401 / 403 / 502.
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function mine(): JSONResponse {
		return $this->receive(
			step: fn (PortalLearner $learner): PortalOutcome => $this->memberships->mine(learner: $learner)
		);
	}//end mine()

	/**
	 * Join (or move to) a work group. Body: `groupId`.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function join(): JSONResponse {
		return $this->receive(
			step: fn (PortalLearner $learner): PortalOutcome => $this->memberships->join(learner: $learner, groupId: $this->param(name: 'groupId'))
		);
	}//end join()

	/**
	 * Leave a work group. Body: `groupId`.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function leave(): JSONResponse {
		return $this->receive(
			step: fn (PortalLearner $learner): PortalOutcome => $this->memberships->leave(learner: $learner, groupId: $this->param(name: 'groupId'))
		);
	}//end leave()

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
				'[PortalWorkGroupController] A portal work group step failed: {msg}',
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
