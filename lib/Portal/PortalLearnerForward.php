<?php

/**
 * Learniq pupil-facing portal forward.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
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
 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use OCA\Learniq\Kernel\Contracts\PortalLearnerResolverInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Throwable;

/**
 * Receives a portaliq forward for one pupil: admit the student assertion
 * and its learnerRef, resolve the pupil, run the step, and relay its outcome
 * with the pupil's message. Any failure past the gate is a bare 502.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */
final class PortalLearnerForward {

	/**
	 * The only audience a pupil-facing receiver serves.
	 */
	private const AUDIENCE = 'student';

	/**
	 * Constructor.
	 *
	 * @param PortalForwardGate     $gate     Admits the forward.
	 * @param PortalLearnerResolverInterface $learners learnerRef to the pupil.
	 */
	public function __construct(
		private readonly PortalForwardGate $gate,
		private readonly PortalLearnerResolverInterface $learners,
	) {
	}//end __construct()

	/**
	 * The response to the forward.
	 *
	 * @param PortalLearnerStep    $step    What to do for the pupil.
	 * @param PortalForwardFailure $failure How a failure is logged and answered.
	 *
	 * @return JSONResponse 200 or the step's status, or 401 / 403 / 502.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
	 */
	public function respond(PortalLearnerStep $step, PortalForwardFailure $failure): JSONResponse {
		$admission = $this->gate->admit(audience: self::AUDIENCE, subjectParam: 'learnerRef');
		if ($admission instanceof JSONResponse) {
			return $admission;
		}

		try {
			return $this->relay(learnerRef: $admission->subject, step: $step);
		} catch (Throwable $exception) {
			return $failure->response(exception: $exception);
		}
	}//end respond()

	/**
	 * Resolve the pupil, run the step, and relay its outcome; a pupil
	 * without an account is not available.
	 *
	 * @param string            $learnerRef The forwarded learnerRef.
	 * @param PortalLearnerStep $step       What to do for the pupil.
	 *
	 * @return JSONResponse
	 */
	private function relay(string $learnerRef, PortalLearnerStep $step): JSONResponse {
		$learner = $this->learners->resolve(learnerRef: $learnerRef);
		if ($learner === null) {
			return new JSONResponse(
				data: ['error' => 'not_available', 'message' => $step->message(reason: 'no-account', user: null)],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		$outcome = $step->run(learner: $learner);
		$body = $outcome->body;
		if ($outcome->reason !== null) {
			$body['message'] = $step->message(reason: $outcome->reason, user: $learner->user);
		}

		return new JSONResponse(data: $body, statusCode: $outcome->status);
	}//end relay()
}//end class
