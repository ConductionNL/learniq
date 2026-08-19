<?php

/**
 * Scholiq DeferredObjectListenerJob unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\BackgroundJob
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

namespace OCA\Scholiq\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Scholiq\BackgroundJob\DeferredObjectListenerJob;
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
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests the job that runs every deferred post-event listener's work.
 *
 * The assertions here are about the JOB's own contract — the allow-list, the
 * guard claim and the per-entry blast radius — not about any one listener's
 * behaviour, which its own test covers.
 */
class DeferredObjectListenerJobTest extends TestCase {

	/**
	 * Service ids the container was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $requested = [];

	/**
	 * Reset the guard before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->requested = [];
		DeferredWorkGuard::reset();

	}//end setUp()

	/**
	 * Drop any guard claim a failing test may have leaked.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		DeferredWorkGuard::reset();
		parent::tearDown();

	}//end tearDown()

	/**
	 * Build the job with a container that records what it was asked for.
	 *
	 * @param array<string, object> $services Services the container can answer with.
	 *
	 * @return DeferredObjectListenerJob
	 */
	private function makeJob(array $services): DeferredObjectListenerJob {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($services): object {
				$this->requested[] = $id;
				if (isset($services[$id]) === true) {
					return $services[$id];
				}

				throw new RuntimeException('unknown service ' . $id);
			}
		);

