<?php

/**
 * Unit tests for DraftSubmitException: a refused or invalid draft submission as it reaches callers.
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

use OCA\Learniq\Exception\DraftSubmitException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Exception\DraftSubmitException
 */
class DraftSubmitExceptionTest extends TestCase {

	/**
	 * The reason code is also the message, and the underlying failure is chained.
	 *
	 * @return void
	 */
	public function testTheReasonIsTheMessageAndTheCauseIsChained(): void {
		$cause = new RuntimeException('validation failed: title');

		$exception = new DraftSubmitException(reason: DraftSubmitException::INVALID, previous: $cause);

		self::assertSame('invalid', $exception->getReason());
		self::assertSame('invalid', $exception->getMessage());
		self::assertSame($cause, $exception->getPrevious());
	}//end testTheReasonIsTheMessageAndTheCauseIsChained()

	/**
	 * Without a cause nothing is chained.
	 *
	 * @return void
	 */
	public function testARefusalWithoutACauseChainsNothing(): void {
		$exception = new DraftSubmitException(reason: DraftSubmitException::REFUSED);

		self::assertSame('refused', $exception->getReason());
		self::assertNull($exception->getPrevious());
		self::assertInstanceOf(RuntimeException::class, $exception);
	}//end testARefusalWithoutACauseChainsNothing()
}//end class
