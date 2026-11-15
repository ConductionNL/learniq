<?php

/**
 * Unit tests for ExchangeImportLandingListener when the landing itself fails:
 * every record is answered as rejected and the job is still answered.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/data-exchange/spec.md#scenario-an-lvs-result-lands-as-imported
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Integriq\Event\ExchangeRecordsReceivedEvent;
use OCA\Learniq\Listener\ExchangeImportLandingListener;
use OCA\Learniq\Service\ExchangeImportLanding;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Listener\ExchangeImportLandingListener
 * @uses   \OCA\Learniq\Support\EventGetter
 */
class ExchangeImportLandingFailureTest extends TestCase {

	/**
	 * A received-records event that expects one accept() call with the given answer.
	 *
	 * @param string                           $owner    The owning app.
	 * @param string                           $target   The target.
	 * @param array<int, array<string, mixed>> $records  The records.
	 * @param array{0: int, 1: array<int, mixed>}|null $answer The expected accept() arguments, null for none.
	 *
	 * @return ExchangeRecordsReceivedEvent
	 */
	private function event(string $owner, string $target, array $records, ?array $answer): ExchangeRecordsReceivedEvent {
		$event = $this->createMock(ExchangeRecordsReceivedEvent::class);
		$event->method('getOwnerApp')->willReturn($owner);
		$event->method('getTarget')->willReturn($target);
		$event->method('getJobId')->willReturn('job-7');
		$event->method('getScope')->willReturn(['tenantId' => 't1']);
		$event->method('getRecords')->willReturn($records);
		if ($answer === null) {
			$event->expects(self::never())->method('accept');
			return $event;
		}

		$event->expects(self::once())->method('accept')->with($answer[0], $answer[1]);
		return $event;
	}//end event()

	/**
	 * A failed landing rejects every record as a write failure, logs only the exception class, and answers.
	 *
	 * @return void
	 */
	public function testAFailedLandingRejectsEveryRecordAndAnswers(): void {
		$records = [['recordId' => 'r-1', 'data' => ['bsn' => 'secret']], ['data' => []]];
		$landing = $this->createMock(ExchangeImportLanding::class);
		$landing->expects(self::once())->method('land')->with('oso', 'job-7', ['tenantId' => 't1'], $records)
			->willThrowException(new RuntimeException('record r-1 holds bsn secret'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with('[ExchangeImportLandingListener] landing failed: RuntimeException');

		$event = $this->event(
			'learniq',
			'oso',
			$records,
			[
				0,
				[
					['recordId' => 'r-1', 'errorCode' => 'IMPORT-WRITE-FAILED'],
					['recordId' => '', 'errorCode' => 'IMPORT-WRITE-FAILED'],
				],
			]
		);

		(new ExchangeImportLandingListener(landing: $landing, logger: $logger))->handle($event);
	}//end testAFailedLandingRejectsEveryRecordAndAnswers()

	/**
	 * A landing outcome is passed on as it is; a missing count or list answers zero and none.
	 *
	 * @return void
	 */
	public function testALandingOutcomeIsPassedOn(): void {
		$landing = $this->createMock(ExchangeImportLanding::class);
		$landing->method('land')->willReturnOnConsecutiveCalls(['accepted' => 2, 'rejected' => [['recordId' => 'r-3', 'errorCode' => 'X']]], []);
		$listener = new ExchangeImportLandingListener(landing: $landing, logger: $this->createMock(LoggerInterface::class));

		$listener->handle($this->event('learniq', 'lvs-results', [], [2, [['recordId' => 'r-3', 'errorCode' => 'X']]]));
		$listener->handle($this->event('learniq', 'migration-import', [], [0, []]));
	}//end testALandingOutcomeIsPassedOn()

	/**
	 * Another owner or an unknown target is not landed or answered.
	 *
	 * @return void
	 */
	public function testOtherOwnersAndTargetsAreNotLanded(): void {
		$landing = $this->createMock(ExchangeImportLanding::class);
		$landing->expects(self::never())->method('land');
		$listener = new ExchangeImportLandingListener(landing: $landing, logger: $this->createMock(LoggerInterface::class));

		$listener->handle($this->event('shillinq', 'oso', [], null));
		$listener->handle($this->event('learniq', 'payroll', [], null));
	}//end testOtherOwnersAndTargetsAreNotLanded()
}//end class
