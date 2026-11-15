<?php

/**
 * Tests for AttendanceFlagReportGuard: a flag moves to `reported` only once
 * integriq concluded its leerplicht job `succeeded`.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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
 * @spec openspec/specs/attendance/spec.md#requirement-the-municipalitys-feedback-on-a-leerplicht-report-is-recorded-on-the-attendance-flag
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\AttendanceFlagReportGuard;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The report transition against integriq's job row.
 */
class AttendanceFlagReportGuardTest extends TestCase {

	/**
	 * A guard over one integriq job row, or none.
	 *
	 * @param array<string, mixed>|null $job   The job row, or null when integriq has no such job.
	 * @param array<int, mixed>         $calls Receives the find() arguments, by position.
	 *
	 * @return AttendanceFlagReportGuard The guard.
	 */
	private function guard(?array $job, array &$calls = []): AttendanceFlagReportGuard {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function (...$args) use ($job, &$calls) {
				$calls[] = $args;
				if ($job === null) {
					throw new RuntimeException('not found');
				}

				return OrEntityFactory::make($job, 'job', 'integriq');
			}
		);

		return new AttendanceFlagReportGuard($objects, new NullLogger());
	}//end guard()

	/**
	 * The report is not sent yet: a queued job, and a job integriq does not know, refuse.
	 *
	 * @return void
	 */
	public function testTheReportIsNotSentYet(): void {
		$calls = [];
		$queued = $this->guard(['id' => 'job-1', 'exchangeStatus' => 'queued'], $calls)->check(['dataExchangeJobId' => 'job-1'], 'report', 'coordinator-1');

		$this->assertFalse($queued->isAllowed());
		$this->assertStringContainsString('Integriq has not sent', (string)$queued->getMessage());
		// find($id, $_extend, $files, $register, $schema, ...): the named arguments land in place.
		$this->assertSame(['job-1', 'integriq', 'job'], [$calls[0][0], $calls[0][3], $calls[0][4]]);

		$this->assertFalse($this->guard(null)->check(['dataExchangeJobId' => 'job-2'], 'report', 'coordinator-1')->isAllowed());
	}//end testTheReportIsNotSentYet()

	/**
	 * A succeeded job allows the report.
	 *
	 * @return void
	 */
	public function testASucceededJobAllowsTheReport(): void {
		$result = $this->guard(['id' => 'job-1', 'exchangeStatus' => 'succeeded'])->check(['dataExchangeJobId' => 'job-1'], 'report', 'coordinator-1');

		$this->assertTrue($result->isAllowed());
	}//end testASucceededJobAllowsTheReport()

	/**
	 * A flag without a job was handled by hand and may be reported.
	 *
	 * @return void
	 */
	public function testAManualReportNeedsNoJob(): void {
		$calls = [];
		$this->assertTrue($this->guard(null, $calls)->check(['dataExchangeJobId' => null], 'report', 'coordinator-1')->isAllowed());
		$this->assertSame([], $calls, 'Nothing is read for a manual report.');
	}//end testAManualReportNeedsNoJob()

	/**
	 * The refusal is the sentence the flag page shows a mentor, word for word.
	 *
	 * The header's Actions run `report` and show the guard's reason when it
	 * refuses, so the reason must be a sentence for that reader: it says what
	 * the flag waits on and names no job id or status code.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-flag-report-actions/specs/attendance/spec.md
	 */
	public function testTheRefusalIsTheSentenceThePageShows(): void {
		$running = $this->guard(job: ['id' => 'job-1', 'exchangeStatus' => 'running'])->check(
			object: ['dataExchangeJobId' => 'job-1'],
			action: 'report',
			userId: 'mentor-1'
		);

		$this->assertFalse(condition: $running->isAllowed());
		$this->assertSame(
			expected: 'Integriq has not sent the leerplicht report yet, so this flag can not be marked as reported.',
			actual: $running->getMessage()
		);
		$this->assertStringNotContainsString(needle: 'job-1', haystack: (string)$running->getMessage());
		$this->assertStringNotContainsString(needle: 'running', haystack: (string)$running->getMessage());
	}//end testTheRefusalIsTheSentenceThePageShows()
}//end class
