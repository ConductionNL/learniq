<?php

/**
 * Learniq AttestationRevocationGuard
 *
 * Refuses the `revoke` transition of an attestation without a reason. A
 * signed attestation is evidence; revoking it is the only way to correct it
 * (the freeze refuses every edit), and the reason is what tells an auditor
 * later why a signed record stopped counting.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/compliance-attestation-locked-after-signing/specs/compliance-audit/spec.md#requirement-revoking-a-signed-attestation-records-a-reason-and-changes-nothing-else
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Requires a non-empty revocationReason on `revoke`.
 *
 * @spec openspec/changes/compliance-attestation-locked-after-signing/specs/compliance-audit/spec.md#requirement-revoking-a-signed-attestation-records-a-reason-and-changes-nothing-else
 */
class AttestationRevocationGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	public const DENIAL = 'Give a reason for revoking the attestation.';

	/**
	 * Allow the revocation only with a reason (LifecycleGuardInterface).
	 *
	 * @param array<string,mixed> $object The attestation at its target state, transition inputs merged in.
	 * @param string              $action The transition action being applied.
	 * @param string              $userId The uid of the caller.
	 *
	 * @return GuardResult Allow, or deny asking for the reason.
	 *
	 * @spec openspec/changes/compliance-attestation-locked-after-signing/specs/compliance-audit/spec.md#requirement-revoking-a-signed-attestation-records-a-reason-and-changes-nothing-else
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$reason = ($object['revocationReason'] ?? null);
		if (is_string($reason) === true && trim($reason) !== '') {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::DENIAL);
	}//end check()
}//end class
