<?php

/**
 * Test stub for OCA\OpenRegister\BackgroundJob\ActorForwardedJob.
 *
 * Mirrors the real abstract base
 * (openregister/lib/BackgroundJob/ActorForwardedJob.php): the same constructor
 * parameter list, the same `protected abstract runDeferred()` contract, and the
 * same `protected readonly LoggerInterface $logger` that subclasses use.
 *
 * ⚠️ `run()` IS IMPLEMENTED HERE ON PURPOSE. `OCP\BackgroundJob\QueuedJob::run()`
 * is abstract, so a stub that declared only `runDeferred()` would fatal at
 * class-load with "contains abstract method and must therefore be declared
 * abstract" the moment anything instantiated a concrete subclass. It also
 * mirrors the real base's identity rules — a captured user that no longer
 * resolves SKIPS the work rather than running it under whatever identity the
 * worker holds, and the previous user is restored in a `finally` — so a test
 * that drains through this stub exercises the same skip/restore behaviour
 * production has.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Scholiq\Tests\Stubs\BackgroundJob
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

if (class_exists(ActorForwardedJob::class) === false) {
	/**
	 * Mirror of OpenRegister's ActorForwardedJob for standalone Scholiq unit tests.
	 */
	abstract class ActorForwardedJob extends QueuedJob {

		/**
		 * Wire the identity plumbing shared by all actor-forwarded jobs.
		 *
		 * @param ITimeFactory $time Time factory for the parent job class.
		 * @param IUserSession $userSession Session to impersonate on / restore.
		 * @param IUserManager $userManager Resolver for the captured user id.
		 * @param OrganisationService $organisation Active-organisation resolver.
		 * @param LoggerInterface $logger PSR logger shared with subclasses.
		 *
		 * @return void
		 */
		public function __construct(
			ITimeFactory $time,
			private readonly IUserSession $userSession,
			private readonly IUserManager $userManager,
			private readonly OrganisationService $organisation,
			protected readonly LoggerInterface $logger,
		) {
			parent::__construct(time: $time);
		}//end __construct()

		/**
		 * Re-establish the captured actor, run the deferred work, restore.
		 *
		 * @param array<string, mixed> $argument Serialized DeferredListenerContext.
		 *
		 * @return void
		 */
		protected function run($argument): void {
			$context = DeferredListenerContext::fromJobArguments($argument);
			if (count($context->getEntries()) === 0) {
				return;
			}

			$userId = $context->getUserId();
			$user = null;
			if ($userId !== null) {
				$user = $this->userManager->get($userId);
				if ($user === null) {
					return;
				}
			}

			$previousUser = $this->userSession->getUser();
			if ($user !== null) {
				$this->userSession->setUser($user);
			}

			try {
				$this->runDeferred(context: $context);
			} finally {
				$this->userSession->setUser($previousUser);
			}
		}//end run()

		/**
		 * The deferred listener work, executed under the re-established actor.
		 *
		 * @param DeferredListenerContext $context The captured dispatch-time context.
		 *
		 * @return void
		 */
		abstract protected function runDeferred(DeferredListenerContext $context): void;
	}
}
