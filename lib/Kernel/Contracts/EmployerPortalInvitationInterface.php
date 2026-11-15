<?php

/**
 * Contract of EmployerPortalInvitation
 *
 * The public surface of EmployerPortalInvitation that other components call, so they depend
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
 * Contract of EmployerPortalInvitation.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EmployerPortalInvitationInterface {

	/**
	 * Invite one company's contact person, reading the company as the system.
	 *
	 * For the occ commands, which run without a caller. The company must still
	 * lie in the portal's organisation.
	 *
	 * @param string       $organisationRef The client-organisation uuid.
	 * @param PortalTenant $tenant          The portal organisation.
	 * @param string       $email           An address to use instead of the one on the company.
	 *
	 * @return array{status: string, reason?: string, subjectRef?: string}
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
	 */
	public function invite(string $organisationRef, PortalTenant $tenant, string $email=''): array;

	/**
	 * Invite one company's contact person for the signed-in caller.
	 *
	 * The company is read with the caller's rights, and a company the caller
	 * cannot see answers exactly like an unknown one. invite() reads as the
	 * system, for the occ commands that run without a caller.
	 *
	 * @param string       $organisationRef The client-organisation uuid.
	 * @param PortalTenant $tenant          The portal organisation, one of the caller's own.
	 * @param string       $email           An address to use instead of the one on the company.
	 *
	 * @return array{status: string, reason?: string, subjectRef?: string}
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
	 */
	public function inviteAsCaller(string $organisationRef, PortalTenant $tenant, string $email=''): array;
}//end interface
