<?php

/**
 * Learniq ReadsOrganisationTenant
 *
 * Turns an OpenRegister Organisation entity into a PortalTenant, for the
 * classes that look organisations up.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * The tenant an OpenRegister organisation stands for.
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
 */
trait ReadsOrganisationTenant {

	/**
	 * The tenant for an OpenRegister organisation, or null when it lacks a slug or a uuid.
	 *
	 * @param object $organisation An OpenRegister Organisation entity.
	 *
	 * @return PortalTenant|null
	 */
	private function tenantOf(object $organisation): ?PortalTenant {
		// The accessors of an OpenRegister Entity are answered by __call, which
		// is_callable() sees and method_exists() does not.
		if (is_callable([$organisation, 'getSlug']) === false || is_callable([$organisation, 'getUuid']) === false) {
			return null;
		}

		$slug = trim((string)$organisation->getSlug());
		$uuid = trim((string)$organisation->getUuid());
		if ($slug === '' || $uuid === '') {
			return null;
		}

		return new PortalTenant(slug: $slug, uuid: $uuid);
	}//end tenantOf()
}//end trait
