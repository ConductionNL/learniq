<?php

/**
 * AttendanceWindowSweepJob unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/attendance-warns-before-the-threshold/specs/attendance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Learniq\BackgroundJob\AttendanceWindowSweepJob;
use OCA\Learniq\Kernel\Contracts\AttendanceSummaryServiceInterface;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Unit tests for AttendanceWindowSweepJob.
 *
 * @covers \OCA\Learniq\BackgroundJob\AttendanceWindowSweepJob
 * @uses   \OCA\Learniq\Service\Attendance\AttendanceSummaryCalculator
 * @uses   \OCA\Learniq\Support\ObjectRow
 */
class AttendanceWindowSweepJobTest extends TestCase {

	private const NOW = '2026-11-14T06:00:00+00:00';

	private const TENANT = '0f000000-0000-4000-8000-000000000001';

	/**
	 * The register stand-in.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * The logger the job writes to.
	 *
	 * @var CapturingLogger
	 */
	private CapturingLogger $logger;

	/**
	 * The recount calls, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $recounts = [];

	/**
	 * Learner ids whose recount throws.
	 *
	 * @var array<int, string>
	 */
	private array $failing = [];

	/**
	 * Fresh store and logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new RegisterFaithfulStore();
		$this->store->callerGroups = [];
		$this->store->actingUser = '';
		$this->logger = new CapturingLogger();
		$this->recounts = [];
		$this->failing = [];
	}//end setUp()

	/**
	 * Store one summary row.
	 *
	 * @param string     $learnerId  The pupil.
	 * @param string     $schoolYear The school year.
	 * @param float|null $hours      The stored window count.
	 *
	 * @return void
	 */
	private function storeSummary(string $learnerId, string $schoolYear, ?float $hours): void {
		$n = count($this->store->rows['attendance-summary'] ?? []) + 1;
		$this->store->rows['attendance-summary'][] = [
			'id' => sprintf('0a000000-0000-4000-8000-%012d', $n),
			'learnerId' => $learnerId,
			'learnerRef' => 'ref-' . $learnerId,
			'schoolYear' => $schoolYear,
			'unauthorisedHoursWindow' => $hours,
			'tenant_id' => self::TENANT,
		];
	}//end storeSummary()

	/**
	 * Build the job over the store.
	 *
	 * @return AttendanceWindowSweepJob
	 */
	private function makeJob(): AttendanceWindowSweepJob {
		$objects = $this->createMock(ObjectService::class);
		$store = $this->store;
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $store->findAll($config, $_rbac, $_multitenancy)
		);

		$summaries = $this->createMock(AttendanceSummaryServiceInterface::class);
		$summaries->method('recompute')->willReturnCallback(
			function (string $learnerId, array $schoolYears = [], string $tenantId = '', ?string $learnerRef = null): int {
				$this->recounts[] = ['learnerId' => $learnerId, 'schoolYears' => $schoolYears, 'tenantId' => $tenantId, 'learnerRef' => $learnerRef];
				if (in_array($learnerId, $this->failing, true) === true) {
					throw new RuntimeException('register down');
				}

				return 1;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((new DateTimeImmutable(self::NOW))->getTimestamp());

		return new AttendanceWindowSweepJob(time: $time, objectService: $objects, summaries: $summaries, logger: $this->logger);
	}//end makeJob()

	/**
	 * Run the job's protected run().
	 *
	 * @param AttendanceWindowSweepJob $job The job.
	 *
	 * @return void
	 */
	private static function runJob(AttendanceWindowSweepJob $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * Only the pupils of this school year whose window holds hours are
	 * recounted, with the row's tenant and profile, as the system.
	 *
	 * @return void
	 */
	public function testOnlyPupilsWithHoursInTheWindowAreRecounted(): void {
		$this->storeSummary('sem', '2026-2027', 14.0);
		$this->storeSummary('finn', '2026-2027', 0.5);
		$this->storeSummary('lotte', '2026-2027', 0.0);
		$this->storeSummary('noor', '2026-2027', null);
		$this->storeSummary('ryan', '2025-2026', 12.0);

		self::runJob($this->makeJob());

		$this->assertSame(
			[
				['learnerId' => 'sem', 'schoolYears' => ['2026-2027'], 'tenantId' => self::TENANT, 'learnerRef' => 'ref-sem'],
				['learnerId' => 'finn', 'schoolYears' => ['2026-2027'], 'tenantId' => self::TENANT, 'learnerRef' => 'ref-finn'],
			],
			$this->recounts
		);
		$this->assertNotSame([], $this->store->reads);
		foreach ($this->store->reads as $read) {
			$this->assertFalse($read['rbac']);
			$this->assertFalse($read['multitenancy']);
			$this->assertSame('2026-2027', $read['config']['filters']['schoolYear']);
		}
	}//end testOnlyPupilsWithHoursInTheWindowAreRecounted()

	/**
	 * The summaries are read page by page until a short page.
	 *
	 * @return void
	 */
	public function testEverySummaryPageIsRead(): void {
		for ($i = 0; $i < AttendanceWindowSweepJob::PAGE + 3; $i++) {
			$this->storeSummary('pupil-' . $i, '2026-2027', 1.0);
		}

		self::runJob($this->makeJob());

		$this->assertCount(AttendanceWindowSweepJob::PAGE + 3, $this->recounts);
		$this->assertCount(2, $this->store->reads);
		$this->assertSame(0, $this->store->reads[0]['config']['offset']);
		$this->assertSame(AttendanceWindowSweepJob::PAGE, $this->store->reads[1]['config']['offset']);
	}//end testEverySummaryPageIsRead()

	/**
	 * A failing recount is logged and does not stop the others.
	 *
	 * @return void
	 */
	public function testAFailedRecountDoesNotStopTheRest(): void {
		$this->storeSummary('sem', '2026-2027', 14.0);
		$this->storeSummary('ryan', '2026-2027', 12.0);
		$this->failing = ['sem'];

		self::runJob($this->makeJob());

		$this->assertSame(['sem', 'ryan'], array_column($this->recounts, 'learnerId'));
		$summary = array_values(array_filter($this->logger->records, static fn (array $record): bool => array_key_exists('recounted', $record['context'])));
		$this->assertCount(1, $summary);
		$this->assertSame(1, $summary[0]['context']['recounted']);
		$this->assertSame(1, $summary[0]['context']['failed']);
		$warnings = array_values(array_filter($this->logger->records, static fn (array $record): bool => ($record['context']['learner'] ?? null) === 'sem'));
		$this->assertCount(1, $warnings);
	}//end testAFailedRecountDoesNotStopTheRest()

	/**
	 * Nothing stored: nothing recounted.
	 *
	 * @return void
	 */
	public function testNoSummariesMeansNoRecount(): void {
		self::runJob($this->makeJob());

		$this->assertSame([], $this->recounts);
	}//end testNoSummariesMeansNoRecount()

	/**
	 * The job is registered in appinfo/info.xml, or Nextcloud never schedules it.
	 *
	 * @return void
	 */
	public function testTheJobIsRegisteredInInfoXml(): void {
		$infoXml = simplexml_load_string(
			(string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/info.xml')
		);
		$this->assertNotFalse(condition: $infoXml);

		$jobs = array_map('strval', $infoXml->xpath('/info/background-jobs/job'));
		$this->assertContains(needle: AttendanceWindowSweepJob::class, haystack: $jobs);
	}//end testTheJobIsRegisteredInInfoXml()
}//end class
