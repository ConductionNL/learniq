<?php

/**
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\BackgroundJob;

use OCA\Learniq\BackgroundJob\ExamRegistrationCloseJob;
use OCA\Learniq\Kernel\Contracts\ExamRegistrationServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The job closes the lists that are due and survives a failing store.
 *
 * @covers \OCA\Learniq\BackgroundJob\ExamRegistrationCloseJob
 */
class ExamRegistrationCloseJobTest extends TestCase {

	/**
	 * Run job.
	 *
	 * @param ExamRegistrationServiceInterface $lists  The lists.
	 * @param LoggerInterface                  $logger The logger.
	 *
	 * @return void
	 */
	private function runJob(ExamRegistrationServiceInterface $lists, LoggerInterface $logger): void {
		$job = new ExamRegistrationCloseJob(time: $this->createMock(originalClassName: ITimeFactory::class), lists: $lists, logger: $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * It closes the due lists and says how many.
	 *
	 * @return void
	 */
	public function testItClosesTheDueListsAndSaysHowMany(): void {
		$lists = $this->createMock(originalClassName: ExamRegistrationServiceInterface::class);
		$lists->expects($this->once())->method('closeDue')->willReturn(2);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with($this->stringContains(string: 'closed at their moment'), ['count' => 2]);

		$this->runJob(lists: $lists, logger: $logger);
	}//end testItClosesTheDueListsAndSaysHowMany()

	/**
	 * Nothing due is quiet and a failure is a warning.
	 *
	 * @return void
	 */
	public function testNothingDueIsQuietAndAFailureIsAWarning(): void {
		$quiet = $this->createMock(originalClassName: ExamRegistrationServiceInterface::class);
		$quiet->method('closeDue')->willReturn(0);
		$silent = $this->createMock(originalClassName: LoggerInterface::class);
		$silent->expects($this->never())->method('info');
		$this->runJob(lists: $quiet, logger: $silent);

		$broken = $this->createMock(originalClassName: ExamRegistrationServiceInterface::class);
		$broken->method('closeDue')->willThrowException(new RuntimeException('database gone'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains(string: 'Stopped early'), ['msg' => 'database gone']);
		$this->runJob(lists: $broken, logger: $logger);
	}//end testNothingDueIsQuietAndAFailureIsAWarning()
}//end class
