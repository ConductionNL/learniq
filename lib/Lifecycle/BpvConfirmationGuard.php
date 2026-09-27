<?php

/**
 * Learniq BPV Confirmation Guard
 *
 * Lifecycle guard for the BpvPlacement schema's `confirm` transition
 * (sbb-verification-pending → confirmed). Blocks confirmation unless the
 * placement's stored `leerbedrijfVerification.status` is `verified` — WEB
 * art. 7.2.8/7.2.9 requires the employer hosting a BPV placement to be a
 * leerbedrijf erkend by SBB.
 *
 * Reads the STORED verification result already present on the transitioning
 * object (written earlier by BpvLeerbedrijfVerificationHandler on the
 * `checkLeerbedrijf` transition) — it never calls a
 * ProvidesLeerbedrijfVerification provider synchronously during the
 * transition, the same "read stored lifecycle-adjacent state" pattern
 * AssessmentPublishGuard and LearningPlanSignatureGuard use for their own
 * gates.
 *
 * ADR-031 legitimate exception: "Lifecycle guard — business rule that must
 * run before a state transition and cannot be expressed as a schema
 * declaration." Referenced from BpvPlacement's x-openregister-lifecycle
 * `confirm` transition's `requires` in learniq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
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
 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-bpvplacement-confirmation-is-gated-on-verified-leerbedrijf-status
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards the BpvPlacement `confirm` lifecycle transition.
 *
 * A BpvPlacement may only be confirmed when its stored
 * `leerbedrijfVerification.status` equals `verified`. Every other status
 * (`unverified`, `pending`, `rejected`, `expired`) or a missing verification
 * block fails closed.
 *
 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-bpvplacement-confirmation-is-gated-on-verified-leerbedrijf-status
 */
class BpvConfirmationGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'The work placement can only be confirmed once the training company is verified.';

	/**
	 * The verification status value that satisfies the gate.
	 */
	private const VERIFIED_STATUS = 'verified';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Authorise or deny the transition this guard is named on (LifecycleGuardInterface).
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow, or deny with the reason shown to the caller.
	 *
	 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-bpvplacement-confirmation-is-gated-on-verified-leerbedrijf-status
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->allows(placement: $object) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::DENIAL);
	}//end check()

	/**
	 * The rule behind check(), answered as a boolean.
	 *
	 * Called by OpenRegister's lifecycle engine before executing the
	 * `confirm` transition on a BpvPlacement object.
	 *
	 * @param array<string,mixed> $placement The object at its target state, transition inputs merged in.
	 *
	 * @return bool True when leerbedrijfVerification.status is `verified`; false blocks the
	 *              transition (HTTP 422).
	 *
	 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-bpvplacement-confirmation-is-gated-on-verified-leerbedrijf-status
	 */
	private function allows(array $placement): bool {
		$placementId = $placement['id'] ?? ($placement['uuid'] ?? '');
		$verification = $placement['trainingCompanyVerification'] ?? null;

		if (is_array($verification) === false) {
			$this->logger->info(
				'[BpvConfirmationGuard] BpvPlacement {id} has no leerbedrijfVerification block; blocking confirm.',
				['id' => $placementId]
			);
			return false;
		}

		$status = $verification['status'] ?? 'unverified';

		if ($status !== self::VERIFIED_STATUS) {
			$this->logger->info(
				'[BpvPlacement] {id} leerbedrijfVerification.status is "{status}", not verified; blocking confirm.',
				['id' => $placementId, 'status' => $status]
			);
			return false;
		}

		$this->logger->info(
			'[BpvConfirmationGuard] BpvPlacement {id} leerbedrijf is verified — allowing confirm.',
			['id' => $placementId]
		);

		return true;
	}//end allows()
}//end class
