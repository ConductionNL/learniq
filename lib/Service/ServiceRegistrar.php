<?php

/**
 * Registration entry point of the Service component
 *
 * @category  Service
 * @package   OCA\Learniq\Service
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

namespace OCA\Learniq\Service;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * What the Service component registers with the container: the bindings of
 * the Kernel contracts its classes implement.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural composition, no functional requirement.
 */
final class ServiceRegistrar {

	/**
	 * Register the Service component.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec exclude Structural composition, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		(new ContractBindings())->register(context: $context);
	}//end register()
}//end class
