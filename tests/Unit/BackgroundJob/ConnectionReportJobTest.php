<?php

/**
 * ConnectionReportJob unit tests.
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
 * @spec openspec/changes/adopt-connection-registry/specs/integrations/spec.md#requirement-req-int-conn-002-learniq-reports-what-the-last-wallet-offer-met
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\BackgroundJob;

use OCA\Learniq\BackgroundJob\ConnectionReportJob;
use OCA\Learniq\Service\ConnectionReportService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Unit tests for ConnectionReportJob.
 *
 * @covers \OCA\Learniq\BackgroundJob\ConnectionReportJob
 */
class ConnectionReportJobTest extends TestCase {

	/**
	 * The job reports once per run, and runs once a day.
	 *
	 * A shorter interval would write a row on integriq far more often than an
	 * outcome can change.
	 *
	 * @return void
	 */
	public function testTheJobReportsOncePerRunOnADailyInterval(): void {
		$reporter = $this->createMock(originalClassName: ConnectionReportService::class);
		$reporter->expects($this->once())->method('reportObservations');

		$job = new ConnectionReportJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			reporter: $reporter,
		);

		$run = new ReflectionMethod($job, 'run');
		$run->invoke($job, null);

		$interval = new ReflectionProperty($job, 'interval');
		$this->assertSame(expected: 86400, actual: $interval->getValue($job));
	}//end testTheJobReportsOncePerRunOnADailyInterval()

	/**
	 * The job is registered in appinfo/info.xml, or Nextcloud never schedules it.
	 *
	 * @return void
	 */
	public function testTheJobIsRegisteredInInfoXml(): void {
		$infoXml = simplexml_load_file(dirname(__DIR__, 3) . '/appinfo/info.xml');
		$this->assertNotFalse(condition: $infoXml);

		$jobs = array_map('strval', $infoXml->xpath('/info/background-jobs/job'));
		$this->assertContains(needle: ConnectionReportJob::class, haystack: $jobs);
	}//end testTheJobIsRegisteredInInfoXml()
}//end class
