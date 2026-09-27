<?php

/**
 * Learniq POK Activation Guard
 *
 * Lifecycle guard for the Praktijkovereenkomst schema's `activate` transition
 * (pending-signatures → active). Blocks activation unless a PokSignature
 * exists for each of the three required roles (`student`, `school`,
 * `praktijkopleider`) on the POK's current (subjectId, subjectVersion) pair —
 * WEB art. 7.2.8/7.2.9 requires all three parties to sign before the
 * placement starts.
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
 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-all-three-signatures
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

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
 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-all-three-signatures
 */
class PokActivationGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'The practical training agreement needs the signatures of the student, the school and the workplace trainer.';

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
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
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
	 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-all-three-signatures
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->allows(pok: $object) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::DENIAL);
	}//end check()

	/**
	 * The rule behind check(), answered as a boolean.
	 *
	 * Called by OpenRegister's lifecycle engine before executing the
	 * `activate` transition on a Praktijkovereenkomst object.
	 *
	 * @param array<string,mixed> $pok The object at its target state, transition inputs merged in.
	 *
	 * @return bool True when all three required roles have a PokSignature for this version;
	 *              false blocks the transition (HTTP 422).
	 *
	 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-all-three-signatures
	 */
	private function allows(array $pok): bool {
		$pokId = $pok['id'] ?? ($pok['uuid'] ?? '');
		$version = (int)($pok['version'] ?? 1);
		$tenantId = $pok['tenant_id'] ?? '';

		if ($pokId === '') {
			$this->logger->warning('[PokActivationGuard] Praktijkovereenkomst has no id; blocking activation.');
			return false;
		}

		$signedRoles = $this->fetchSignedRoles(pokId: $pokId, version: $version, tenantId: $tenantId);

		$missing = array_diff(self::REQUIRED_ROLES, $signedRoles);

		if (empty($missing) === false) {
			$this->logger->info(
				'[PokActivationGuard] Praktijkovereenkomst {id} v{v} missing signatures for role(s): {missing}.',
				['id' => $pokId, 'v' => $version, 'missing' => implode(', ', $missing)]
			);
			return false;
		}

		$this->logger->info(
			'[PokActivationGuard] Praktijkovereenkomst {id} v{v} fully signed — allowing activation.',
			['id' => $pokId, 'v' => $version]
		);

		return true;
	}//end allows()

	/**
	 * Fetch the distinct set of signerRoles that have signed this POK version.
	 *
	 * @param string $pokId Praktijkovereenkomst UUID.
	 * @param int $version Praktijkovereenkomst version.
	 * @param string $tenantId Tenant UUID scope filter.
	 *
	 * @return string[] Distinct signerRole values present among the matching PokSignatures.
	 *
	 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-all-three-signatures
	 */
	private function fetchSignedRoles(string $pokId, int $version, string $tenantId = ''): array {
		$filters = ['subjectId' => $pokId, 'subjectVersion' => $version];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$raw = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => self::POK_SIGNATURE_SCHEMA,
				'filters' => $filters,
				'limit' => 200,
			]
		);

		$roles = [];
		foreach ($raw as $item) {
			$row = $item;
			if (is_array($item) === false) {
				$row = $item->jsonSerialize();
			}

			$role = $row['signerRole'] ?? null;
			if ($role !== null && in_array($role, $roles, strict: true) === false) {
				$roles[] = $role;
			}
		}

		return $roles;
	}//end fetchSignedRoles()
}//end class
