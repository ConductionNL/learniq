<?php

/**
 * Minimal OrganisationService stub for Learniq unit tests.
 *
 * Present so ActorForwardedJob's constructor signature resolves, and for the
 * one method learniq calls, `ensureDefaultOrganisation()` (openregister
 * development 45bf4d47a0, lib/Service/OrganisationService.php).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Organisation;

/**
 * Tenant/organisation context.
 */
abstract class OrganisationService {

	/**
	 * Ensure the default organisation exists, creating it when needed.
	 *
	 * @return Organisation The default organisation.
	 */
	abstract public function ensureDefaultOrganisation(): Organisation;
}//end class
