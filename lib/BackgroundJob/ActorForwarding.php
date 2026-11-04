<?php

/**
 * What a deferred job needs to run as the user who caused it.
 *
 * @category BackgroundJob
 * @package  OCA\Learniq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
 */

declare(strict_types=1);

namespace OCA\Learniq\BackgroundJob;

use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * The clock, user session, user manager and tenant context that OpenRegister's
 * ActorForwardedJob takes to re-establish the captured actor.
 *
 * @psalm-suppress UndefinedClass OrganisationService is OpenRegister's, outside
 *  the analysis path like the ActorForwardedJob it is handed to.
 *
 * @spec openspec/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
 */
final class ActorForwarding {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory        $time         Clock.
	 * @param IUserSession        $userSession  Sets and restores the actor.
	 * @param IUserManager        $userManager  Looks the captured actor up.
	 * @param OrganisationService $organisation Tenant context.
	 */
	public function __construct(
		public readonly ITimeFactory $time,
		public readonly IUserSession $userSession,
		public readonly IUserManager $userManager,
		public readonly OrganisationService $organisation,
	) {
	}//end __construct()
}//end class
