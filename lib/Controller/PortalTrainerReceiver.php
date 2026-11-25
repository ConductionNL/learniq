<?php

/**
 * Learniq PortalTrainerReceiver
 *
 * The shared receive step of the endpoints a workplace trainer's portal form
 * forwards to: verify the signed `X-Portal-Subject` assertion, accept only the
 * praktijkopleider audience, take the trainer from the `practicalTrainerId`
 * scope claim portaliq resolved (never from the form), and hand the trainer,
 * the session's trust and the whitelisted form fields to one step.
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

use OCA\Learniq\Portal\PortalForwardGate;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Throwable;

/**
 * Receives one trainer's portal form. The using class holds `$request`,
 * `$verifier` (PortalAssertionVerifierInterface) and `$logger`, and declares
 * `AUDIENCE`.
 *
 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-an-invited-trainer-may-sign-a-praktijkovereenkomst
 */
trait PortalTrainerReceiver {

	/**
	 * Verify the assertion and run the step for the trainer it names.
	 *
	 * @param array<int, string>                                         $fields The fields the form may send.
	 * @param callable(string, string, array<string, mixed>): PortalOutcome $step   Takes trainerRef, trust and the form.
	 *
	 * @return JSONResponse The step's outcome, or 401 / 403 / 502.
	 *
	 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-an-invited-trainer-may-sign-a-praktijkovereenkomst
	 */
	private function receiveFromTrainer(array $fields, callable $step): JSONResponse {
		// The trainer is the subject portaliq resolved, never a value the form
		// sent: `practicalTrainerId` is the scope claim the action declares.
		$admission = (new PortalForwardGate(verifier: $this->verifier, request: $this->request))->admit(
			audience: self::AUDIENCE,
			subjectParam: 'practicalTrainerId'
		);
		if ($admission instanceof JSONResponse) {
			return $admission;
		}

		$form = [];
		foreach ($fields as $field) {
			$form[$field] = $this->request->getParam($field);
		}

		$form = array_filter($form, static fn ($value): bool => $value !== null);
		try {
			$outcome = $step($admission->subject, (string)($admission->claims['trust'] ?? ''), $form);
		} catch (Throwable $exception) {
			// Never leak internals from a public route (ADR-005).
			$this->logger->error(
				'[' . static::class . '] A portal trainer request failed: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new JSONResponse(data: ['error' => 'downstream_error'], statusCode: Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(data: $outcome->body, statusCode: $outcome->status);
	}//end receiveFromTrainer()
}//end trait
