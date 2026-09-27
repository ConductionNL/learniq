<?php

/**
 * Learniq SSO Attribute Mapping Applier
 *
 * Pure, side-effect-free service: given a raw attribute bag (a SAML
 * assertion's attributes or an OIDC token's claims, already authenticated by
 * Nextcloud's own user_saml/user_oidc apps), computes the LearnerProfile
 * field-update array the active SsoAttributeMapping rows describe.
 *
 * This class does NOT write to LearnerProfile, does NOT listen for any
 * Nextcloud login event, and does NOT implement any SAML/OIDC protocol —
 * see the identity-federation capability's Purpose and
 * entree-surfconext-sso-contract's proposal.md "What this change does NOT
 * do". Wiring this service to user_saml/user_oidc's real provisioning event
 * is an explicit, named follow-up once those apps are available to verify
 * their event surface against, the same posture DataExchangeRunHandler's
 * own OPENCONNECTOR_RUN_PATH docblock takes toward an unverified endpoint.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/changes/entree-surfconext-sso-contract/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Computes LearnerProfile field updates from an SSO attribute bag and the
 * active SsoAttributeMapping rows for a given provider.
 *
 * @spec openspec/changes/entree-surfconext-sso-contract/tasks.md#task-2
 */
class SsoAttributeMappingApplier {

	private const LEARNIQ_REGISTER = 'learniq';
	private const SSO_ATTRIBUTE_MAPPING_SCHEMA = 'sso-attribute-mapping';

	/**
	 * The single LearnerProfile field that accumulates rather than
	 * overwrites — every matching `role` mapping adds to this array.
	 */
	private const ROLE_FIELD = 'role';
	private const ROLES_TARGET = 'roles';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Compute the LearnerProfile field-update array for one authenticated
	 * user's attribute bag.
	 *
	 * Loads every active SsoAttributeMapping for the given provider/tenant,
	 * and for each mapping whose externalAttribute is present in $attributes,
	 * writes the mapped LearnerProfile field. `learniqField: role` entries
	 * accumulate into a `roles` array instead of overwriting one another.
	 * An attribute with no matching active mapping contributes nothing and
	 * raises no error (fail-closed: never invents a field).
	 *
	 * @param array<string,mixed> $attributes Raw SAML attributes or OIDC claims.
	 * @param string $provider 'saml' or 'oidc'.
	 * @param string $tenantId Tenant ID to scope the mapping lookup.
	 *
	 * @return array<string,mixed> LearnerProfile field updates. Never writes to LearnerProfile itself.
	 *
	 * @spec openspec/changes/entree-surfconext-sso-contract/specs/identity-federation/spec.md#requirement-apply-attribute-mappings-to-compute-learnerprofile-updates
	 */
	public function apply(array $attributes, string $provider, string $tenantId): array {
		$mappings = $this->loadActiveMappings(provider: $provider, tenantId: $tenantId);

		$updates = [];
		foreach ($mappings as $mapping) {
			$externalAttribute = (string)($mapping['externalAttribute'] ?? '');
			if ($externalAttribute === '' || array_key_exists($externalAttribute, $attributes) === false) {
				continue;
			}

			$learniqField = (string)($mapping['learniqField'] ?? '');
			$value = $attributes[$externalAttribute];

			if ($learniqField === self::ROLE_FIELD) {
				$this->applyRoleMapping(mapping: $mapping, value: $value, updates: $updates);
				continue;
			}

			if ($learniqField === '') {
				continue;
			}

			$updates[$learniqField] = $value;
		}//end foreach

		return $updates;
	}//end apply()

	/**
	 * Apply one `learniqField: role` mapping: add its `roleValue` to the
	 * accumulating `roles` array when the attribute's actual value equals
	 * the mapping's `externalValue`. No-op (fail-closed) when either
	 * `externalValue` or `roleValue` is unset, or the value does not match.
	 *
	 * @param array<string,mixed> $mapping The SsoAttributeMapping row being evaluated.
	 * @param mixed $value The attribute's actual value from the caller's attribute bag.
	 * @param array<string,mixed> $updates The accumulating update array, mutated in place.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/entree-surfconext-sso-contract/specs/identity-federation/spec.md#scenario-multiple-role-mappings-accumulate-into-one-roles-array
	 */
	private function applyRoleMapping(array $mapping, mixed $value, array &$updates): void {
		$externalValue = $mapping['externalValue'] ?? null;
		$roleValue = $mapping['roleValue'] ?? null;

		if (empty($externalValue) === true || empty($roleValue) === true) {
			return;
		}

		if ($value !== $externalValue) {
			return;
		}

		$updates[self::ROLES_TARGET][] = $roleValue;
	}//end applyRoleMapping()

	/**
	 * Load every active SsoAttributeMapping for the given provider/tenant.
	 *
	 * @param string $provider 'saml' or 'oidc'.
	 * @param string $tenantId Tenant ID to enforce as a mandatory filter.
	 *
	 * @return array<int,array<string,mixed>> Active mapping rows.
	 *
	 * @spec openspec/changes/entree-surfconext-sso-contract/tasks.md#task-2
	 */
	private function loadActiveMappings(string $provider, string $tenantId): array {
		$filters = [
			'provider' => $provider,
			'lifecycle' => 'active',
		];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$results = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => self::SSO_ATTRIBUTE_MAPPING_SCHEMA,
				'filters' => $filters,
			]
		);

		$mappings = [];
		foreach ($results as $result) {
			if (is_array($result) === true) {
				$mappings[] = $result;
				continue;
			}

			$mappings[] = $result->jsonSerialize();
		}

		return $mappings;
	}//end loadActiveMappings()
}//end class
