<?php

/**
 * Learniq Portal Check-in Controller
 *
 * The receiving end of portaliq's `checkIn` forward
 * (attendance-self-check-in): a pupil checks in to a lesson from the portal
 * with the code on the board. Same receiver pattern as
 * PortalSubmissionController (learniq #1096 and #1142): the `X-Portal-Subject`
 * assertion is the only credential, the method is `#[PublicPage]` only
 * because the forward carries no Nextcloud session, and there is no session
 * fallback.
 *
 * Order: verify (401, throttled) -> audience `student` (403) -> `learnerRef`
 * stamped by portaliq (403) -> the pupil's profile and Nextcloud account (403
 * `not_available`) -> CheckInService, which enforces every check-in rule and
 * writes as the pupil. A downstream failure answers 502 without internals.
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
 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Service\CheckIn\CheckInMessages;
use OCA\Learniq\Service\CheckIn\CheckInService;
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
 * Receives portaliq's check-in forward for one pupil.
 *
 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
 */
class PortalCheckInController extends Controller {

	/**
	 * The only audience this endpoint serves.
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
	 * @param CheckInService          $checkIns The check-in rules and write.
	 * @param CheckInMessages         $messages Plain refusal reasons.
	 * @param LoggerInterface         $logger   PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifier $verifier,
		private readonly PortalLearnerResolver $learners,
		private readonly CheckInService $checkIns,
		private readonly CheckInMessages $messages,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Check the pupil in with `code`, to the window named by `windowId`
	 * when the forward carries one, else to the open window of the pupil's
	 * own lessons that the code belongs to.
	 *
	 * Rate limit: generous, because every forward arrives from portaliq's own
	 * address and a whole class checks in within the same minute.
	 *
	 * @return JSONResponse 200 `{status, sessionId}`, or 401 / 403 / 404 / 409 / 422 / 502.
	 *
	 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function checkIn(): JSONResponse {
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

			$outcome = $this->run(learner: $learner, windowId: $this->stringParam(name: 'windowId'), code: $this->stringParam(name: 'code'));
			$body = $outcome->body;
			if ($outcome->reason !== null) {
				$body['message'] = $this->messages->message(reason: $outcome->reason, user: $learner->user);
			}
		} catch (Throwable $exception) {
			// Never leak internals from a public route (ADR-005).
			$this->logger->error(
				'[PortalCheckInController] A portal check-in failed: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new JSONResponse(data: ['error' => 'downstream_error'], statusCode: Http::STATUS_BAD_GATEWAY);
		}//end try

		return new JSONResponse(data: $body, statusCode: $outcome->status);
	}//end checkIn()

	/**
	 * The check-in to the named window, or by the code alone when the forward
	 * names none.
	 *
	 * @param PortalLearner $learner  The pupil.
	 * @param string        $windowId The window uuid, or ''.
	 * @param string        $code     The code the pupil typed.
	 *
	 * @return PortalOutcome
	 */
	private function run(PortalLearner $learner, string $windowId, string $code): PortalOutcome {
		if ($windowId === '') {
			return $this->checkIns->checkInWithCode(code: $code, userId: $learner->ncUserId, learnerRef: $learner->profileRef, runAs: $learner->user);
		}

		return $this->checkIns->checkIn(
			windowId: $windowId,
			code: $code,
			userId: $learner->ncUserId,
			learnerRef: $learner->profileRef,
			runAs: $learner->user
		);
	}//end run()

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
