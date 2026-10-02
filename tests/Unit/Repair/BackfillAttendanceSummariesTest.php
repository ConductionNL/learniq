<?php

/**
 * Learniq BackfillAttendanceSummaries unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Repair
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
 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-existing-records-get-their-summaries
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use OCA\Learniq\Repair\BackfillAttendanceSummaries;
use OCA\Learniq\Service\Attendance\AttendanceSummaryService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The repair step counts every learner with records once.
 */
class BackfillAttendanceSummariesTest extends TestCase {

	/** @var array<int, string> */
	private array $messages = [];

	/**
	 * Every learner is recounted once, with the years of all their lessons, across pages.
	 *
	 * @return void
	 */
	public function testCountsEveryLearnerOnce(): void {
		$records = [];
		for ($i = 0; $i < 250; $i++) {
			$records[] = ['learnerId' => 'pupil-' . ($i % 2), 'sessionId' => 's-' . $i, 'tenant_id' => 't', 'learnerRef' => 'ref-' . ($i % 2), 'status' => 'present'];
		}

		$records[] = ['learnerId' => '', 'sessionId' => 's-x', 'status' => 'late'];

		$offsets = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config=[], bool $_rbac=true, bool $_multitenancy=true) use ($records, &$offsets): array {
				self::assertFalse($_rbac);
				self::assertFalse($_multitenancy);
				self::assertSame('attendance-record', $config['filters']['schema']);
				$offsets[] = $config['offset'];
				return array_map(static fn (array $r) => OrEntityFactory::make($r, 'attendance-record'), array_slice($records, $config['offset'], $config['limit']));
			}
		);

		$calls = [];
		$service = $this->createMock(AttendanceSummaryService::class);
		$service->method('schoolYearsOf')->willReturnCallback(static fn (array $ids): array => [count($ids) . ' lessons']);
		$service->method('recompute')->willReturnCallback(
			static function (string $learnerId, array $schoolYears=[], string $tenantId='', ?string $learnerRef=null) use (&$calls): int {
				$calls[$learnerId] = [$schoolYears, $tenantId, $learnerRef];
				return 1;
			}
		);

		(new BackfillAttendanceSummaries(objectService: $objects, summaries: $service, logger: new NullLogger()))->run($this->repairOutput());

		self::assertSame([0, 200], $offsets);
		self::assertSame(
			[
				'pupil-0' => [['125 lessons'], 't', 'ref-0'],
				'pupil-1' => [['125 lessons'], 't', 'ref-1'],
			],
			$calls
		);
		self::assertStringContainsString('2 saved', implode("\n", $this->messages));
	}//end testCountsEveryLearnerOnce()

	/**
	 * A failing read stops the step without failing the upgrade.
	 *
	 * @return void
	 */
	public function testAFailingReadDoesNotFailTheUpgrade(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willThrowException(new RuntimeException('register not imported yet'));
		$service = $this->createMock(AttendanceSummaryService::class);
		$service->expects(self::never())->method('recompute');

		(new BackfillAttendanceSummaries(objectService: $objects, summaries: $service, logger: new NullLogger()))->run($this->repairOutput());

		self::assertStringContainsString('0 saved', implode("\n", $this->messages));
	}//end testAFailingReadDoesNotFailTheUpgrade()

	/**
	 * A repair output that records its messages.
	 *
	 * @return IOutput
	 */
	private function repairOutput(): IOutput {
		$this->messages = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->messages[] = $message;
			}
		);

		return $output;
	}//end repairOutput()
}//end class
