<?php

/**
 * Learniq BSA Decision Guard
 *
 * Lifecycle guard for the BsaDecision schema's `drafted -> decided` transition.
 * Called by OpenRegister's lifecycle engine when a study-advisor / exam-board
 * member records a year-end bindend studieadvies (BSA) decision.
 *
 * This is the ONE requirement in the study-progress capability that MUST be
 * enforced in code rather than declared config: "no negative BSA without a
 * logged warning" is a cross-object invariant no JSON-logic expression on a
 * single schema can check (it needs to query the BsaWarning collection).
 * Legitimate PHP per ADR-031 §"Lifecycle guards".
 *
 * Rule: when `decisionType` is `negative` or `negative-with-recommendation`,
 * at least one `BsaWarning` with `lifecycle: issued` MUST exist for the same
 * (learnerId, programmeId, academicYear), AND `rationale` must be non-empty.
 * `positive` and `postponed` decisions are not subject to either check.
 *
 * Mirrors AttestationSigningGuard / ProgrammePublishGuard's `requires` pattern:
 * on success this guard also stamps the HMAC `signature`/`signingKeyId` pair,
 * matching BsaWarningSigningGuard's cryptographic-exception rationale.
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
 * @spec openspec/specs/study-progress/spec.md#requirement-a-negative-bsa-decision-must-be-blocked-without-a-logged-issued-warning
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TenantKeyService;
use Psr\Log\LoggerInterface;

/**
 * Guards the BsaDecision `drafted -> decided` lifecycle transition.
 *
 * A negative decision needs an issued BsaWarning for the same learner,
 * programme and academic year, and a rationale; every decision needs the
 * tenant's HMAC signing key. The signing itself is TenantSignatureAction,
 * declared on the same transition: OpenRegister calls guards by value, so a
 * guard can not write onto the object (learniq#983).
 *
 * @spec openspec/specs/study-progress/spec.md#requirement-a-negative-bsa-decision-must-be-blocked-without-a-logged-issued-warning
 */
class BsaDecisionGuard implements LifecycleGuardInterface {

	private const LEARNIQ_REGISTER = 'learniq';
	private const BSA_WARNING_SCHEMA = 'bsa-warning';

	/**
	 * DecisionType values that trigger the warning + rationale requirement.
	 *
	 * @var string[]
	 */
	private const NEGATIVE_DECISION_TYPES = [
		'negative',
		'negative-with-recommendation',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object query service for
	 *                                     BsaWarning lookup.
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
	 * Assert the negative-decision pre-conditions and that a signing key is available.
	 *
	 * Called by OpenRegister's LifecycleValidationListener before the
	 * `drafted -> decided` transition is saved.
	 *
	 * @param array<string,mixed> $object The BsaDecision as it would be saved (lifecycle at `decided`).
	 * @param string              $action The transition action (`decide`).
	 * @param string              $userId The caller's uid, or '' without a session.
	 *
	 * @return GuardResult Allow, or deny naming the missing requirement.
	 *
	 * @spec openspec/specs/study-progress/spec.md#requirement-a-negative-bsa-decision-must-be-blocked-without-a-logged-issued-warning
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$decisionType = $object['decisionType'] ?? '';
		$tenantId = (string)($object['tenant_id'] ?? '');

		if (in_array($decisionType, self::NEGATIVE_DECISION_TYPES, true) === true) {
			$refusal = $this->negativeDecisionRefusal(decision: $object);
			if ($refusal !== null) {
				return $refusal;
			}
		}

		return ($this->missingKeyRefusal(tenantId: $tenantId) ?? GuardResult::allow());
	}//end check()

	/**
	 * Why a negative decision may not be recorded, or null: first the issued
	 * warning, then the rationale.
	 *
	 * @param array<string,mixed> $decision The BsaDecision as it would be saved.
	 *
	 * @return GuardResult|null
	 *
	 * @spec openspec/specs/study-progress/spec.md#requirement-a-negative-bsa-decision-must-be-blocked-without-a-logged-issued-warning
	 */
	private function negativeDecisionRefusal(array $decision): ?GuardResult {
		return ($this->warningRefusal(decision: $decision) ?? $this->rationaleRefusal(decision: $decision));
	}//end negativeDecisionRefusal()

