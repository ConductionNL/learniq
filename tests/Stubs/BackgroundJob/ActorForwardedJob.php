<?php

/**
 * Minimal ActorForwardedJob stub for Learniq unit tests.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Base for a job enqueued by ListenerDeferralService.
 *
 * Parameter names MUST match canonical OpenRegister
 * (`lib/BackgroundJob/ActorForwardedJob.php` on origin/development) exactly —
 * subclasses call `parent::__construct()` with named arguments, which bind by
 * NAME, so an invented name here would make an `Unknown named parameter`
 * runtime error invisible to every static tool and to the tests.
 *
 * `$logger` is `protected` on the canonical class and subclasses read
 * `$this->logger`; a stub that made it private would fail only at runtime.
 */
abstract class ActorForwardedJob {

	/**
	 * @param ITimeFactory        $time         Clock.
	 * @param IUserSession        $userSession  Actor forwarding.
	 * @param IUserManager        $userManager  Actor forwarding.
	 * @param OrganisationService $organisation Tenant context.
	 * @param LoggerInterface     $logger       Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly OrganisationService $organisation,
		protected readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run the deferred work for one buffered chunk.
	 *
	 * @param DeferredListenerContext $context The buffered entries.
	 *
	 * @return void
	 */
	abstract protected function runDeferred(DeferredListenerContext $context): void;
}//end class
