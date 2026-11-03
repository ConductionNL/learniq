<?php

/**
 * Portaliq PortalClaimScopeEvent test stub.
 *
 * Mirrors portaliq's lib/Event/PortalClaimScopeEvent.php: the same name,
 * constructor and accessors. PortalClaimScopeListener matches it by class
 * name, so learniq stays installable without portaliq; tests/bootstrap.php
 * loads this stub only when the real class is absent.
 *
 * @category Tests
 * @package  OCA\Portaliq\Event
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Event;

use OCP\EventDispatcher\Event;

/**
 * Asks the app that owns a claim whether it may scope for this organisation.
 */
class PortalClaimScopeEvent extends Event {

	/**
	 * Whether a listener refused the claim.
	 *
	 * @var boolean
	 */
	private bool $refused = false;

	/**
	 * Constructor.
	 *
	 * @param array{appId: string, claimName: string}    $claim  The claim's app and name.
	 * @param array{value: string, organisation: string} $holder The claim value and the account's portal organisation slug.
	 */
	public function __construct(
		private readonly array $claim,
		private readonly array $holder,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The app whose claim it is.
	 *
	 * @return string
	 */
	public function getAppId(): string {
		return $this->claim['appId'];
	}//end getAppId()

	/**
	 * The claim name.
	 *
	 * @return string
	 */
	public function getClaimName(): string {
		return $this->claim['claimName'];
	}//end getClaimName()

	/**
	 * The value the account holds.
	 *
	 * @return string
	 */
	public function getValue(): string {
		return $this->holder['value'];
	}//end getValue()

	/**
	 * The account's portal organisation slug.
	 *
	 * @return string
	 */
	public function getOrganisation(): string {
		return $this->holder['organisation'];
	}//end getOrganisation()

	/**
	 * Refuse the claim.
	 *
	 * @return void
	 */
	public function refuse(): void {
		$this->refused = true;
	}//end refuse()

	/**
	 * Whether a listener refused the claim.
	 *
	 * @return bool
	 */
	public function isRefused(): bool {
		return $this->refused;
	}//end isRefused()
}//end class
