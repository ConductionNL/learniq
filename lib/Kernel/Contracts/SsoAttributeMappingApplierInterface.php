<?php

/**
 * Contract of SsoAttributeMappingApplier
 *
 * The public surface of SsoAttributeMappingApplier that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

/**
 * Contract of SsoAttributeMappingApplier.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SsoAttributeMappingApplierInterface {

	/**
	 * Compute the LearnerProfile field updates for a set of IdP attributes.
	 *
	 * @param array<string,mixed> $attributes Raw SAML attributes or OIDC claims.
	 * @param string              $provider   'saml' or 'oidc'.
	 * @param string              $tenantId   Tenant ID to scope the mapping lookup.
	 *
	 * @return array<string,mixed> LearnerProfile field updates.
	 *
	 * @spec openspec/specs/identity-federation/spec.md#requirement-apply-attribute-mappings-to-compute-learnerprofile-updates
	 */
	public function apply(array $attributes, string $provider, string $tenantId): array;
}//end interface
