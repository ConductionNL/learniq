<?php

/**
 * Learniq Attestation Signing Guard
 *
 * Lifecycle guard for the Attestation schema's `drafted → signed` transition.
 * Called by OpenRegister's lifecycle engine when a learner submits an attestation.
 *
 * This is a legitimate PHP lifecycle seam per ADR-031 §"Lifecycle guards" plus
 * the cryptographic exception: HMAC-SHA256 signing cannot be expressed declaratively.
 * It is the ONLY PHP behaviour file for the compliance-audit feature beyond the
 * AuditPackExportController (document generation).
 *
 * Per ADR-022: HMAC key management and rotation live in OR's TenantKeyService.
 * This guard retrieves the current key via TenantKeyService::getCurrentTenantKey()
 * and MUST NOT maintain a local key store.
 *
 * Per ADR-008: OR emits the `attestation.signed` audit-trail entry automatically
 * when the lifecycle engine completes the transition — no AuditTrail::record()
 * call from this guard.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-2
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-12
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TenantKeyService;
use Psr\Log\LoggerInterface;

/**
 * Guards the Attestation `drafted → signed` lifecycle transition.
 *
 * Verifies that a matching cmi5.completed (or cmi5.passed) XapiStatement exists
 * in OpenRegister for the (learnerId, lessonId) pair and that the tenant has an
 * HMAC signing key. The signing itself is TenantSignatureAction, declared on the
 * same transition: OpenRegister calls guards by value, so a guard can not write
 * onto the object (learniq#983).
 *
 * Per ADR-031: no AuditTrail::record(), no HmacKeyService, no event listener.
 * OR's lifecycle engine owns all audit entries; this guard only does guard logic.
 *
 * @spec openspec/specs/compliance-audit/spec.md#requirement-maintain-an-append-only-signed-evidence-log
 */
class AttestationSigningGuard implements LifecycleGuardInterface {
	/**
	 * XAPI verb IDs that count as "completed" for attestation pre-condition.
	 *
	 * NOTE: these IRIs must match XapiCompletionHandler::COMPLETION_VERBS exactly —
	 * both classes must agree on the same xAPI ADL vocabulary. Fixes #201.
	 *
	 * @var string[]
	 */
	private const COMPLETION_VERBS = [
		'http://adlnet.gov/expapi/verbs/completed',
		'http://adlnet.gov/expapi/verbs/passed',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object query service for
	 *                                     XapiStatement lookup.
	 * @param TenantKeyService $tenantKeyService OR tenant-key abstraction that
	 *                                           exposes the current HMAC
	 *                                           signing key.
	 * @param LoggerInterface $logger PSR logger for guard rejections.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly TenantKeyService $tenantKeyService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Assert xAPI completion exists and a signing key is available.
	 *
	 * Called by OpenRegister's LifecycleValidationListener before the
	 * `drafted → signed` transition is saved.
	 *
	 * @param array<string,mixed> $object The Attestation as it would be saved (lifecycle at `signed`).
	 * @param string              $action The transition action (`sign`).
	 * @param string              $userId The caller's uid, or '' without a session.
	 *
	 * @return GuardResult Allow, or deny with what is missing.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-2
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$learnerId = (string)($object['learnerId'] ?? '');
		$lessonId = (string)($object['lessonId'] ?? '');
		$tenantId = (string)($object['tenant_id'] ?? '');

		if ($learnerId === '' || $lessonId === '') {
			$this->logger->warning(
				'AttestationSigningGuard: missing learnerId or lessonId',
				['object' => $object]
			);
			return GuardResult::deny('The attestation names no learner or no lesson, so it can not be signed.');
		}

		// Verify cmi5.completed (or cmi5.passed) XapiStatement exists.
		if ($this->xapiCompletionExists(learnerId: $learnerId, lessonId: $lessonId, tenantId: $tenantId) === false) {
			$this->logger->info(
				'AttestationSigningGuard: no completion statement found',
				['learnerId' => $learnerId, 'lessonId' => $lessonId]
			);
			return GuardResult::deny('The learner has not completed this lesson yet, so the attestation can not be signed.');
		}

		// Per spec: if the HMAC key is unavailable the attestation MUST fail.
		if ($this->tenantKeyService->getCurrentTenantKey($tenantId) === '') {
			$this->logger->error(
				'AttestationSigningGuard: OR tenant key unavailable; refusing to sign without HMAC key',
				['tenantId' => $tenantId]
			);
			return GuardResult::deny('No signing key is available for this organisation, so the attestation can not be signed.');
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * Query OR for a cmi5.completed or cmi5.passed XapiStatement for the given pair.
	 *
	 * The query is always scoped to the tenant so that a crafted xAPI statement
	 * in another tenant cannot satisfy this guard. Fixes #178.
	 *
	 * @param string $learnerId Learner identifier.
	 * @param string $lessonId Lesson UUID.
	 * @param string $tenantId Tenant ID to scope the query. Fixes #178.
	 *
	 * @return bool True when at least one matching statement exists.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-12
	 */
	private function xapiCompletionExists(string $learnerId, string $lessonId, string $tenantId): bool {
		foreach (self::COMPLETION_VERBS as $verbId) {
			$filters = [
				'actor.id' => $learnerId,
				'object.id' => $lessonId,
				'verb.id' => $verbId,
			];

			// #178: always scope to tenant_id to prevent cross-tenant forgery.
			if ($tenantId !== '') {
				$filters['tenant_id'] = $tenantId;
			}

			$results = $this->objectService->findAll(
				[
					'filters' => array_merge(
						$filters,
						[
							'register' => 'learniq',
							'schema' => 'xapi-statement',
						]
					),
					'limit' => 1,
				]
			);

			if (count($results) > 0) {
				return true;
			}
		}//end foreach

		return false;
	}//end xapiCompletionExists()
}//end class
