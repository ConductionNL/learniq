<?php

/**
 * Learniq Portal Claim Scope Listener
 *
 * Portaliq asks, before an account's claim scopes a read, a write or a change
 * notice, whether the app that owns the claim lets it stand for the account's
 * organisation (portaliq's PortalClaimScopeEvent). Learniq refuses its
 * employer claim `organisationRef` when the company it names does not lie in
 * the organisation of the account's portal, and its `editionLocationRef`
 * when that location does not, so an account that carries another tenant's
 * company or location reads and changes nothing of it.
 *
 * The event is portaliq's. Neither the registration nor the instanceof
 * check loads its class, so learniq still loads where portaliq is absent.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Portal\EmployerCompanyTenancy;
use OCA\Learniq\Portal\EmployerSitePages;
use OCA\Portaliq\Event\PortalClaimScopeEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Refuses an employer claim that names another tenant's company or location.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
 */
class PortalClaimScopeListener implements IEventListener {

	/**
	 * Portaliq's claim scope event. Naming the class does not load it.
	 */
	public const EVENT = PortalClaimScopeEvent::class;

	/**
	 * Constructor.
	 *
	 * @param EmployerCompanyTenancy $tenancy Checks the company or location lies in the portal's organisation.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly EmployerCompanyTenancy $tenancy,
	) {
	}//end __construct()

	/**
	 * Refuse learniq's employer claims when their company or location lies in another organisation.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
	 */
	public function handle(Event $event): void {
		if ($event instanceof PortalClaimScopeEvent === false || $event->getAppId() !== Application::APP_ID) {
			return;
		}

		if ($this->stands(event: $event) === false) {
			$event->refuse();
		}
	}//end handle()

	/**
	 * Whether the claim may stand: learniq's claims other than the company and the location always do.
	 *
	 * @param PortalClaimScopeEvent $event The claim check.
	 *
	 * @return bool
	 */
	private function stands(PortalClaimScopeEvent $event): bool {
		return match ($event->getClaimName()) {
			EmployerSitePages::CLAIM => $this->tenancy->holds(organisationRef: $event->getValue(), organisation: $event->getOrganisation()),
			EmployerSitePages::LOCATION_CLAIM => $this->tenancy->holdsLocation(locationRef: $event->getValue(), organisation: $event->getOrganisation()),
			default => true,
		};
	}//end stands()
}//end class
