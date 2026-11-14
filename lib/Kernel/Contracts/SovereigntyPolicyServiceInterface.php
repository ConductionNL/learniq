<?php

/**
 * Contract of SovereigntyPolicyService
 *
 * The public surface of SovereigntyPolicyService that other components call, so they depend
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
 * Contract of SovereigntyPolicyService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SovereigntyPolicyServiceInterface {

	/**
	 * Read the school's current locality policy tier.
	 *
	 * Returns the first `SovereigntyPolicy` object's `policy` value, or the
	 * schema-documented default (`eu-hosted-allowed`) when no object exists
	 * yet, the read fails, or the stored value is not one of the three valid
	 * tiers.
	 *
	 * @return string One of `on-premises-only`, `eu-hosted-allowed`, `third-country-allowed`.
	 *
	 * @spec openspec/specs/ai-locality-guarantee/spec.md#scenario-no-policy-set-yet-defaults-to-the-documented-default
	 */
	public function currentPolicy(): string;

	/**
	 * Whether a classified `{locality, verified}` pair complies with the
	 * school's current `SovereigntyPolicy`.
	 *
	 * `unverified` (`verified === false`) MUST NOT satisfy `on-premises-only`
	 * or `eu-hosted-allowed` — it only ever passes under the explicitly
	 * permissive `third-country-allowed` tier.
	 *
	 * | Policy tier            | Compliant when                                        |
	 * |-------------------------|-------------------------------------------------------|
	 * | `on-premises-only`      | `locality === 'on-premises'` AND `verified === true`   |
	 * | `eu-hosted-allowed`     | `locality` in `{on-premises, eu-hosted}` AND `verified === true` |
	 * | `third-country-allowed` | anything, including `unverified`                      |
	 *
	 * @param string $locality One of `on-premises`, `eu-hosted`, `third-country`, `unverified`.
	 * @param bool $verified Whether the locality was code-verified true.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/ai-locality-guarantee/spec.md#requirement-the-system-must-refuse-to-let-an-ai-assisted-feature-take-effect-when-its-verified-or-unverified-locality-violates-the-school-s-policy
	 */
	public function isCompliant(string $locality, bool $verified): bool;
}//end interface
