<?php

/**
 * Learniq PortalTenants
 *
 * Finds the OpenRegister organisation of a portal organisation slug, the way
 * portaliq resolves `?org=`: through OpenRegister's organisation mapper. It is
 * a system lookup for callers without a signed-in staff user, such as the occ
 * commands and the portal's signed assertions. OpenRegister is reached through
 * the container by class name, so learniq still loads where it is absent; then
 * no slug resolves and every check that needs a tenant refuses.
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

use OCA\Learniq\Kernel\Contracts\PortalTenantsInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The tenant of a portal organisation slug.
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
 */
class PortalTenants implements PortalTenantsInterface {
	use ReadsOrganisationTenant;

	/**
	 * OpenRegister's organisation mapper, by class name.
	 */
	public const ORGANISATION_MAPPER = 'OCA\\OpenRegister\\Db\\OrganisationMapper';

	/**
	 * Tenants already found in this request, by slug.
	 *
	 * @var array<string, PortalTenant|null>
	 */
	private array $found = [];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Reaches OpenRegister when it is installed.
	 * @param LoggerInterface    $logger    PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The tenant whose OpenRegister organisation has this slug.
	 *
	 * @param string $slug The portal organisation slug.
	 *
	 * @return PortalTenant|null Null for an empty or unknown slug, and when OpenRegister cannot answer.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
	 */
	public function bySlug(string $slug): ?PortalTenant {
		$slug = trim($slug);
		if ($slug === '') {
			return null;
		}

		if (array_key_exists($slug, $this->found) === false) {
			$this->found[$slug] = $this->lookUp(slug: $slug);
		}

		return $this->found[$slug];
	}//end bySlug()

	/**
	 * Ask OpenRegister for the organisation with this slug.
	 *
	 * @param string $slug The slug, trimmed and not empty.
	 *
	 * @return PortalTenant|null
	 */
	private function lookUp(string $slug): ?PortalTenant {
		try {
			$organisation = $this->container->get(self::ORGANISATION_MAPPER)->findBySlug($slug);
		} catch (Throwable $exception) {
			$this->logger->info('[PortalTenants] No organisation for slug {slug}: {msg}', ['slug' => $slug, 'msg' => $exception->getMessage()]);
			return null;
		}

		if (is_object($organisation) === false) {
			return null;
		}

		$tenant = $this->tenantOf(organisation: $organisation);
		if ($tenant === null || $tenant->slug !== $slug) {
			return null;
		}

		return $tenant;
	}//end lookUp()
}//end class
