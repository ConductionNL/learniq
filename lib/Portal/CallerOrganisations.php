<?php
/**
 * Learniq caller organisations.
 *
 * The portal organisations the signed-in staff user belongs to. A portal
 * organisation slug is the slug of an OpenRegister organisation (portaliq
 * resolves `?org=` through OpenRegister's organisation mapper), so the
 * caller's OpenRegister memberships say which portals they may invite into
 * (security review L5).
 *
 * OpenRegister is reached through the container by class name, the way the
 * portal events are, so learniq still loads where it is absent; then the
 * caller belongs to no organisation and every invitation is refused.
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
 * @spec openspec/changes/portal-guardian-invitation-letter/specs/portal-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The OpenRegister organisation slugs of the signed-in user.
 *
 * @spec openspec/changes/portal-guardian-invitation-letter/specs/portal-identity/spec.md
 */
class CallerOrganisations {

	/**
	 * OpenRegister's organisation service, by class name.
	 */
	public const ORGANISATION_SERVICE = 'OCA\\OpenRegister\\Service\\OrganisationService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Reaches OpenRegister when it is installed.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

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
	public function includes(string $slug): bool {
		$slug = trim($slug);
		if ($slug === '') {
			return false;
		}

		return in_array($slug, $this->slugs(), true);
	}//end includes()

	/**
	 * The slugs of the signed-in user's organisations, or [] when
	 * OpenRegister cannot answer.
	 *
	 * @return array<int, string>
	 */
	private function slugs(): array {
		try {
			$service = $this->container->get(self::ORGANISATION_SERVICE);
			$organisations = $service->getUserOrganisations();
		} catch (Throwable $exception) {
			$this->logger->warning('[CallerOrganisations] OpenRegister could not list the organisations: {msg}', ['msg' => $exception->getMessage()]);
			return [];
		}

		$slugs = [];
		foreach ((array)$organisations as $organisation) {
			if (is_object($organisation) === true && method_exists($organisation, 'getSlug') === true) {
				$slugs[] = (string)$organisation->getSlug();
			}
		}

		return $slugs;
	}//end slugs()
}//end class
