<?php

/**
 * Learniq PortalPokSignatureController
 *
 * Receives portaliq's forward when a workplace trainer signs a
 * praktijkovereenkomst from the portal. The signed `X-Portal-Subject`
 * assertion is the only credential, and it is also the only place the sign-in
 * level can be read: a portal create writes straight into OpenRegister and
 * carries none. That is why the signature goes through learniq's own endpoint
 * since `an-invited-trainer-may-activate-a-placement`.
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
 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-an-invited-trainer-may-sign-a-praktijkovereenkomst
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Learniq\Kernel\Contracts\PortalPokSigningInterface;
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
 * Receives one POK signature from the trainer's portal form.
 *
 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-an-invited-trainer-may-sign-a-praktijkovereenkomst
 */
class PortalPokSignatureController extends Controller {

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
	 * The fields the form may send, as the action whitelists them: only the
	 * agreement. Who signed, how and how surely are the server's.
	 *
	 * @var array<int, string>
	 */
	private const FIELDS = ['subjectId'];

	/**
	 * Constructor.
	 *
	 * @param IRequest                         $request    The request.
	 * @param PortalAssertionVerifierInterface $verifier   Verifies X-Portal-Subject.
	 * @param PortalPokSigningInterface        $signatures Checks, stamps and stores the signature.
	 * @param LoggerInterface                  $logger     PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifierInterface $verifier,
		private readonly PortalPokSigningInterface $signatures,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Store the signature of the trainer the assertion names.
	 *
	 * @return JSONResponse 201 `{signatureId, assuranceLevel, signedAt}`, or 401 / 403 / 409 / 422 / 502.
	 *
	 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-an-invited-trainer-may-sign-a-praktijkovereenkomst
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function sign(): JSONResponse {
		return $this->receiveFromTrainer(
			step: fn (string $trainerRef, string $trust, array $body): PortalOutcome => $this->signatures->sign(
				trainerRef: $trainerRef,
				trust: $trust,
				body: $body
			)
		);
	}//end sign()
}//end class
