<?php

/**
 * Unit tests for RolloverInterruptedException: a failed rollover as it reaches the handler.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Exception;

use InvalidArgumentException;
use OCA\Learniq\Exception\RolloverInterruptedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Exception\RolloverInterruptedException
 */
class RolloverInterruptedExceptionTest extends TestCase {

	/**
	 * The cause's message is the exception's message, and the cause is chained.
	 *
	 * @return void
	 */
	public function testTakesItsMessageFromTheCauseAndChainsIt(): void {
		$cause = new InvalidArgumentException('Mapping for lesson-7 failed', 42);

		$exception = new RolloverInterruptedException(progress: ['done' => 3], cause: $cause);

		self::assertSame('Mapping for lesson-7 failed', $exception->getMessage());
		self::assertSame($cause, $exception->getPrevious());
		self::assertSame(0, $exception->getCode());
	}//end testTakesItsMessageFromTheCauseAndChainsIt()

	/**
	 * The progress so far travels unchanged, including its key order.
	 *
	 * @return void
	 */
	public function testCarriesTheProgressSoFarUnchanged(): void {
		$progress = ['mapped' => ['course-1' => 'course-9'], 'done' => 1, 'total' => 4];

		$exception = new RolloverInterruptedException(progress: $progress, cause: new RuntimeException('x'));

		self::assertSame($progress, $exception->getProgress());
	}//end testCarriesTheProgressSoFarUnchanged()

	/**
	 * A caller catching a RuntimeException still gets the progress.
	 *
	 * @return void
	 */
	public function testIsCaughtAsARuntimeException(): void {
		try {
			throw new RolloverInterruptedException(progress: ['done' => 0], cause: new RuntimeException('boom'));
		} catch (RuntimeException $e) {
			self::assertInstanceOf(RolloverInterruptedException::class, $e);
			self::assertSame(['done' => 0], $e->getProgress());
		}
	}//end testIsCaughtAsARuntimeException()
}//end class
