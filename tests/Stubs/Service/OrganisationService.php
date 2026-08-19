<?php

/**
 * Test stub for OCA\OpenRegister\Service\OrganisationService.
 *
 * THIS STUB IS A MIRROR, NOT A CONVENIENCE — same rule as
 * tests/Stubs/Service/ObjectService.php. Standalone, the
 * `OCA\OpenRegister\ => tests/Stubs/` PSR-4 mapping resolves the name here; in
 * CI the real openregister app sits next to this one and Nextcloud's autoloader
 * resolves the real class instead. Anything asserted against the stub must hold
 * against the real class too.
 *
 * Only the surface Scholiq actually touches is mirrored. Scholiq never CALLS
 * this service: it appears solely as a constructor parameter type on
 * `ActorForwardedJob` (and therefore on
 * `OCA\Scholiq\BackgroundJob\DeferredObjectListenerJob`), which passes it
 * straight through to the parent. `getActiveOrganisation()` is declared because
 * the real base class calls it when logging organisation drift, so a mock built
 * from this stub must be able to answer it.
 *
 * It is deliberately NOT abstract: `DeferredJobDrain` builds a mock of it with
 * `disableOriginalConstructor()`, and the real class is concrete.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Scholiq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

if (class_exists(OrganisationService::class) === false) {
	/**
	 * Mirror of OpenRegister's OrganisationService for standalone Scholiq unit tests.
	 */
	class OrganisationService {

		/**
		 * The acting user's currently active organisation.
		 *
		 * @param array<int,mixed>|null $preloadedOrgs Pre-resolved organisations, when the caller has them.
		 *
		 * @return object|null The active organisation, or null when there is none.
		 */
		public function getActiveOrganisation(?array $preloadedOrgs = null): ?object {
			return null;
		}//end getActiveOrganisation()

		/**
		 * The default organisation uuid for this instance.
		 *
		 * @return string|null The uuid, or null when none is configured.
		 */
		public function getDefaultOrganisationUuid(): ?string {
			return null;
		}//end getDefaultOrganisationUuid()
	}
}
