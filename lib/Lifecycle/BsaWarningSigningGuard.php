<?php

/**
 * Learniq BSA Warning Signing Guard
 *
 * Lifecycle guard for the BsaWarning schema's `drafted -> issued` transition.
 * Called by OpenRegister's lifecycle engine when a study-advisor issues a
 * formal BSA warning.
 *
 * This is a legitimate PHP lifecycle seam per ADR-031 §"Lifecycle guards" plus
 * the cryptographic exception: HMAC-SHA256 signing cannot be expressed
 * declaratively. Mirrors AttestationSigningGuard's `drafted -> signed`
 * behaviour on Attestation.
 *
 * Blocks the transition unless `improvementPeriod` (startDate + endDate) and
 * `offeredGuidance` (non-empty) are both set — the "sufficient study
 * guidance" and timely-warning safeguards a negative BSA decision is judged
 * against on appeal (rijksoverheid.nl, per proposal.md "Why").
 *
 * Per ADR-022: HMAC key management and rotation live in OR's TenantKeyService.
 * This guard retrieves the current key via TenantKeyService::getCurrentTenantKey()
 * and MUST NOT maintain a local key store.
 *
 * Per ADR-008: OR emits the `bsa-warning.issued` audit-trail entry
 * automatically when the lifecycle engine completes the transition — no
 * AuditTrail::record() call from this guard.
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
 * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-the-formal-warning-captures-improvement-period-guidance-and-personal-circumstances-and-is-signed-evidence
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\TenantKeyService;
use Psr\Log\LoggerInterface;

/**
 * Guards the BsaWarning `drafted -> issued` lifecycle transition.
 *
 * Verifies `improvementPeriod.startDate`/`improvementPeriod.endDate` and a
 * non-empty `offeredGuidance`, and that the tenant has an HMAC signing key. The
 * signing itself is TenantSignatureAction, declared on the same transition:
 * OpenRegister calls guards by value, so a guard can not write onto the object
 * (learniq#983).
 *
 * Per ADR-031: no AuditTrail::record(), no HmacKeyService, no event listener.
 * OR's lifecycle engine owns all audit entries; this guard only does guard logic.
 *
 * @spec openspec/specs/study-progress/spec.md#requirement-the-formal-warning-captures-improvement-period-guidance-and-personal-circumstances-and-is-signed-evidence
 */
class BsaWarningSigningGuard implements LifecycleGuardInterface {
	/**
	 * Constructor.
	 *
	 * @param TenantKeyService $tenantKeyService OR tenant-key abstraction that
	 *                                           exposes the current HMAC
	 *                                           signing key.
	 * @param LoggerInterface $logger PSR logger for guard rejections.
	 */
	public function __construct(
		private readonly TenantKeyService $tenantKeyService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Assert the guidance/improvement-period pre-conditions and that a signing key is available.
	 *
	 * Called by OpenRegister's LifecycleValidationListener before the
	 * `drafted -> issued` transition is saved.
	 *
	 * @param array<string,mixed> $object The BsaWarning as it would be saved (lifecycle at `issued`).
	 * @param string              $action The transition action (`issue`).
	 * @param string              $userId The caller's uid, or '' without a session.
	 *
	 * @return GuardResult Allow, or deny with what is missing.
	 *
	 * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-the-formal-warning-captures-improvement-period-guidance-and-personal-circumstances-and-is-signed-evidence
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$tenantId = (string)($object['tenant_id'] ?? '');

		if ($this->hasValidImprovementPeriod(object: $object) === false) {
			$this->logger->info(
				'BsaWarningSigningGuard: improvementPeriod missing startDate/endDate — blocking issue',
				['object' => $object]
			);
			return GuardResult::deny('The warning needs an improvement period with a start and an end date before it can be issued.');
		}

		$offeredGuidance = $object['offeredGuidance'] ?? '';
		if (is_string($offeredGuidance) === false || trim($offeredGuidance) === '') {
			$this->logger->info(
				'BsaWarningSigningGuard: offeredGuidance missing/empty — blocking issue',
				['object' => $object]
			);
			return GuardResult::deny('The warning needs a description of the study guidance offered before it can be issued.');
		}

		// Per spec: if the HMAC key is unavailable the warning MUST fail to issue.
		if ($this->tenantKeyService->getCurrentTenantKey($tenantId) === '') {
			$this->logger->error(
				'BsaWarningSigningGuard: OR tenant key unavailable; refusing to sign without HMAC key',
				['tenantId' => $tenantId]
			);
			return GuardResult::deny('No signing key is available for this organisation, so the warning can not be issued.');
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * Check that improvementPeriod carries non-empty startDate and endDate.
	 *
	 * @param array<string,mixed> $object The BsaWarning property array.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-the-formal-warning-captures-improvement-period-guidance-and-personal-circumstances-and-is-signed-evidence
	 */
	private function hasValidImprovementPeriod(array $object): bool {
		$period = $object['improvementPeriod'] ?? null;
		if (is_array($period) === false) {
			return false;
		}

		$startDate = $period['startDate'] ?? null;
		$endDate = $period['endDate'] ?? null;

		if (is_string($startDate) === false || trim($startDate) === '') {
			return false;
		}

		if (is_string($endDate) === false || trim($endDate) === '') {
			return false;
		}

		return true;
	}//end hasValidImprovementPeriod()
}//end class
