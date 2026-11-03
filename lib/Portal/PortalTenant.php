<?php

/**
 * Learniq PortalTenant
 *
 * A portal organisation: its slug, which portaliq and the portal accounts
 * carry, and the uuid of the OpenRegister organisation with that slug, which
 * OpenRegister stamps on every object as `@self.organisation`. A company
 * belongs to a portal only when its `@self.organisation` is this uuid.
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
 * A portal organisation slug with its OpenRegister organisation uuid.
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
 */
final class PortalTenant {

	/**
	 * Constructor.
	 *
	 * @param string $slug The portal organisation slug.
	 * @param string $uuid The OpenRegister organisation uuid.
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $uuid,
	) {
	}//end __construct()

	/**
	 * Whether an OpenRegister row belongs to this tenant by its `@self.organisation`.
	 *
	 * @param array<string, mixed> $row The row as OpenRegister serialises it.
	 *
	 * @return bool False for a row without an organisation.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
	 */
	public function owns(array $row): bool {
		$self = ($row['@self'] ?? null);
		if (is_array($self) === false) {
			return false;
		}

		return $this->uuid !== '' && ($self['organisation'] ?? null) === $this->uuid;
	}//end owns()
}//end class
