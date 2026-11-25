<?php

/**
 * Learniq PortalWerkprocesController
 *
 * Receives portaliq's forward when a workplace trainer submits a werkproces
 * assessment from the portal. The signed `X-Portal-Subject` assertion is the
 * only credential, and it is also the only place the sign-in level can be
 * read: a portal create writes straight into OpenRegister and carries none.
 * That is why the assessment goes through learniq's own endpoint since
 * `an-invited-trainer-may-assess`.
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
 * @spec openspec/changes/an-invited-trainer-may-assess/specs/bpv/spec.md#requirement-an-invited-trainer-may-submit-a-werkproces-assessment
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Learniq\Kernel\Contracts\PortalWerkprocesAssessmentInterface;
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
 * Receives one werkproces assessment from the trainer's portal form.
 *
 * @spec openspec/changes/an-invited-trainer-may-assess/specs/bpv/spec.md#requirement-an-invited-trainer-may-submit-a-werkproces-assessment
 */
class PortalWerkprocesController extends Controller {

	use PortalTrainerReceiver;

	/**
	 * The only audience this endpoint serves.
	 */
	private const AUDIENCE = 'praktijkopleider';

	/**
	 * The brute-force bucket for rejected assertions, shared with the other
	 * portal receivers.
	 */
	private const THROTTLE_ACTION = 'learniq_portal_assertion';

	/**
	 * The fields the form may send, as the action whitelists them.
	 *
	 * @var array<int, string>
	 */
	private const FIELDS = [
		'bpvPlacementId',
		'curriculumPlanId',
		'componentId',
		'kwalificatiedossierCode',
		'coreTaskCode',
		'werkprocesCode',
		'werkprocesLabel',
		'competencyId',
		'assessment',
		'notes',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest                   $request     The request.
	 * @param PortalAssertionVerifierInterface    $verifier    Verifies X-Portal-Subject.
	 * @param PortalWerkprocesAssessmentInterface $assessments Checks, stamps and stores the assessment.
	 * @param LoggerInterface            $logger      PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifierInterface $verifier,
		private readonly PortalWerkprocesAssessmentInterface $assessments,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Store the assessment the trainer named in the assertion submitted.
	 *
	 * @return JSONResponse 201 `{assessmentId, assuranceLevel}`, or 401 / 403 / 422 / 502.
	 *
	 * @spec openspec/changes/an-invited-trainer-may-assess/specs/bpv/spec.md#requirement-an-invited-trainer-may-submit-a-werkproces-assessment
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function submit(): JSONResponse {
		return $this->receiveFromTrainer(
			step: fn (string $trainerRef, string $trust, array $body): PortalOutcome => $this->assessments->submit(
				trainerRef: $trainerRef,
				trust: $trust,
				body: $body
			)
		);
	}//end submit()
}//end class
