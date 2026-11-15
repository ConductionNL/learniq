<?php

/**
 * Bindings of the Kernel contracts to their Portal implementations
 *
 * @category  Portal
 * @package   OCA\Learniq\Portal
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural composition, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use OCA\Learniq\Kernel\Contracts\BpvPortalInvitationInterface;
use OCA\Learniq\Kernel\Contracts\CallerOrganisationsInterface;
use OCA\Learniq\Kernel\Contracts\EmployerCompanyTenancyInterface;
use OCA\Learniq\Kernel\Contracts\EmployerPortalInvitationInterface;
use OCA\Learniq\Kernel\Contracts\EmployerSitePagesInterface;
use OCA\Learniq\Kernel\Contracts\ExamplePortalAccountGrantsInterface;
use OCA\Learniq\Kernel\Contracts\ExamplePortalProvisionerInterface;
use OCA\Learniq\Kernel\Contracts\GuardianPortalInvitationInterface;
use OCA\Learniq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Learniq\Kernel\Contracts\PortalTenantsInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Nextcloud autowires concrete classes but not interfaces, so every contract
 * in OCA\Learniq\Kernel\Contracts that a caller type-hints needs an alias to
 * the class that implements it. An alias resolves to the same shared instance
 * as the implementation itself.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec exclude Structural composition, no functional requirement.
 */
final class PortalContractBindings {

	/**
	 * Contract => the class that implements it.
	 *
	 * @var array<class-string, class-string>
	 */
	private const BINDINGS = [
		BpvPortalInvitationInterface::class => BpvPortalInvitation::class,
		CallerOrganisationsInterface::class => CallerOrganisations::class,
		EmployerCompanyTenancyInterface::class => EmployerCompanyTenancy::class,
		EmployerPortalInvitationInterface::class => EmployerPortalInvitation::class,
		EmployerSitePagesInterface::class => EmployerSitePages::class,
		ExamplePortalAccountGrantsInterface::class => ExamplePortalAccountGrants::class,
		ExamplePortalProvisionerInterface::class => ExamplePortalProvisioner::class,
		GuardianPortalInvitationInterface::class => GuardianPortalInvitation::class,
		PortalAssertionVerifierInterface::class => PortalAssertionVerifier::class,
		PortalTenantsInterface::class => PortalTenants::class,
	];

	/**
	 * Alias every contract to its implementation.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec exclude Structural composition, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		foreach (self::BINDINGS as $contract => $implementation) {
			$context->registerServiceAlias($contract, $implementation);
		}
	}//end register()
}//end class
