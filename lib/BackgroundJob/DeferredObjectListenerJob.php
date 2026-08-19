<?php

/**
 * Scholiq Deferred Object Listener Job
 *
 * @category BackgroundJob
 * @package  OCA\Scholiq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/event-listener-work-placement/spec.md#requirement-deferred-post-event-work-runs-in-one-actor-forwarded-job
 */

declare(strict_types=1);

namespace OCA\Scholiq\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ActorForwardedJob;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Scholiq\Lifecycle\XapiCompletionHandler;
use OCA\Scholiq\Listener\CompetencyAttainmentRollupHandler;
use OCA\Scholiq\Listener\DeferredObjectWork;
use OCA\Scholiq\Listener\DeferredWorkGuard;
use OCA\Scholiq\Listener\EngagementSignalHandler;
use OCA\Scholiq\Listener\EnrolmentProgressRollupHandler;
use OCA\Scholiq\Listener\LearnerEngagementRollupHandler;
use OCA\Scholiq\Listener\LessonProgressHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the work Scholiq's post-event listeners used to do inside the write.
 *
 * ADR-078: `Object*edEvent` listeners cannot influence the write they observe,
 * so their work is asynchronous by default. Each converted listener implements
 * {@see DeferredObjectWork} and hands an entry to OpenRegister's
 * `ListenerDeferralService`, which captures the acting user and enqueues this
 * job. The job re-establishes that user (via {@see ActorForwardedJob}) and
 * calls the listener back.
 *
 * ONE JOB FOR ALL SIX LISTENERS, NOT SIX JOBS. The deferral service buffers per
 * job class, so a single class lets one request's worth of listener work
 * coalesce into one job row instead of six — and three of these listeners
 * (XapiCompletionHandler, LessonProgressHandler, EngagementSignalHandler) react
 * to the SAME XapiStatement write, so that coalescing is the common case, not
 * an edge case. The re-entrancy guard below also has to be applied identically
 * to every one of them, which is easier to keep true in one place.
 *
 * THE HANDLER MAP IS AN ALLOW-LIST, NOT A LOOKUP. The handler key arrives from
 * a persisted job row, so a class name taken from it and resolved through the
 * container would be an instantiate-anything primitive. Only the keys below
 * resolve; anything else is logged and dropped.
 *
 * It extends `ActorForwardedJob`, which is a `QueuedJob`: it runs once and is
 * removed from the job list. It never re-queues itself, so it cannot starve the
 * cron queue behind it.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The class exists precisely to
 *  fan one job out to the app's converted listeners; naming them is the point.
 *
 * @spec openspec/specs/event-listener-work-placement/spec.md#requirement-deferred-post-event-work-runs-in-one-actor-forwarded-job
 */
class DeferredObjectListenerJob extends ActorForwardedJob {

	/**
	 * Handler key -> listener class. An allow-list; see the class docblock.
	 *
	 * @var array<string, class-string<DeferredObjectWork>>
	 */
	private const HANDLERS = [
		XapiCompletionHandler::HANDLER_KEY => XapiCompletionHandler::class,
		CompetencyAttainmentRollupHandler::HANDLER_KEY => CompetencyAttainmentRollupHandler::class,
		LessonProgressHandler::HANDLER_KEY => LessonProgressHandler::class,
		EnrolmentProgressRollupHandler::HANDLER_KEY => EnrolmentProgressRollupHandler::class,
		EngagementSignalHandler::HANDLER_KEY => EngagementSignalHandler::class,
		LearnerEngagementRollupHandler::HANDLER_KEY => LearnerEngagementRollupHandler::class,
	];

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for the parent job class.
	 * @param IUserSession $userSession Session to impersonate on / restore.
	 * @param IUserManager $userManager Resolver for the captured user id.
	 * @param OrganisationService $organisation Active-organisation resolver.
	 * @param LoggerInterface $logger PSR logger.
	 * @param ContainerInterface $container DI container the listeners resolve from.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		IUserSession $userSession,
		IUserManager $userManager,
		OrganisationService $organisation,
		LoggerInterface $logger,
		private readonly ContainerInterface $container,
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
	 * Run every entry's listener work under the re-established actor.
	 *
	 * @param DeferredListenerContext $context The captured dispatch-time context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-listener-work-placement/spec.md#requirement-deferred-post-event-work-runs-in-one-actor-forwarded-job
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		foreach ($context->getEntries() as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$this->runEntry(entry: $entry);
		}

	}//end runDeferred()

	/**
	 * Resolve and run one entry's listener, guarded against re-entry.
	 *
	 * @param array<string, mixed> $entry The entry captured at dispatch time.
	 *
	 * @return void
	 */
	private function runEntry(array $entry): void {
		$listener = $this->resolveListener(entry: $entry);
		if ($listener === null) {
			return;
		}

		$handler = (string)($entry['handler'] ?? '');
		$key = DeferredWorkGuard::key(handler: $handler, uuid: (string)($entry['uuid'] ?? ''));
		if (DeferredWorkGuard::enter(key: $key) === false) {
			// Already on this stack — the write we are about to make has
			// already re-entered us once. Doing it again is the loop.
			return;
		}

		try {
			$listener->runDeferredWork($entry);
		} catch (Throwable $e) {
			// Same blast radius as the inline listeners had: a failure here is
			// logged and dropped, never rethrown into cron.
			$this->logger->warning(
				'Scholiq: deferred listener work failed',
				[
					'handler' => $handler,
					'uuid' => ($entry['uuid'] ?? ''),
					'exception' => $e->getMessage(),
				]
			);
		} finally {
			DeferredWorkGuard::leave(key: $key);
		}//end try

	}//end runEntry()

	/**
	 * Resolve an entry's handler key to a listener through the allow-list.
	 *
	 * @param array<string, mixed> $entry The entry captured at dispatch time.
	 *
	 * @return DeferredObjectWork|null The listener, or null when the entry is unusable.
	 */
	private function resolveListener(array $entry): ?DeferredObjectWork {
		$handler = ($entry['handler'] ?? '');
		if (is_string($handler) === false || isset(self::HANDLERS[$handler]) === false) {
			$this->logger->warning(
				'Scholiq: deferred listener entry names no known handler',
				['handler' => $handler]
			);
			return null;
		}

		try {
			$listener = $this->container->get(self::HANDLERS[$handler]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Scholiq: deferred listener could not be resolved',
				['handler' => $handler, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if (($listener instanceof DeferredObjectWork) === false) {
			$this->logger->warning(
				'Scholiq: deferred listener does not implement DeferredObjectWork',
				['handler' => $handler]
			);
			return null;
		}

		return $listener;
	}//end resolveListener()
}//end class
