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
			$learnerId = (string)($object['learnerId'] ?? '');
			$programmeId = (string)($object['programmeId'] ?? '');
			$academicYear = (string)($object['academicYear'] ?? '');

			$hasWarning = $this->hasIssuedWarning(
				learnerId: $learnerId,
				programmeId: $programmeId,
				academicYear: $academicYear,
				tenantId: $tenantId
			);

			if ($hasWarning === false) {
				$this->logger->info(
					'BsaDecisionGuard: no issued BsaWarning found for learner {l}, programme {p}, year {y} — blocking negative decision.',
					['l' => $learnerId, 'p' => $programmeId, 'y' => $academicYear]
				);
				return GuardResult::deny('A negative decision needs an issued warning for this learner, programme and academic year.');
			}

			$rationale = $object['rationale'] ?? '';
			if (is_string($rationale) === false || trim($rationale) === '') {
				$this->logger->info(
					'BsaDecisionGuard: rationale missing/empty — blocking negative decision.',
					['learnerId' => $learnerId]
				);
				return GuardResult::deny('A negative decision needs a rationale.');
			}
		}//end if

		if ($this->tenantKeyService->getCurrentTenantKey($tenantId) === '') {
			$this->logger->error(
				'BsaDecisionGuard: OR tenant key unavailable; refusing to decide without HMAC key',
				['tenantId' => $tenantId]
			);
			return GuardResult::deny('No signing key is available for this organisation, so the decision can not be recorded.');
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * Query OR for an `issued` BsaWarning matching (learnerId, programmeId, academicYear).
	 *
	 * @param string $learnerId Learner Nextcloud user ID.
	 * @param string $programmeId Programme UUID.
	 * @param string $academicYear Academic year string.
	 * @param string $tenantId Tenant ID to scope the query.
	 *
	 * @return bool True when at least one matching issued warning exists.
	 *
	 * @spec openspec/specs/study-progress/spec.md#requirement-a-negative-bsa-decision-must-be-blocked-without-a-logged-issued-warning
	 */
	private function hasIssuedWarning(string $learnerId, string $programmeId, string $academicYear, string $tenantId): bool {
		if ($learnerId === '' || $programmeId === '' || $academicYear === '') {
			return false;
		}

		$filters = [
			'learnerId' => $learnerId,
			'programmeId' => $programmeId,
			'academicYear' => $academicYear,
			'lifecycle' => 'issued',
		];

		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$results = $this->objectService->findAll(
			[
				'filters' => array_merge(
					$filters,
					[
						'register' => self::LEARNIQ_REGISTER,
						'schema' => self::BSA_WARNING_SCHEMA,
					]
				),
				'limit' => 1,
			]
		);

		return count($results) > 0;
	}//end hasIssuedWarning()
}//end class
