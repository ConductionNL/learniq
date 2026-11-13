<?php

/**
 * ResultResponseSum unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\ResultResponseSum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The sum of a result's response scores.
 */
#[CoversClass(ResultResponseSum::class)]
class ResultResponseSumTest extends TestCase {

	/**
	 * An automatic score wins over a manual one; a manual one counts when there is no automatic one.
	 *
	 * @return void
	 */
	public function testAutoScoreWinsAndManualScoreFillsIn(): void {
		$responses = [
			['autoScore' => 2, 'manualScore' => 9],
			['autoScore' => null, 'manualScore' => '1.5'],
			['manualScore' => 3],
			['autoScore' => 'n/a', 'manualScore' => 0.25],
		];

		self::assertSame(6.75, (new ResultResponseSum())->sumOf($responses));
	}//end testAutoScoreWinsAndManualScoreFillsIn()

	/**
	 * Responses without a numeric score, and entries that are no response, add nothing.
	 *
	 * @return void
	 */
	public function testUnscoredAndMalformedResponsesAddNothing(): void {
		$responses = [['autoScore' => 'high'], [], 'answer', 7, null, ['autoScore' => '-1']];

		self::assertSame(-1.0, (new ResultResponseSum())->sumOf($responses));
	}//end testUnscoredAndMalformedResponsesAddNothing()

	/**
	 * Anything but a list of responses sums to zero.
	 *
	 * @return void
	 */
	public function testNoListSumsToZero(): void {
		$sum = new ResultResponseSum();

		self::assertSame(0.0, $sum->sumOf(null));
		self::assertSame(0.0, $sum->sumOf('3'));
		self::assertSame(0.0, $sum->sumOf([]));
	}//end testNoListSumsToZero()
}//end class
