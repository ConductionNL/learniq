<?php

/**
 * Contract of CallerOrganisations
 *
 * The public surface of CallerOrganisations that other components call, so they depend
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

use OCA\Learniq\Portal\PortalTenant;

/**
 * Contract of CallerOrganisations.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CallerOrganisationsInterface {

	/**
	 * Whether the signed-in user belongs to the organisation with this slug.
	 *
	 * @param string $slug The portal organisation slug.
	 *
	 * @return bool False for an empty slug, a slug the user does not belong
	 *              to, and when OpenRegister cannot answer.
	 *
	 * @spec openspec/changes/portal-guardian-invitation-letter/specs/portal-identity/spec.md
	 */
	public function includes(string $slug): bool;

	/**
	 * The signed-in user's organisation with this slug, with its OpenRegister uuid.
	 *
	 * @param string $slug The portal organisation slug.
	 *
	 * @return PortalTenant|null Null for an empty slug, a slug the user does not
	 *                           belong to, and when OpenRegister cannot answer.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
	 */
	public function tenant(string $slug): ?PortalTenant;
}//end interface
