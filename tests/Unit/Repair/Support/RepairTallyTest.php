<?php
/**
 * Unit tests for RepairTally: every row counts as scanned and the outcome the
 * visit names is counted next to it.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Repair\Support
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

namespace OCA\Learniq\Tests\Unit\Repair\Support;

use OCA\Learniq\Repair\Support\RepairTally;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Repair\Support\RepairTally
 */
class RepairTallyTest extends TestCase {

	/**
	 * A fresh tally counts nothing, for any outcome.
	 *
	 * @return void
	 */
	public function testAFreshTallyCountsNothing(): void {
		$tally = new RepairTally(visit: static fn (array $row): ?string => 'stamped');

		self::assertSame(0, $tally->countOf(key: 'scanned'));
		self::assertSame(0, $tally->countOf(key: 'stamped'));
	}//end testAFreshTallyCountsNothing()

	/**
	 * Each row is handed to the visit unchanged and in order.
	 *
	 * @return void
	 */
	public function testEachRowIsHandedToTheVisitInOrder(): void {
		$seen = [];
		$tally = new RepairTally(
			visit: static function (array $row) use (&$seen): ?string {
				$seen[] = $row;
				return null;
			}
		);

		$tally->visit(row: ['id' => 'a', 'n' => 1]);
		$tally->visit(row: ['id' => 'b']);

		self::assertSame([['id' => 'a', 'n' => 1], ['id' => 'b']], $seen);
	}//end testEachRowIsHandedToTheVisitInOrder()

	/**
	 * Every row counts as scanned; each named outcome is counted on its own.
	 *
	 * @return void
	 */
	public function testOutcomesAreCountedPerName(): void {
		$tally = new RepairTally(visit: static fn (array $row): ?string => $row['outcome']);

		$tally->visit(row: ['outcome' => 'stamped']);
		$tally->visit(row: ['outcome' => 'failed']);
		$tally->visit(row: ['outcome' => 'stamped']);
		$tally->visit(row: ['outcome' => null]);

		self::assertSame(4, $tally->countOf(key: 'scanned'));
		self::assertSame(2, $tally->countOf(key: 'stamped'));
		self::assertSame(1, $tally->countOf(key: 'failed'));
		self::assertSame(0, $tally->countOf(key: 'skipped'));
	}//end testOutcomesAreCountedPerName()

	/**
	 * A visit that names no outcome still counts the row as scanned.
	 *
	 * @return void
	 */
	public function testANullOutcomeOnlyCountsTheScan(): void {
		$tally = new RepairTally(visit: static fn (array $row): ?string => null);

		$tally->visit(row: []);
		$tally->visit(row: []);

		self::assertSame(2, $tally->countOf(key: 'scanned'));
		self::assertSame(0, $tally->countOf(key: ''));
	}//end testANullOutcomeOnlyCountsTheScan()

	/**
	 * A visit naming `scanned` adds to the scan count rather than replacing it.
	 *
	 * @return void
	 */
	public function testAnOutcomeNamedScannedAddsToTheScanCount(): void {
		$tally = new RepairTally(visit: static fn (array $row): ?string => 'scanned');

		$tally->visit(row: []);

		self::assertSame(2, $tally->countOf(key: 'scanned'));
	}//end testAnOutcomeNamedScannedAddsToTheScanCount()

	/**
	 * A visit that throws has already counted the row as scanned, and the
	 * exception reaches the caller.
	 *
	 * @return void
	 */
	public function testAThrowingVisitHasCountedTheScan(): void {
		$tally = new RepairTally(
			visit: static function (array $row): ?string {
				throw new \RuntimeException('boom');
			}
		);

		try {
			$tally->visit(row: []);
			self::fail('The visit exception should reach the caller.');
		} catch (\RuntimeException $exception) {
			self::assertSame('boom', $exception->getMessage());
		}

		self::assertSame(1, $tally->countOf(key: 'scanned'));
	}//end testAThrowingVisitHasCountedTheScan()
}//end class
