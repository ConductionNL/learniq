<?php

/**
 * Learniq POK Activation Guard
 *
 * Lifecycle guard for the Praktijkovereenkomst schema's `activate` transition
 * (pending-signatures → active). Blocks activation unless a PokSignature
 * exists for each of the three required roles (`student`, `school`,
 * `praktijkopleider`) on the POK's current (subjectId, subjectVersion) pair —
 * WEB art. 7.2.8/7.2.9 requires all three parties to sign before the
 * placement starts. When the student was under 18 on the day they signed, or
 * has no date of birth recorded, a parent or guardian listed in the learner's
 * `LearnerProfile.parentIds` must sign too (PokParentSignatureRule). The
 * guard derives that itself; it never reads the POK's
 * `parentSignatureRequired` flag, which a client could write.
 *
 * Mirrors LearningPlanSignatureGuard's shape (constructor-injected
 * ObjectService + LoggerInterface, a `fetchSignatures()` + `indexByRole()`
 * cross-schema query): this guard independently re-derives the same fact the
 * declarative `isFullySigned` calculation on Praktijkovereenkomst expresses —
 * this class is the tested, authoritative enforcement; `isFullySigned` is the
 * declarative read-surface for the frontend. A duplicate signature for the
 * same role still counts as one distinct role toward the three (indexing by
 * role naturally collapses duplicates).
 *
 * ADR-031 legitimate exception: multi-schema guard logic (Praktijkovereenkomst
 * → PokSignature) cannot be expressed as a schema metadata declaration.
 * Referenced from Praktijkovereenkomst's x-openregister-lifecycle `activate`
 * transition's `requires` in learniq_register.json.
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
 * @spec openspec/changes/archive/2026-07-13-bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-all-three-signatures
 * @spec openspec/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\Learniq\Service\PokParentSignatureRule;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the Praktijkovereenkomst `activate` lifecycle transition.
 *
 * A Praktijkovereenkomst may only activate once a PokSignature exists for
 * each of `student`, `school`, and `praktijkopleider` on the current
 * (subjectId, subjectVersion) pair.
 *
 * @spec openspec/changes/archive/2026-07-13-bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-all-three-signatures
 */
class PokActivationGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'The practical training agreement needs the signatures of the student, the school and the workplace trainer.';

	/**
	 * Added when the student was under 18 on the day they signed.
	 *
	 * @var string
	 */
	private const PARENT_FOR_MINOR = 'The student was under 18 when they signed, so a parent or guardian listed on their learner profile '
		. 'also signs the practical training agreement.';

	/**
	 * Added when the student's age cannot be established.
	 *
	 * @var string
	 */
	private const PARENT_FOR_UNKNOWN_AGE = 'The student\'s date of birth is not recorded, so a parent or guardian listed on their learner '
		. 'profile also signs the practical training agreement. Record the date of birth if the student is 18 or older.';

	/**
	 * Learniq register slug.
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * PokSignature schema slug.
	 */
	private const POK_SIGNATURE_SCHEMA = 'pok-signature';

	/**
	 * The three roles required for full signing, per the `bpv` spec.
	 *
	 * @var string[]
	 */
	private const REQUIRED_ROLES = ['student', 'school', 'praktijkopleider'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object query service.
	 * @param LoggerInterface $logger PSR logger.
	 * @param PokParentSignatureRule $parentRule Whether a parent signs, and who may.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly PokParentSignatureRule $parentRule,
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
	 * @spec openspec/changes/archive/2026-07-13-bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-all-three-signatures
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$reason = $this->denialReason(pok: $object);
		if ($reason === null) {
			return GuardResult::allow();
		}

		return GuardResult::deny($reason);
	}//end check()

	/**
	 * The rule behind check(): null when every required signature is there,
	 * otherwise the reason shown to the caller.
	 *
	 * Called by OpenRegister's lifecycle engine before executing the
	 * `activate` transition on a Praktijkovereenkomst object.
	 *
	 * @param array<string,mixed> $pok The object at its target state, transition inputs merged in.
	 *
	 * @return string|null Null allows; a reason blocks the transition (HTTP 422).
	 *
	 * @spec openspec/changes/archive/2026-07-13-bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-all-three-signatures
	 * @spec openspec/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature
	 */
	private function denialReason(array $pok): ?string {
		if ($this->pokId(pok: $pok) === '') {
			$this->logger->warning('[PokActivationGuard] Praktijkovereenkomst has no id; blocking activation.');
			return self::DENIAL;
		}

		$gaps = $this->signatureGaps(pok: $pok);
		if (empty($gaps['roles']) === true && $gaps['parentMissing'] === false) {
			$this->logFullySigned(pok: $pok);
			return null;
		}

		$this->logMissing(pok: $pok, gaps: $gaps);

		return $this->reasonFor(gaps: $gaps);
	}//end denialReason()

	/**
	 * The agreement's id, else its uuid, else ''.
	 *
	 * @param array<string,mixed> $pok The Praktijkovereenkomst.
	 *
	 * @return string
	 */
	private function pokId(array $pok): string {
		return $pok['id'] ?? ($pok['uuid'] ?? '');
	}//end pokId()

	/**
	 * The agreement's version, 1 when it has none.
	 *
	 * @param array<string,mixed> $pok The Praktijkovereenkomst.
	 *
	 * @return int
	 */
	private function pokVersion(array $pok): int {
		return (int)($pok['version'] ?? 1);
	}//end pokVersion()

	/**
	 * What is missing on this version: the standing roles that did not sign,
	 * and whether a required parent signature is missing, with the parent rule's reason.
	 *
	 * @param array<string,mixed> $pok The Praktijkovereenkomst.
	 *
	 * @return array{roles: array<int, string>, parentMissing: bool, parentReason: string}
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature
	 */
	private function signatureGaps(array $pok): array {
		$signatures = $this->fetchSignatures(pok: $pok);
		$missing = array_diff(self::REQUIRED_ROLES, $this->signedRoles(signatures: $signatures));

		$parent = $this->parentRule->evaluate(pok: $pok, studentSignedAt: $this->parentRule->studentSignedAt(signatures: $signatures));

		return [
			'roles' => $missing,
			'parentMissing' => $parent['required'] === true
				&& $this->aListedParentSigned(signatures: $signatures, parentIds: $parent['parentIds']) === false,
			'parentReason' => $parent['reason'],
		];
	}//end signatureGaps()

	/**
	 * Log an agreement version that every required signature is on.
	 *
	 * @param array<string,mixed> $pok The Praktijkovereenkomst.
	 *
	 * @return void
	 */
	private function logFullySigned(array $pok): void {
		$this->logger->info(
			'[PokActivationGuard] Praktijkovereenkomst {id} v{v} fully signed — allowing activation.',
			['id' => $this->pokId(pok: $pok), 'v' => $this->pokVersion(pok: $pok)]
		);
	}//end logFullySigned()

	/**
	 * Log what an agreement version still misses.
	 *
	 * @param array<string,mixed>                                                      $pok  The Praktijkovereenkomst.
	 * @param array{roles: array<int, string>, parentMissing: bool, parentReason: string} $gaps What is missing.
	 *
	 * @return void
	 */
	private function logMissing(array $pok, array $gaps): void {
		$this->logger->info(
			'[PokActivationGuard] Praktijkovereenkomst {id} v{v} missing signatures for role(s): {missing}; parent missing: {parent} ({reason}).',
			[
				'id' => $this->pokId(pok: $pok),
				'v' => $this->pokVersion(pok: $pok),
				'missing' => implode(', ', $gaps['roles']),
				'parent' => var_export($gaps['parentMissing'], true),
				'reason' => $gaps['parentReason'],
			]
		);
	}//end logMissing()

	/**
	 * The refusal text for what is missing.
	 *
	 * @param array{roles: array<int, string>, parentMissing: bool, parentReason: string} $gaps What is missing.
	 *
	 * @return string
	 */
	private function reasonFor(array $gaps): string {
		$parts = [];
		if (empty($gaps['roles']) === false) {
			$parts[] = self::DENIAL;
		}

		if ($gaps['parentMissing'] === true) {
			$parentSentence = self::PARENT_FOR_MINOR;
			if ($gaps['parentReason'] === PokParentSignatureRule::REASON_UNKNOWN_AGE) {
				$parentSentence = self::PARENT_FOR_UNKNOWN_AGE;
			}

			$parts[] = $parentSentence;
		}

		return implode(' ', $parts);
	}//end reasonFor()

	/**
	 * The distinct roles among the three standing roles that have signed.
	 *
	 * @param array<int, array<string, mixed>> $signatures PokSignature rows for this version.
	 *
	 * @return string[]
	 */
	private function signedRoles(array $signatures): array {
		$roles = [];
		foreach ($signatures as $row) {
			$role = $row['signerRole'] ?? null;
			if (is_string($role) === true && in_array($role, $roles, strict: true) === false) {
				$roles[] = $role;
			}
		}

		return $roles;
	}//end signedRoles()

	/**
	 * Whether a `parent` signature comes from one of the learner's listed parents.
	 *
	 * @param array<int, array<string, mixed>> $signatures PokSignature rows for this version.
	 * @param array<int, string> $parentIds The learner's LearnerProfile.parentIds.
	 *
	 * @return bool
	 */
	private function aListedParentSigned(array $signatures, array $parentIds): bool {
		foreach ($signatures as $row) {
			if (($row['signerRole'] ?? null) === 'parent' && in_array((string)($row['signerId'] ?? ''), $parentIds, true) === true) {
				return true;
			}
		}

		return false;
	}//end aListedParentSigned()

	/**
	 * Fetch the PokSignatures on this POK version.
	 *
	 * @param array<string,mixed> $pok The Praktijkovereenkomst: its id, version and tenant.
	 *
	 * @return array<int, array<string, mixed>> The matching PokSignature rows.
	 *
	 * @spec openspec/changes/archive/2026-07-13-bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-all-three-signatures
	 */
	private function fetchSignatures(array $pok): array {
		$raw = $this->objectService->findAll(
			[
				'filters' => array_merge(
					$this->signatureFilters(pok: $pok),
					[
						'register' => self::LEARNIQ_REGISTER,
						'schema' => self::POK_SIGNATURE_SCHEMA,
					]
				),
				'limit' => 200,
			]
		);

		return $this->signatureRows(raw: $raw);
	}//end fetchSignatures()

	/**
	 * The signature filters of this version, scoped to its tenant when it has one.
	 *
	 * @param array<string,mixed> $pok The Praktijkovereenkomst.
	 *
	 * @return array<string, mixed>
	 */
	private function signatureFilters(array $pok): array {
		$tenantId = $pok['tenant_id'] ?? '';
		$filters = ['subjectId' => $this->pokId(pok: $pok), 'subjectVersion' => $this->pokVersion(pok: $pok)];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		return $filters;
	}//end signatureFilters()

	/**
	 * The found signatures as arrays; anything that is not one is left out.
	 *
	 * @param array<int, mixed> $raw What findAll() answered.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function signatureRows(array $raw): array {
		$rows = [];
		foreach ($raw as $item) {
			$row = $item;
			if (is_array($item) === false) {
				$row = $item->jsonSerialize();
			}

			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end signatureRows()
}//end class
