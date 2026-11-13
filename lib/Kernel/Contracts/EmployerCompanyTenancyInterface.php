<?php

/**
 * Contract of EmployerCompanyTenancy
 *
 * The public surface of EmployerCompanyTenancy that other components call, so they depend
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
 * Contract of EmployerCompanyTenancy.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EmployerCompanyTenancyInterface {

	/**
	 * The company lies in the portal's organisation.
	 */
	public const HOLDS = 'holds';

	/**
	 * Whether the company lies in the portal organisation with this slug.
	 *
	 * @param string $organisationRef The company's `client-organisation` uuid.
	 * @param string $organisation    The portal organisation slug.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
	 */
	public function holds(string $organisationRef, string $organisation): bool;

	/**
	 * Whether the institute location lies in the portal organisation with this slug.
	 *
	 * @param string $locationRef  The location's `vestiging` uuid.
	 * @param string $organisation The portal organisation slug.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
	 */
	public function holdsLocation(string $locationRef, string $organisation): bool;

	/**
	 * Why the company does or does not lie in the portal organisation.
	 *
	 * @param string $organisationRef The company's `client-organisation` uuid.
	 * @param string $organisation    The portal organisation slug.
	 *
	 * @return string One of the verdict constants.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
	 */
	public function verdict(string $organisationRef, string $organisation): string;
}//end interface
