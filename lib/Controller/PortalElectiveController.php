<?php

/**
 * Learniq Portal Elective Controller
 *
 * The receiving end of portaliq's `signUpForElective` forward: a pupil signs
 * herself up from the portal for the next lesson of an open optional-lesson
 * offer, such as a support lesson. Same receiver pattern as
 * PortalCatalogueController: the `X-Portal-Subject` assertion is the only
 * credential, the method is `#[PublicPage]` only because the forward carries
 * no Nextcloud session, and there is no session fallback.
 *
 * Order: verify (401, throttled) -> audience `student` (403) -> `learnerRef`
 * stamped by portaliq (403) -> the pupil's profile and account (403
 * `not_available`) -> PortalElectiveSignUp, which writes as the pupil so the
 * sign-up rules apply. A downstream failure answers 502.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Learniq\Kernel\Contracts\PortalElectiveSignUpInterface;
use OCA\Learniq\Kernel\Contracts\PortalLearnerResolverInterface;
use OCA\Learniq\Portal\PortalForwardFailure;
use OCA\Learniq\Portal\PortalForwardGate;
use OCA\Learniq\Portal\PortalLearnerForward;
use OCA\Learniq\Portal\PortalLearnerStep;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Receives portaliq's optional-lesson sign-up forward for one pupil.
 *
 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
 */
class PortalElectiveController extends Controller {

	/**
	 * The brute-force bucket for rejected assertions, shared with the other receivers.
	 */
	private const THROTTLE_ACTION = 'learniq_portal_assertion';

	/**
	 * Constructor.
	 *
	 * @param IRequest                         $request  The request.
	 * @param PortalAssertionVerifierInterface $verifier Verifies X-Portal-Subject.
	 * @param PortalLearnerResolverInterface   $learners learnerRef to the pupil.
	 * @param PortalElectiveSignUpInterface    $signUps  The sign-up and its messages.
	 * @param LoggerInterface                  $logger   PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifierInterface $verifier,
		private readonly PortalLearnerResolverInterface $learners,
		private readonly PortalElectiveSignUpInterface $signUps,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Sign the pupil up for the next lesson of an offer. Body: `offerId`.
	 *
	 * @return JSONResponse 200 `{signUpId, offerId, startsAt}`, or 401 / 403 / 404 / 422 / 502.
	 *
	 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function signUp(): JSONResponse {
		$failure = new PortalForwardFailure(logger: $this->logger, message: '[PortalElectiveController] A portal sign-up for a lesson failed: {msg}');
		$forward = new PortalLearnerForward(gate: new PortalForwardGate(verifier: $this->verifier, request: $this->request), learners: $this->learners);
		$offerId = PortalForwardGate::stringParam(request: $this->request, name: 'offerId');
		$step = new PortalLearnerStep(
			run: fn (PortalLearner $learner): PortalOutcome => $this->signUps->signUp(learner: $learner, offerId: $offerId),
			messages: $this->signUps->message(...)
		);

		return $forward->respond(step: $step, failure: $failure);
	}//end signUp()
}//end class
