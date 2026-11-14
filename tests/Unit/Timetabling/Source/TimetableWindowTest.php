<?php

/**
 * TimetableWindow unit tests: the date bounds sent with a timetable query.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Timetabling\Source
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

namespace OCA\Learniq\Tests\Unit\Timetabling\Source;

use OCA\Learniq\Timetabling\Source\TimetableWindow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Only filled bounds are sent.
 */
#[CoversClass(TimetableWindow::class)]
class TimetableWindowTest extends TestCase {

	/**
	 * Both bounds are sent when both are filled.
	 *
	 * @return void
	 */
	public function testBothBoundsAreSent(): void {
		self::assertSame(['from' => '2026-11-01', 'to' => '2026-11-30'], (new TimetableWindow(from: '2026-11-01', to: '2026-11-30'))->bounds());
	}//end testBothBoundsAreSent()

	/**
	 * A null or empty bound is left out rather than sent empty.
	 *
	 * @return void
	 */
	public function testAMissingBoundIsLeftOut(): void {
		self::assertSame(['from' => '2026-11-01'], (new TimetableWindow(from: '2026-11-01', to: null))->bounds());
		self::assertSame(['to' => '2026-11-30'], (new TimetableWindow(from: '', to: '2026-11-30'))->bounds());
		self::assertSame([], (new TimetableWindow(from: null, to: ''))->bounds());
	}//end testAMissingBoundIsLeftOut()

	/**
	 * A bound of "0" is a filled value and is sent.
	 *
	 * @return void
	 */
	public function testAZeroStringIsAFilledBound(): void {
		self::assertSame(['from' => '0', 'to' => ' '], (new TimetableWindow(from: '0', to: ' '))->bounds());
	}//end testAZeroStringIsAFilledBound()
}//end class
