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
	 * NOTE: these IRIs must match XapiEnrolmentCompletion::COMPLETION_VERBS exactly —
	 * both classes must agree on the same xAPI ADL vocabulary. Fixes #201.
	 *
	 * @var string[]
	 */
	private const COMPLETION_VERBS = [
		'http://adlnet.gov/expapi/verbs/completed',
		'http://adlnet.gov/expapi/verbs/passed',
	];

	/**
	 * Most statements one learner holds for one lesson that the guard scans.
	 *
	 * @var int
	 */
	private const STATEMENT_SCAN_LIMIT = 1000;

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
		$refusal = ($this->identifierRefusal(attestation: $object)
			?? $this->completionRefusal(attestation: $object)
			?? $this->missingKeyRefusal(tenantId: (string)($object['tenant_id'] ?? '')));

		return ($refusal ?? GuardResult::allow());
	}//end check()

	/**
	 * The refusal of an attestation that names no learner or no lesson, or null.
	 *
	 * @param array<string,mixed> $attestation The Attestation as it would be saved.
	 *
	 * @return GuardResult|null
	 */
	private function identifierRefusal(array $attestation): ?GuardResult {
		if ((string)($attestation['learnerId'] ?? '') !== '' && (string)($attestation['lessonId'] ?? '') !== '') {
			return null;
		}

		$this->logger->warning(
			'AttestationSigningGuard: missing learnerId or lessonId',
			['object' => $attestation]
		);
		return GuardResult::deny('The attestation names no learner or no lesson, so it can not be signed.');
	}//end identifierRefusal()

	/**
	 * The refusal of an attestation whose learner has no cmi5.completed (or
	 * cmi5.passed) XapiStatement for the lesson, or null.
	 *
	 * @param array<string,mixed> $attestation The Attestation as it would be saved.
	 *
	 * @return GuardResult|null
	 */
	private function completionRefusal(array $attestation): ?GuardResult {
		if ($this->xapiCompletionExists(attestation: $attestation) === true) {
			return null;
		}

		$this->logger->info(
			'AttestationSigningGuard: no completion statement found',
			['learnerId' => (string)($attestation['learnerId'] ?? ''), 'lessonId' => (string)($attestation['lessonId'] ?? '')]
		);
		return GuardResult::deny('The learner has not completed this lesson yet, so the attestation can not be signed.');
	}//end completionRefusal()

	/**
	 * The refusal when the organisation has no signing key, or null. Per spec:
	 * if the HMAC key is unavailable the attestation MUST fail.
	 *
	 * @param string $tenantId The attestation's tenant.
	 *
	 * @return GuardResult|null
	 */
	private function missingKeyRefusal(string $tenantId): ?GuardResult {
		if ($this->tenantKeyService->getCurrentTenantKey($tenantId) !== '') {
			return null;
		}

		$this->logger->error(
			'AttestationSigningGuard: OR tenant key unavailable; refusing to sign without HMAC key',
			['tenantId' => $tenantId]
		);
		return GuardResult::deny('No signing key is available for this organisation, so the attestation can not be signed.');
	}//end missingKeyRefusal()

	/**
	 * Query OR for a completed or passed XapiStatement for the attestation's learner and lesson.
	 *
	 * The read filters only on declared XapiStatement fields: the stamped
	 * `lessonId` and the server-trusted `verified_actor_id` (C6). The verb is
	 * a nested xAPI object, so it is matched here rather than in the filter,
	 * which could never match an undeclared `verb.id` key (#1116).
	 *
	 * The query is always scoped to the tenant so that a crafted xAPI statement
	 * in another tenant cannot satisfy this guard. Fixes #178.
	 *
	 * @param array<string,mixed> $attestation The Attestation: its learner, lesson and tenant.
	 *
	 * @return bool True when at least one matching statement exists.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-12
	 */
	private function xapiCompletionExists(array $attestation): bool {
		$results = $this->objectService->findAll(
			[
				'filters' => array_merge(
					$this->statementFilters(attestation: $attestation),
					[
						'register' => 'learniq',
						'schema' => 'xapi-statement',
					]
				),
				'limit' => self::STATEMENT_SCAN_LIMIT,
			]
		);

		return $this->anyCompletion(results: $results);
	}//end xapiCompletionExists()

	/**
	 * The statement filters: the learner, the lesson and, when set, the tenant.
	 *
	 * @param array<string,mixed> $attestation The Attestation.
	 *
	 * @return array<string, string>
	 */
	private function statementFilters(array $attestation): array {
		$tenantId = (string)($attestation['tenant_id'] ?? '');
		$filters = [
			'verified_actor_id' => (string)($attestation['learnerId'] ?? ''),
			'lessonId' => (string)($attestation['lessonId'] ?? ''),
		];

		// #178: always scope to tenant_id to prevent cross-tenant forgery.
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		return $filters;
	}//end statementFilters()

	/**
	 * Whether any found statement has a completion verb.
	 *
	 * @param array<int, mixed> $results What findAll() answered.
	 *
	 * @return bool
	 */
	private function anyCompletion(array $results): bool {
		foreach ($results as $row) {
			if (is_array($row) === false) {
				$row = $row->jsonSerialize();
			}

			if (in_array((string)($row['verb']['id'] ?? ''), self::COMPLETION_VERBS, true) === true) {
				return true;
			}
		}

		return false;
	}//end anyCompletion()
}//end class
