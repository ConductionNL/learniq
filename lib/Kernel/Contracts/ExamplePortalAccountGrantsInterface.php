<?php

/**
 * Contract of ExamplePortalAccountGrants
 *
 * The public surface of ExamplePortalAccountGrants that other components call, so they depend
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
 * Contract of ExamplePortalAccountGrants.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExamplePortalAccountGrantsInterface {

	/**
	 * Give every declared account with a `portal` entry its portal account.
	 *
	 * - `granted`: portaliq wrote the account or a missing claim.
	 * - `kept`: the account already had the audience and every claim; nothing
	 *   was asked, so a second load writes nothing.
	 * - `waiting`: the portal has no organisation yet. Binding one is a
	 *   deployment step; load the set again after setting it.
	 * - `failed`: portaliq refused, with the reasons in `reasons`.
	 *
	 * `status` is `done`, `none` (nothing declared), `portaliq-absent` or
	 * `portaliq-too-old`.
	 *
	 * @param string $profileId The example set id.
	 *
	 * @return array{status: string, granted: int, kept: int, waiting: int, failed: int, reasons: array<int, string>}
	 *
	 * @spec openspec/changes/example-portal-install-steps/specs/example-sets/spec.md#requirement-loading-a-set-gives-its-declared-learners-a-portal-account
	 */
	public function grant(string $profileId): array;
}//end interface
