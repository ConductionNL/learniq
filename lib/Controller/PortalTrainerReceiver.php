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

use OCA\Learniq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Throwable;

/**
 * Receives one trainer's portal form. The using class holds `$request`,
 * `$verifier` (PortalAssertionVerifierInterface) and `$logger`, and declares
 * `FIELDS`, `AUDIENCE` and `THROTTLE_ACTION`.
 *
 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-an-invited-trainer-may-sign-a-praktijkovereenkomst
 */
trait PortalTrainerReceiver {

	use PortalFormFields;

	/**
	 * Verify the assertion and run the step for the trainer it names.
	 *
	 * @param callable(string, string, array<string, mixed>): PortalOutcome $step Takes trainerRef, trust and the form.
	 *
	 * @return JSONResponse The step's outcome, or 401 / 403 / 502.
	 *
	 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-an-invited-trainer-may-sign-a-praktijkovereenkomst
	 */
	private function receiveFromTrainer(callable $step): JSONResponse {
		$claims = $this->verifier->verify(jwt: (string)$this->request->getHeader(PortalAssertionVerifierInterface::HEADER));
		if ($claims === null) {
			$response = new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => self::THROTTLE_ACTION]);
			return $response;
		}

		// The trainer is the subject portaliq resolved, never a value the form
		// sent: `practicalTrainerId` is the scope claim the action declares.
		$trainerRef = $this->stringParam(name: 'practicalTrainerId');
		if (($claims['audience'] ?? '') !== self::AUDIENCE || $trainerRef === '') {
			return new JSONResponse(data: ['error' => 'forbidden'], statusCode: Http::STATUS_FORBIDDEN);
		}

		try {
			$outcome = $step($trainerRef, (string)($claims['trust'] ?? ''), $this->body());
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
