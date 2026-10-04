<?php

/**
 * Deferred group-name re-stamp after a cohort rename.
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
 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-the-child-switcher-shows-the-childs-group
 */

declare(strict_types=1);

namespace OCA\Learniq\BackgroundJob;

use OCA\Learniq\Service\CohortNameRestamp;
use OCA\OpenRegister\BackgroundJob\ActorForwardedJob;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Writes a renamed group's name on its enrolments. CohortNameCascade buffers
 * one entry per rename; the last name buffered for a group wins.
 *
 * @psalm-suppress UnusedClass Enqueued by ListenerDeferralService at request
 *  shutdown, never constructed by name.
 *
 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-the-child-switcher-shows-the-childs-group
 */
class CohortNameRestampJob extends ActorForwardedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory        $time         Clock, for the base job.
	 * @param IUserSession        $userSession  Actor forwarding, for the base job.
	 * @param IUserManager        $userManager  Actor forwarding, for the base job.
	 * @param OrganisationService $organisation Tenant context, for the base job.
	 * @param LoggerInterface     $logger       Logger; the base declares it protected.
	 * @param CohortNameRestamp   $restamp      Writes the name on the enrolments.
	 */
	public function __construct(
		ITimeFactory $time,
		IUserSession $userSession,
		IUserManager $userManager,
		OrganisationService $organisation,
		LoggerInterface $logger,
		private readonly CohortNameRestamp $restamp,
	) {
		parent::__construct(
			time: $time,
			userSession: $userSession,
			userManager: $userManager,
			organisation: $organisation,
			logger: $logger
		);
	}//end __construct()

	/**
	 * Re-stamp each renamed group once, with the last name buffered for it.
	 *
	 * @param DeferredListenerContext $context The buffered entries.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-the-child-switcher-shows-the-childs-group
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		$names = [];
		foreach ($context->getEntries() as $entry) {
			$cohortId = ($entry['cohortId'] ?? null);
			$name = ($entry['name'] ?? null);
			if (is_string($cohortId) === true && $cohortId !== '' && is_string($name) === true) {
				$names[$cohortId] = $name;
			}
		}

		foreach ($names as $cohortId => $name) {
			$this->restamp->restamp(cohortId: (string)$cohortId, name: $name);
		}
	}//end runDeferred()
}//end class