		return new DeferredObjectListenerJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			$this->getMockBuilder(OrganisationService::class)->disableOriginalConstructor()->getMock(),
			$this->createMock(LoggerInterface::class),
			$container,
		);
	}//end makeJob()

	/**
	 * Run the job's protected runDeferred() over a set of entries.
	 *
	 * @param DeferredObjectListenerJob $job The job under test.
	 * @param array<int, array<string, mixed>> $entries The entries to run.
	 *
	 * @return void
	 */
	private function runEntries(DeferredObjectListenerJob $job, array $entries): void {
		$method = new ReflectionMethod(DeferredObjectListenerJob::class, 'runDeferred');
		$method->setAccessible(true);
		$method->invoke($job, new DeferredListenerContext(userId: 'tester', orgUuid: null, entries: $entries));

	}//end run()

	/**
	 * A handler key outside the allow-list must never reach the container.
	 *
	 * A class name arriving from a persisted `oc_jobs.argument` row and passed
	 * to `$container->get()` would be an instantiate-anything primitive, so the
	 * assertion that matters is that NOTHING was resolved — not merely that the
	 * job survived.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-listener-work-placement/spec.md#scenario-an-entry-naming-an-unknown-handler-is-dropped-not-resolved
	 */
	public function testAnUnknownHandlerKeyResolvesNothing(): void {
		$job = $this->makeJob([]);

		$this->runEntries(
			$job,
			[
				['handler' => 'OCA\\Evil\\Whatever', 'uuid' => 'x'],
				['handler' => 'not-a-handler', 'uuid' => 'y'],
				['handler' => 123, 'uuid' => 'z'],
			]
		);

		self::assertSame([], $this->requested, 'no service may be resolved for an unknown handler key');

	}//end testAnUnknownHandlerKeyResolvesNothing()

	/**
	 * Every converted listener's HANDLER_KEY is in the allow-list, and each key
	 * is distinct. A duplicate key would silently route one listener's entries
	 * to another listener.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-listener-work-placement/spec.md#requirement-deferred-post-event-work-runs-in-one-actor-forwarded-job
	 */
	public function testEveryConvertedListenerIsRoutable(): void {
		$expected = [
			XapiCompletionHandler::HANDLER_KEY => XapiCompletionHandler::class,
			CompetencyAttainmentRollupHandler::HANDLER_KEY => CompetencyAttainmentRollupHandler::class,
			LessonProgressHandler::HANDLER_KEY => LessonProgressHandler::class,
			EnrolmentProgressRollupHandler::HANDLER_KEY => EnrolmentProgressRollupHandler::class,
			EngagementSignalHandler::HANDLER_KEY => EngagementSignalHandler::class,
			LearnerEngagementRollupHandler::HANDLER_KEY => LearnerEngagementRollupHandler::class,
		];

		self::assertCount(6, $expected, 'two listeners share a HANDLER_KEY — entries would be misrouted');

		foreach ($expected as $key => $class) {
			$listener = $this->makeSpy();
			$job = $this->makeJob([$class => $listener]);
			$this->requested = [];

			$this->runEntries($job, [['handler' => $key, 'uuid' => 'obj-1']]);

			self::assertSame([$class], $this->requested, $key . ' did not route to ' . $class);
			self::assertSame([['handler' => $key, 'uuid' => 'obj-1']], $listener->ran);
		}

	}//end testEveryConvertedListenerIsRoutable()

	/**
	 * A resolved service that is not a DeferredObjectWork is dropped, not called.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-listener-work-placement/spec.md#requirement-deferred-post-event-work-runs-in-one-actor-forwarded-job
	 */
	public function testAServiceOfTheWrongTypeIsDropped(): void {
		$job = $this->makeJob([EnrolmentProgressRollupHandler::class => new \stdClass()]);

		$this->runEntries($job, [['handler' => EnrolmentProgressRollupHandler::HANDLER_KEY, 'uuid' => 'obj-1']]);

		self::assertSame([EnrolmentProgressRollupHandler::class], $this->requested);
		self::assertFalse(
			DeferredWorkGuard::isRunning(
				key: DeferredWorkGuard::key(handler: EnrolmentProgressRollupHandler::HANDLER_KEY, uuid: 'obj-1')
			)
		);

	}//end testAServiceOfTheWrongTypeIsDropped()

	/**
	 * A throwing entry is logged and dropped, the guard is released, and the
	 * NEXT entry still runs. The inline listeners swallowed their own
	 * exceptions; the job must not have widened that blast radius into cron.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-listener-work-placement/spec.md#requirement-deferred-post-event-work-runs-in-one-actor-forwarded-job
	 */
	public function testAThrowingEntryDoesNotStopTheRestOrLeakTheGuard(): void {
		$boom = $this->makeSpy(throw: true);
		$fine = $this->makeSpy();

		$job = $this->makeJob(
			[
				EnrolmentProgressRollupHandler::class => $boom,
				LessonProgressHandler::class => $fine,
			]
		);

		$this->runEntries(
			$job,
			[
				['handler' => EnrolmentProgressRollupHandler::HANDLER_KEY, 'uuid' => 'obj-1'],
				['handler' => LessonProgressHandler::HANDLER_KEY, 'uuid' => 'obj-2'],
			]
		);

		self::assertCount(1, $boom->ran);
		self::assertCount(1, $fine->ran, 'a failing entry must not abandon the ones behind it');
		self::assertFalse(
			DeferredWorkGuard::isRunning(
				key: DeferredWorkGuard::key(handler: EnrolmentProgressRollupHandler::HANDLER_KEY, uuid: 'obj-1')
			),
			'leave() must run from the finally even when the work threw'
		);

	}//end testAThrowingEntryDoesNotStopTheRestOrLeakTheGuard()

	/**
	 * The guard is HELD while the work runs — this is what a test that called
	 * runDeferredWork() directly would silently skip.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-listener-work-placement/spec.md#requirement-a-listeners-own-write-must-not-re-queue-it
	 */
	public function testTheGuardIsHeldForTheDurationOfTheWork(): void {
		$key = DeferredWorkGuard::key(handler: EnrolmentProgressRollupHandler::HANDLER_KEY, uuid: 'obj-1');

		$observed = null;
		$listener = $this->makeSpy();
		$listener->onRun = static function () use ($key, &$observed): void {
			$observed = DeferredWorkGuard::isRunning(key: $key);
		};

		$job = $this->makeJob([EnrolmentProgressRollupHandler::class => $listener]);
		$this->runEntries($job, [['handler' => EnrolmentProgressRollupHandler::HANDLER_KEY, 'uuid' => 'obj-1']]);

		self::assertTrue($observed, 'the claim must be held while runDeferredWork() executes');
		self::assertFalse(DeferredWorkGuard::isRunning(key: $key), 'and released afterwards');

	}//end testTheGuardIsHeldForTheDurationOfTheWork()

	/**
	 * An entry for a pair already in flight is skipped entirely.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-listener-work-placement/spec.md#requirement-a-listeners-own-write-must-not-re-queue-it
	 */
	public function testAnAlreadyClaimedPairIsSkipped(): void {
		$key = DeferredWorkGuard::key(handler: EnrolmentProgressRollupHandler::HANDLER_KEY, uuid: 'obj-1');
		DeferredWorkGuard::enter(key: $key);

		$listener = $this->makeSpy();
		$job = $this->makeJob([EnrolmentProgressRollupHandler::class => $listener]);

		$this->runEntries($job, [['handler' => EnrolmentProgressRollupHandler::HANDLER_KEY, 'uuid' => 'obj-1']]);

		self::assertSame([], $listener->ran);
		self::assertTrue(DeferredWorkGuard::isRunning(key: $key), 'the outer claim must survive the skip');

	}//end testAnAlreadyClaimedPairIsSkipped()

	/**
	 * Build a listener double that records the entries it was handed.
	 *
	 * @param bool $throw Whether runDeferredWork() should throw.
	 *
	 * @return DeferredObjectWork
	 */
	private function makeSpy(bool $throw = false): DeferredObjectWork {
		return new class($throw) implements DeferredObjectWork {

			/**
			 * Entries handed to runDeferredWork(), in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $ran = [];

			/**
			 * Optional hook fired inside runDeferredWork().
			 *
			 * @var callable|null
			 */
			public $onRun = null;

			/**
			 * Constructor.
			 *
			 * @param bool $throw Whether runDeferredWork() should throw.
			 *
			 * @return void
			 */
			public function __construct(private readonly bool $throw) {
			}//end __construct()

			/**
			 * Record the entry, optionally observing state or failing.
			 *
			 * @param array<string, mixed> $entry The entry captured at dispatch time.
			 *
			 * @return void
			 */
			public function runDeferredWork(array $entry): void {
				$this->ran[] = $entry;

				if ($this->onRun !== null) {
					($this->onRun)();
				}

				if ($this->throw === true) {
					throw new RuntimeException('deliberate failure');
				}

			}//end runDeferredWork()
		};
	}//end makeSpy()
}//end class
