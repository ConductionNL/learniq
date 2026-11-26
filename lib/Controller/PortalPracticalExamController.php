<?php

/**
 * Learniq PortalPracticalExamController
 *
 * The receiver of the external assessor's practical exam form: portaliq
 * forwards the form with the signed assertion, and the assertion names the
 * assessor and the trust of his session.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-assessment-records-who-assessed-and-how-sure-the-school-is
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Learniq\Kernel\Contracts\PortalExamSittingDocumentsInterface;
use OCA\Learniq\Kernel\Contracts\PortalPracticalExamAssessmentInterface;
use OCA\Learniq\Kernel\Contracts\PortalPracticalExamSigningInterface;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Receives the assessor's practical exam form from portaliq.
 *
 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-assessment-records-who-assessed-and-how-sure-the-school-is
 */
class PortalPracticalExamController extends Controller {

	use PortalFormFields;

	/**
	 * The only audience this endpoint serves.
	 */
	private const AUDIENCE = 'external-assessor';

	/**
	 * The brute-force bucket for rejected assertions, shared with the other portal receivers.
	 */
	private const THROTTLE_ACTION = 'learniq_portal_assertion';

	/**
	 * The fields the form may send, as the action whitelists them.
	 *
	 * @var array<int, string>
	 */
	private const FIELDS = ['examSittingId', 'learnerRef', 'assessmentModelId', 'criteria', 'outcome', 'lifecycle'];

	/**
	 * Constructor.
	 *
	 * @param IRequest                                $request     The request.
	 * @param PortalAssertionVerifierInterface        $verifier    Verifies X-Portal-Subject.
	 * @param PortalPracticalExamAssessmentInterface  $assessments Checks, stamps and stores the assessment.
	 * @param PortalPracticalExamSigningInterface     $signing     Checks and stores an assessor's signature.
	 * @param PortalExamSittingDocumentsInterface     $sittings    Reads an assigned sitting and its documents.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifierInterface $verifier,
		private readonly PortalPracticalExamAssessmentInterface $assessments,
		private readonly PortalPracticalExamSigningInterface $signing,
		private readonly PortalExamSittingDocumentsInterface $sittings,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Save a step of, or submit, the assessment the assessor named in the assertion.
	 *
	 * @return JSONResponse 201 / 200 `{assessmentId, assuranceLevel, lifecycle}`, or 401 / 403 / 409 / 422 / 502.
	 *
	 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-assessment-records-who-assessed-and-how-sure-the-school-is
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function submit(): JSONResponse {
		return $this->forAssessor(
			call: fn (string $assessorRef, array $claims): PortalOutcome => $this->assessments->submit(
				assessorRef: $assessorRef,
				trust: (string)($claims['trust'] ?? ''),
				body: $this->body()
			)
		);
	}//end submit()

	/**
	 * The assessor's own draft for a candidate at a sitting, to resume the form.
	 *
	 * @return JSONResponse 200 `{draft}`, or 401 / 403 / 422 / 502.
	 *
	 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-assessment-records-who-assessed-and-how-sure-the-school-is
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function draft(): JSONResponse {
		return $this->forAssessor(
			call: fn (string $assessorRef, array $claims): PortalOutcome => $this->assessments->draft(
				assessorRef: $assessorRef,
				sittingId: $this->stringParam(name: 'examSittingId'),
				learnerRef: $this->stringParam(name: 'learnerRef')
			)
		);
	}//end draft()

	/**
	 * Sign a submitted practical exam assessment as one of its two assessors.
	 *
	 * @param string $id The assessment to sign.
	 *
	 * @return JSONResponse 201 `{signatureId, role, assuranceLevel, signedAt}`, or 401 / 403 / 409 / 422 / 502.
	 *
	 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-result-needs-both-assessors
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function sign(string $id = ''): JSONResponse {
		return $this->forAssessor(
			call: fn (string $assessorRef, array $claims): PortalOutcome => $this->signing->sign(
				assessorRef: $assessorRef,
				trust: (string)($claims['trust'] ?? ''),
				assessmentId: trim($id)
			)
		);
	}//end sign()

	/**
	 * Sign the submitted assessment of a candidate at a sitting: the portal's signing action.
	 *
	 * The assessor picks the exam and the candidate from his own assignments,
	 * so the form sends `examSittingId` and `learnerRef`, never an assessment
	 * id he could not read.
	 *
	 * @return JSONResponse 201 `{signatureId, role, assuranceLevel, signedAt}`, or 401 / 403 / 409 / 422 / 502.
	 *
	 * @spec openspec/changes/practical-exam-assessment/tasks.md#t9
	 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-result-needs-both-assessors
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function signCandidate(): JSONResponse {
		return $this->forAssessor(
			call: fn (string $assessorRef, array $claims): PortalOutcome => $this->signing->signForCandidate(
				assessorRef: $assessorRef,
				trust: (string)($claims['trust'] ?? ''),
				sittingId: $this->stringParam(name: 'examSittingId'),
				learnerRef: $this->stringParam(name: 'learnerRef')
			)
		);
	}//end signCandidate()

	/**
	 * One exam sitting and its documents, for an assessor assigned to it.
	 *
	 * @param string $id The sitting.
	 *
	 * @return JSONResponse 200 `{id, startsAt, endsAt, roomIds, lifecycle, documents}`, or 401 / 403 / 422 / 502.
	 *
	 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-an-assessor-is-linked-to-an-exam-sitting-by-a-record
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function sitting(string $id = ''): JSONResponse {
		return $this->forAssessor(
			call: fn (string $assessorRef, array $claims): PortalOutcome => $this->sittings->sitting(
				assessorRef: $assessorRef,
				sittingId: trim($id)
			)
		);
	}//end sitting()

	/**
	 * Verify the assertion and its audience, then answer with the call's outcome.
	 *
	 * @param callable(string, array<string, mixed>): PortalOutcome $call The call, given the assessor and the claims.
	 *
	 * @return JSONResponse
	 */
	private function forAssessor(callable $call): JSONResponse {
		$claims = $this->verifier->verify(jwt: (string)$this->request->getHeader(PortalAssertionVerifierInterface::HEADER));
		if ($claims === null) {
			$response = new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => self::THROTTLE_ACTION]);
			return $response;
		}

		$assessorRef = $this->stringParam(name: 'externalAssessorId');
		if (($claims['audience'] ?? '') !== self::AUDIENCE || $assessorRef === '') {
			return new JSONResponse(data: ['error' => 'forbidden'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$outcome = $call($assessorRef, $claims);

		return new JSONResponse(data: $outcome->body, statusCode: $outcome->status);
	}//end forAssessor()
}//end class
