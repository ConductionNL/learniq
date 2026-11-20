<?php

/**
 * Learniq Portal Proof Of Enrolment Controller
 *
 * Receives portaliq's forward of `requestProofOfEnrolment` for one learner:
 * the assertion is verified, the learner resolved from it, and her proof of
 * enrolment for the current school year rendered and kept with her documents.
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
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Learniq\Kernel\Contracts\PortalLearnerResolverInterface;
use OCA\Learniq\Kernel\Contracts\ProofOfEnrolmentInterface;
use OCA\Learniq\Portal\PortalForwardFailure;
use OCA\Learniq\Portal\PortalForwardGate;
use OCA\Learniq\Portal\PortalLearnerForward;
use OCA\Learniq\Portal\PortalLearnerStep;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Receives portaliq's proof-of-enrolment forward for one learner.
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
 */
class PortalProofOfEnrolmentController extends Controller {

	/**
	 * The brute-force bucket for rejected assertions, shared with the other receivers.
	 */
	private const THROTTLE_ACTION = 'learniq_portal_assertion';

	/**
	 * Constructor.
	 *
	 * @param IRequest                         $request  The request.
	 * @param PortalAssertionVerifierInterface $verifier Verifies X-Portal-Subject.
	 * @param PortalLearnerResolverInterface   $learners learnerRef to the learner.
	 * @param ProofOfEnrolmentInterface        $proofs   Renders and keeps the proof.
	 * @param LoggerInterface                  $logger   PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifierInterface $verifier,
		private readonly PortalLearnerResolverInterface $learners,
		private readonly ProofOfEnrolmentInterface $proofs,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Render the learner's proof of enrolment for the current school year.
	 *
	 * Rate limit: a proof is asked for now and then, never in bulk.
	 *
	 * @return JSONResponse 201 `{status, title}`, or 401 / 403 / 409 / 502.
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function request(): JSONResponse {
		$failure = new PortalForwardFailure(logger: $this->logger, message: '[PortalProofOfEnrolmentController] A proof of enrolment failed: {msg}');
		$forward = new PortalLearnerForward(
			gate: new PortalForwardGate(verifier: $this->verifier, request: $this->request),
			learners: $this->learners
		);

		return $forward->respond(
			step: new PortalLearnerStep(
				run: fn (PortalLearner $learner) => $this->proofs->request(learner: $learner),
				messages: $this->proofs->message(...)
			),
			failure: $failure
		);
	}//end request()
}//end class
