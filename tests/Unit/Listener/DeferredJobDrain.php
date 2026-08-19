<?php

/**
 * Test helper: run recorded deferrals through the REAL background job.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Listener
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
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Scholiq\BackgroundJob\DeferredObjectListenerJob;
use OCA\Scholiq\Listener\DeferredObjectWork;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Drains a {@see RecordingDeferralService} through {@see DeferredObjectListenerJob}.
 *
 * WHY THE REAL JOB AND NOT A DIRECT CALL TO runDeferredWork(): the job carries
 * the handler allow-list AND the {@see \OCA\Scholiq\Listener\DeferredWorkGuard}
 * claim that stops a listener's own write from re-entering it and enqueueing
 * another job. A test that called `runDeferredWork()` directly would run the
 * work with the guard NOT held — the one condition production never has — and
 * would report success for a listener whose deferral loops for ever in cron.
 */
final class DeferredJobDrain {

	/**
	 * Run every recorded entry through the real job, once.
	 *
	 * @param TestCase $test The calling test (for building mocks).
	 * @param RecordingDeferralService $deferral The recorder holding the entries.
	 * @param DeferredObjectWork $listener The listener the entries belong to.
	 *
	 * @return int The number of entries this pass consumed.
	 */
	public static function run(
		TestCase $test,
		RecordingDeferralService $deferral,
		DeferredObjectWork $listener,
	): int {
		$entries = $deferral->entries;
		if ($entries === []) {
			return 0;
		}

		// The entries are consumed, so a listener that queues MORE work from
		// inside the deferred pass (the loop this design forbids) shows up as a
		// re-populated list rather than silently recursing here.
		$deferral->entries = [];
		$deferral->jobClasses = [];
		$deferral->dedupeKeys = [];

		$context = new DeferredListenerContext(userId: 'tester', orgUuid: null, entries: $entries);

		$run = new ReflectionMethod(DeferredObjectListenerJob::class, 'runDeferred');
		$run->setAccessible(true);
		$run->invoke(self::makeJob(test: $test, listener: $listener), $context);

		return count($entries);
	}//end run()

	/**
	 * Drain repeatedly until nothing is left, or the budget runs out.
	 *
	 * A conversion that re-queues itself never empties the recorder, so the
	 * budget is what turns "loops for ever in cron" into a finite failing test
	 * instead of a hanging one.
	 *
	 * @param TestCase $test The calling test (for building mocks).
	 * @param RecordingDeferralService $deferral The recorder holding the entries.
	 * @param DeferredObjectWork $listener The listener the entries belong to.
	 * @param int $maxPasses Maximum drain passes before giving up.
	 *
	 * @return int The number of passes that actually ran work.
	 */
	public static function drain(
		TestCase $test,
		RecordingDeferralService $deferral,
		DeferredObjectWork $listener,
		int $maxPasses = 5,
	): int {
		$passes = 0;
		while ($deferral->entries !== [] && $passes < $maxPasses) {
			self::run(test: $test, deferral: $deferral, listener: $listener);
			$passes++;
		}

		return $passes;
	}//end drain()

	/**
	 * Build the real job with mocked identity plumbing.
	 *
	 * @param TestCase $test The calling test (for building mocks).
	 * @param DeferredObjectWork $listener The only service the container resolves.
	 *
	 * @return DeferredObjectListenerJob
	 */
	private static function makeJob(TestCase $test, DeferredObjectWork $listener): DeferredObjectListenerJob {
		$container = $test->getMockBuilder(ContainerInterface::class)->getMock();
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($listener): object {
				if ($id === $listener::class) {
					return $listener;
				}

				throw new RuntimeException('unexpected service ' . $id);
			}
		);

		return new DeferredObjectListenerJob(
			$test->getMockBuilder(ITimeFactory::class)->getMock(),
			$test->getMockBuilder(IUserSession::class)->getMock(),
			$test->getMockBuilder(IUserManager::class)->getMock(),
			$test->getMockBuilder(OrganisationService::class)->disableOriginalConstructor()->getMock(),
			$test->getMockBuilder(LoggerInterface::class)->getMock(),
			$container,
		);
	}//end makeJob()
}//end class
