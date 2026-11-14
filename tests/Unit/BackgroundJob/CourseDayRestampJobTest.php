<?php

/**
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\BackgroundJob;

use OCA\Learniq\BackgroundJob\ActorForwarding;
use OCA\Learniq\BackgroundJob\CourseDayRestampJob;
use OCA\Learniq\Service\CourseDay\CourseDayStatusService;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

// The unit bootstrap does not load every support helper.
require_once __DIR__ . '/../../Support/CapturingLogger.php';

/**
 * Each buffered course day is re-derived once; one failure does not stop the rest.
 *
 * @covers \OCA\Learniq\BackgroundJob\CourseDayRestampJob
 * @uses   \OCA\Learniq\BackgroundJob\ForwardedJob
 * @uses   \OCA\Learniq\BackgroundJob\ActorForwarding
 */
class CourseDayRestampJobTest extends TestCase {

	/**
	 * Run the job over entries.
	 *
	 * @param CourseDayStatusService $status  The service.
	 * @param CapturingLogger        $logger  The logger.
	 * @param list<array<string, mixed>> $entries The entries.
	 *
	 * @return void
	 */
	private function runJob(CourseDayStatusService $status, CapturingLogger $logger, array $entries): void {
		$forwarding = new ActorForwarding(
			time: $this->createMock(ITimeFactory::class),
			userSession: $this->createMock(IUserSession::class),
			userManager: $this->createMock(IUserManager::class),
			organisation: $this->createMock(OrganisationService::class)
		);
		$job = new CourseDayRestampJob($forwarding, $logger, $status);
		(new ReflectionMethod($job, 'runDeferred'))->invoke($job, new DeferredListenerContext(userId: 'planner', orgUuid: null, entries: $entries));
	}//end runJob()

	/**
	 * Duplicates and blanks collapse; a failing day is logged and the next still runs.
	 *
	 * @return void
	 */
	public function testEachDayOnceAndAFailureDoesNotStopTheRest(): void {
		$done   = [];
		$status = $this->createMock(CourseDayStatusService::class);
		$status->method('restamp')->willReturnCallback(
			static function (string $cohortId) use (&$done): ?string {
				$done[] = $cohortId;
				if ($cohortId === 'c-bad') {
					throw new RuntimeException('store down');
				}

				return 'open';
			}
		);
		$logger = new CapturingLogger();

		$this->runJob($status, $logger, [['cohortId' => 'c-22'], ['cohortId' => 'c-bad'], ['cohortId' => 'c-22'], ['cohortId' => ''], [], ['cohortId' => 'c-26']]);

		self::assertSame(['c-22', 'c-bad', 'c-26'], $done);
		self::assertCount(1, $logger->records);
	}//end testEachDayOnceAndAFailureDoesNotStopTheRest()
}//end class
