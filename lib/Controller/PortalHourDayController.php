<?php

/**
 * Learniq Portal Hour Day Controller
 *
 * The receiving end of portaliq's `writeHourDay` forward (bpv-hours-per-day):
 * a student writes the hours of one day of her placement and keeps the week
 * for later or sends it to her workplace trainer. Same receiver pattern as
 * PortalSubmissionController: the `X-Portal-Subject` assertion is the only
 * credential, the method is `#[PublicPage]` only because the forward carries
 * no Nextcloud session, and there is no session fallback.
 *
 * Order: verify (401, throttled) -> audience `student` (403) -> `learnerRef`
 * in the body, stamped by portaliq from the subject's own account (403) ->
 * the student's profile and Nextcloud account (403 `not_available`) -> the
 * day. Every rule of the day is enforced in PortalHourDayWrite and, on the
 * write, HourWeekSubmissionStamp. A downstream failure answers 502 without
 * internals.
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
 * @spec openspec/changes/bpv-hours-per-day/specs/portal-contribution/spec.md#requirement-a-student-writes-hours-per-day-and-keeps-a-draft
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Learniq\Kernel\Contracts\PortalHourDayWriteInterface;
use OCA\Learniq\Kernel\Contracts\PortalLearnerResolverInterface;
use OCA\Learniq\Kernel\Contracts\PortalMessagesInterface;
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
 * Receives portaliq's day-of-hours forward for one student.
 *
 * @spec openspec/changes/bpv-hours-per-day/specs/portal-contribution/spec.md#requirement-a-student-writes-hours-per-day-and-keeps-a-draft
 */
class PortalHourDayController extends Controller {

	/**
	 * The brute-force bucket for rejected assertions, shared with the other portal receivers.
	 */
	private const THROTTLE_ACTION = 'learniq_portal_assertion';

	/**
	 * The fields the form may send, as the action whitelists them.
	 *
	 * @var array<int, string>
	 */
	public const FIELDS = [
		'bpvPlacementId',
		'date',
		'startTime',
		'endTime',
		'breakMinutes',
		'description',
		'werkprocesCodes',
		'send',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest                         $request  The request.
	 * @param PortalAssertionVerifierInterface $verifier Verifies X-Portal-Subject.
	 * @param PortalLearnerResolverInterface   $learners learnerRef to the student.
	 * @param PortalHourDayWriteInterface      $days     Checks and stores the day.
	 * @param PortalMessagesInterface          $messages Student-facing refusal messages.
	 * @param LoggerInterface                  $logger   PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifierInterface $verifier,
		private readonly PortalLearnerResolverInterface $learners,
		private readonly PortalHourDayWriteInterface $days,
		private readonly PortalMessagesInterface $messages,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Write one day of the student's own week of hours.
	 *
	 * @return JSONResponse 200 `{hourWeekId, isoWeek, lifecycle, hoursSubmitted}`, or 401 / 403 / 409 / 422 / 502.
	 *
	 * @spec openspec/changes/bpv-hours-per-day/specs/portal-contribution/spec.md#requirement-a-student-writes-hours-per-day-and-keeps-a-draft
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function write(): JSONResponse {
		$failure = new PortalForwardFailure(logger: $this->logger, message: '[PortalHourDayController] A day of hours could not be written: {msg}');
		$forward = new PortalLearnerForward(gate: new PortalForwardGate(verifier: $this->verifier, request: $this->request), learners: $this->learners);
		$step = fn (PortalLearner $learner): PortalOutcome => $this->days->write(learnerRef: $learner->profileRef, body: $this->body());

		return $forward->respond(step: new PortalLearnerStep(run: $step, messages: $this->messages->message(...)), failure: $failure);
	}//end write()

	/**
	 * The whitelisted fields the form sent.
	 *
	 * @return array<string, mixed>
	 */
	private function body(): array {
		$body = [];
		foreach (self::FIELDS as $field) {
			$value = $this->request->getParam($field);
			if ($value !== null) {
				$body[$field] = $value;
			}
		}

		return $body;
	}//end body()
}//end class