	/**
	 * The refusal of a negative decision without an issued warning for its
	 * learner, programme and academic year, or null when there is one.
	 *
	 * @param array<string,mixed> $decision The BsaDecision as it would be saved.
	 *
	 * @return GuardResult|null
	 *
	 * @spec openspec/specs/study-progress/spec.md#requirement-a-negative-bsa-decision-must-be-blocked-without-a-logged-issued-warning
	 */
	private function warningRefusal(array $decision): ?GuardResult {
		$warning = $this->warningKey(decision: $decision);
		if ($this->hasIssuedWarning(warning: $warning, tenantId: (string)($decision['tenant_id'] ?? '')) === true) {
			return null;
		}

		$this->logger->info(
			'BsaDecisionGuard: no issued BsaWarning found for learner {l}, programme {p}, year {y} — blocking negative decision.',
			['l' => $warning['learnerId'], 'p' => $warning['programmeId'], 'y' => $warning['academicYear']]
		);
		return GuardResult::deny('A negative decision needs an issued warning for this learner, programme and academic year.');
	}//end warningRefusal()

	/**
	 * The refusal of a negative decision without a rationale, or null when it has one.
	 *
	 * @param array<string,mixed> $decision The BsaDecision as it would be saved.
	 *
	 * @return GuardResult|null
	 */
	private function rationaleRefusal(array $decision): ?GuardResult {
		$rationale = $decision['rationale'] ?? '';
		if (is_string($rationale) === true && trim($rationale) !== '') {
			return null;
		}

		$this->logger->info(
			'BsaDecisionGuard: rationale missing/empty — blocking negative decision.',
			['learnerId' => (string)($decision['learnerId'] ?? '')]
		);
		return GuardResult::deny('A negative decision needs a rationale.');
	}//end rationaleRefusal()

	/**
	 * The refusal when the organisation has no signing key, or null when it has one.
	 *
	 * @param string $tenantId The decision's tenant.
	 *
	 * @return GuardResult|null
	 */
	private function missingKeyRefusal(string $tenantId): ?GuardResult {
		if ($this->tenantKeyService->getCurrentTenantKey($tenantId) !== '') {
			return null;
		}

		$this->logger->error(
			'BsaDecisionGuard: OR tenant key unavailable; refusing to decide without HMAC key',
			['tenantId' => $tenantId]
		);
		return GuardResult::deny('No signing key is available for this organisation, so the decision can not be recorded.');
	}//end missingKeyRefusal()

	/**
	 * The learner, programme and academic year a negative decision needs an issued warning for.
	 *
	 * @param array<string,mixed> $decision The BsaDecision as it would be saved.
	 *
	 * @return array{learnerId: string, programmeId: string, academicYear: string}
	 */
	private function warningKey(array $decision): array {
		return [
			'learnerId' => (string)($decision['learnerId'] ?? ''),
			'programmeId' => (string)($decision['programmeId'] ?? ''),
			'academicYear' => (string)($decision['academicYear'] ?? ''),
		];
	}//end warningKey()

	/**
	 * Query OR for an `issued` BsaWarning matching (learnerId, programmeId, academicYear).
	 *
	 * @param array{learnerId: string, programmeId: string, academicYear: string} $warning  The warning's learner, programme and academic year.
	 * @param string                                                              $tenantId Tenant ID to scope the query.
	 *
	 * @return bool True when at least one matching issued warning exists.
	 *
	 * @spec openspec/specs/study-progress/spec.md#requirement-a-negative-bsa-decision-must-be-blocked-without-a-logged-issued-warning
	 */
	private function hasIssuedWarning(array $warning, string $tenantId): bool {
		if ($warning['learnerId'] === '' || $warning['programmeId'] === '' || $warning['academicYear'] === '') {
			return false;
		}

		$results = $this->objectService->findAll($this->warningQuery(warning: $warning, tenantId: $tenantId));

		return count($results) > 0;
	}//end hasIssuedWarning()

	/**
	 * The issued-warning query: at most one BsaWarning matching the filters.
	 *
	 * @param array{learnerId: string, programmeId: string, academicYear: string} $warning  The warning's learner, programme and academic year.
	 * @param string                                                              $tenantId Tenant ID to scope the query.
	 *
	 * @return array<string, mixed>
	 */
	private function warningQuery(array $warning, string $tenantId): array {
		return [
			'filters' => array_merge(
				$this->warningFilters(warning: $warning, tenantId: $tenantId),
				[
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::BSA_WARNING_SCHEMA,
				]
			),
			'limit' => 1,
		];
	}//end warningQuery()

	/**
	 * The filters of the issued-warning query, scoped to the tenant when there is one.
	 *
	 * @param array{learnerId: string, programmeId: string, academicYear: string} $warning  The warning's learner, programme and academic year.
	 * @param string                                                              $tenantId Tenant ID to scope the query.
	 *
	 * @return array<string, string>
	 */
	private function warningFilters(array $warning, string $tenantId): array {
		$filters = [
			'learnerId' => $warning['learnerId'],
			'programmeId' => $warning['programmeId'],
			'academicYear' => $warning['academicYear'],
			'lifecycle' => 'issued',
		];

		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		return $filters;
	}//end warningFilters()
}//end class
