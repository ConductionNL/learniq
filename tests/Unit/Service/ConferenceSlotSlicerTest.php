<?php

/**
 * Tests for ConferenceSlotSlicer: slots cut from free blocks, and the
 * half-open overlap check.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\ConferenceSlotSlicer;
use PHPUnit\Framework\TestCase;

/**
 * Pins the slicer's slots and overlap answers.
 */
class ConferenceSlotSlicerTest extends TestCase {

	/**
	 * Slots fill each block in order with the buffer between them; a slot that
	 * would run past the block's end is not offered, and a written offset is kept.
	 *
	 * @return void
	 */
	public function testBlocksAreCutIntoSlotsWithTheBuffer(): void {
		$slots = (new ConferenceSlotSlicer())->slice(
			[
				['startsAt' => '2026-11-12T16:00:00+00:00', 'endsAt' => '2026-11-12T16:50:00+00:00'],
				['startsAt' => '2026-11-12T18:00:00+01:00', 'endsAt' => '2026-11-12T18:15:00+01:00'],
			],
			15,
			5
		);

		self::assertSame(
			[
				['startsAt' => '2026-11-12T16:00:00+00:00', 'endsAt' => '2026-11-12T16:15:00+00:00'],
				['startsAt' => '2026-11-12T16:20:00+00:00', 'endsAt' => '2026-11-12T16:35:00+00:00'],
				['startsAt' => '2026-11-12T18:00:00+01:00', 'endsAt' => '2026-11-12T18:15:00+01:00'],
			],
			$slots
		);
	}//end testBlocksAreCutIntoSlotsWithTheBuffer()

	/**
	 * A block that exactly fits ends on a slot; without a buffer slots touch.
	 *
	 * @return void
	 */
	public function testAnExactFitAndNoBuffer(): void {
		$slots = (new ConferenceSlotSlicer())->slice(
			[['startsAt' => '2026-11-12T16:00:00Z', 'endsAt' => '2026-11-12T16:20:00Z']],
			10,
			0
		);

		self::assertSame(
			[
				['startsAt' => '2026-11-12T16:00:00+00:00', 'endsAt' => '2026-11-12T16:10:00+00:00'],
				['startsAt' => '2026-11-12T16:10:00+00:00', 'endsAt' => '2026-11-12T16:20:00+00:00'],
			],
			$slots
		);
	}//end testAnExactFitAndNoBuffer()

	/**
	 * Incomplete or unreadable blocks are skipped, and a non-positive duration yields nothing.
	 *
	 * @return void
	 */
	public function testBadBlocksAndDurationsYieldNothing(): void {
		$slicer = new ConferenceSlotSlicer();
		$good   = ['startsAt' => '2026-11-12T16:00:00Z', 'endsAt' => '2026-11-12T16:10:00Z'];

		self::assertSame([], $slicer->slice([$good], 0, 5));
		self::assertSame([], $slicer->slice([$good], -10, 5));
		self::assertSame(
			[['startsAt' => '2026-11-12T16:00:00+00:00', 'endsAt' => '2026-11-12T16:10:00+00:00']],
			$slicer->slice(
				[
					['startsAt' => '2026-11-12T15:00:00Z'],
					['endsAt' => '2026-11-12T15:00:00Z'],
					['startsAt' => null, 'endsAt' => '2026-11-12T15:00:00Z'],
					['startsAt' => 'not a date', 'endsAt' => '2026-11-12T15:00:00Z'],
					['startsAt' => '2026-11-12T15:00:00Z', 'endsAt' => 'never'],
					['startsAt' => '2026-11-12T15:00:00Z', 'endsAt' => '2026-11-12T15:05:00Z'],
					$good,
				],
				10,
				5
			)
		);
	}//end testBadBlocksAndDurationsYieldNothing()

	/**
	 * Overlap is half open: touching intervals do not overlap, and unreadable ones are skipped.
	 *
	 * @return void
	 */
	public function testOverlapIsHalfOpen(): void {
		$slicer    = new ConferenceSlotSlicer();
		$candidate = ['startsAt' => '2026-11-12T16:00:00Z', 'endsAt' => '2026-11-12T16:15:00Z'];

		self::assertFalse($slicer->overlaps($candidate, []));
		self::assertFalse($slicer->overlaps($candidate, [['startsAt' => '2026-11-12T16:15:00Z', 'endsAt' => '2026-11-12T16:30:00Z']]));
		self::assertFalse($slicer->overlaps($candidate, [['startsAt' => '2026-11-12T15:45:00Z', 'endsAt' => '2026-11-12T16:00:00Z']]));
		self::assertTrue($slicer->overlaps($candidate, [['startsAt' => '2026-11-12T16:14:00Z', 'endsAt' => '2026-11-12T16:30:00Z']]));
		self::assertTrue($slicer->overlaps($candidate, [['startsAt' => '2026-11-12T15:00:00Z', 'endsAt' => '2026-11-12T17:00:00Z']]));
		self::assertTrue(
			$slicer->overlaps(
				$candidate,
				[
					['startsAt' => 'garbage', 'endsAt' => '2026-11-12T17:00:00Z'],
					['startsAt' => '2026-11-12T15:00:00Z', 'endsAt' => 'garbage'],
					['startsAt' => '2026-11-12T16:05:00Z', 'endsAt' => '2026-11-12T16:06:00Z'],
				]
			)
		);
		self::assertFalse(
			$slicer->overlaps(
				['startsAt' => 'garbage', 'endsAt' => '2026-11-12T16:15:00Z'],
				[['startsAt' => '2026-11-12T15:00:00Z', 'endsAt' => '2026-11-12T17:00:00Z']]
			)
		);
		self::assertFalse(
			$slicer->overlaps(
				['startsAt' => '2026-11-12T16:00:00Z', 'endsAt' => 'garbage'],
				[['startsAt' => '2026-11-12T15:00:00Z', 'endsAt' => '2026-11-12T17:00:00Z']]
			)
		);
	}//end testOverlapIsHalfOpen()
}//end class
