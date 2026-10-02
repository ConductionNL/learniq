<?php

/**
 * Learniq AttendanceSummaryRecomputeJob unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\BackgroundJob
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
 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-the-summary-follows-every-attendance-write
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\BackgroundJob;

use OCA\Learniq\BackgroundJob\AttendanceSummaryRecomputeJob;
use OCA\Learniq\Service\Attendance\AttendanceSummaryService;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * The deferred recount groups entries per learner.
 */
class AttendanceSummaryRecomputeJobTest extends TestCase {

	/**
	 * Entries of one learner become one recount with the school years of their lessons.
	 *
	 * @return void
	 */
	public function testOneRecountPerLearnerWithTheYearsOfTheirLessons(): void {
		$calls = [];
		$service = $this->createMock(AttendanceSummaryService::class);
		$service->method('schoolYearsOf')->willReturnCallback(
			static fn (array $sessionIds): array => in_array('s-sep', $sessionIds, true) === true ? ['2025-2026', '2026-2027'] : ['2025-2026']
		);
		$service->method('recompute')->willReturnCallback(
			static function (string $learnerId, array $schoolYears=[], string $tenantId='', ?string $learnerRef=null) use (&$calls): int {
				if ($learnerId === 'broken') {
					throw new RuntimeException('read failed');
				}

				$calls[] = [$learnerId, $schoolYears, $tenantId, $learnerRef];
				return 1;
			}
		);

		$this->runJob(service: $service, entries: [
			['learnerId' => 'pupil-1', 'sessionId' => 's-mar', 'tenantId' => 't', 'learnerRef' => 'r1'],
			['learnerId' => 'broken', 'sessionId' => 's-mar', 'tenantId' => 't', 'learnerRef' => ''],
			['learnerId' => 'pupil-1', 'sessionId' => 's-sep', 'tenantId' => 't', 'learnerRef' => 'r1'],
			['learnerId' => 'pupil-2', 'sessionId' => 's-mar', 'tenantId' => 't', 'learnerRef' => ''],
			['learnerId' => '', 'sessionId' => 's-mar'],
		]);

		// One failing learner does not stop the others.
		self::assertSame(
			[
				['pupil-1', ['2025-2026', '2026-2027'], 't', 'r1'],
				['pupil-2', ['2025-2026'], 't', null],
			],
			$calls
		);
	}//end testOneRecountPerLearnerWithTheYearsOfTheirLessons()

	/**
	 * Run the job's protected runDeferred() with entries.
	 *
	 * @param AttendanceSummaryService     $service The service double.
	 * @param array<int, array<string, mixed>> $entries The buffered entries.
	 *
	 * @return void
	 */
	private function runJob(AttendanceSummaryService $service, array $entries): void {
		$job = new AttendanceSummaryRecomputeJob(
			time: $this->createMock(ITimeFactory::class),
			userSession: $this->createMock(IUserSession::class),
			userManager: $this->createMock(IUserManager::class),
			organisation: $this->createMock(OrganisationService::class),
			logger: new NullLogger(),
			summaries: $service
		);

		$method = new ReflectionMethod($job, 'runDeferred');
		$method->invoke($job, new DeferredListenerContext(userId: 'juf-7', orgUuid: null, entries: $entries));
	}//end runJob()
}//end class
