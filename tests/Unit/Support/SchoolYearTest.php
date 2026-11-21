<?php

/**
 * Unit tests for SchoolYear.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Support;

use OCA\Learniq\Support\SchoolYear;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the school year a date falls in.
 */
#[CoversClass(SchoolYear::class)]
class SchoolYearTest extends TestCase {

	/**
	 * July still belongs to the year that started the August before.
	 *
	 * @return void
	 */
	public function testJulyBelongsToThePreviousSchoolYear(): void {
		self::assertSame(expected: '2025-2026', actual: SchoolYear::of(date: '2026-07-31'));
		self::assertSame(expected: '2025-2026', actual: SchoolYear::of(date: '2026-01-05'));
	}//end testJulyBelongsToThePreviousSchoolYear()

	/**
	 * The first of August starts a new school year.
	 *
	 * @return void
	 */
	public function testAugustStartsANewSchoolYear(): void {
		self::assertSame(expected: '2026-2027', actual: SchoolYear::of(date: '2026-08-01'));
		self::assertSame(expected: '2026-2027', actual: SchoolYear::of(date: '2026-12-31'));
	}//end testAugustStartsANewSchoolYear()

	/**
	 * A school that sets its own first day turns its year on that day, not on 1 August.
	 *
	 * @return void
	 */
	public function testTheYearTurnsOnTheStartGiven(): void {
		self::assertSame(expected: '2025-2026', actual: SchoolYear::of(date: '2026-08-31', start: '09-01'));
		self::assertSame(expected: '2026-2027', actual: SchoolYear::of(date: '2026-09-01', start: '09-01'));
		self::assertSame(expected: '2026-2027', actual: SchoolYear::of(date: '2026-07-15', start: '07-15'));
		self::assertSame(expected: '2025-2026', actual: SchoolYear::of(date: '2026-07-14', start: '07-15'));
	}//end testTheYearTurnsOnTheStartGiven()

	/**
	 * A start that is not a day of the year reads as 1 August.
	 *
	 * @return void
	 */
	public function testAnUnusableStartReadsAsTheFirstOfAugust(): void {
		self::assertSame(expected: '2025-2026', actual: SchoolYear::of(date: '2026-07-31', start: '13-01'));
		self::assertSame(expected: '2026-2027', actual: SchoolYear::of(date: '2026-08-01', start: '02-30'));
		self::assertSame(expected: '2026-2027', actual: SchoolYear::of(date: '2026-08-01', start: 'september'));
	}//end testAnUnusableStartReadsAsTheFirstOfAugust()

	/**
	 * Anything that is not a `Y-m-d` date names no school year.
	 *
	 * @return void
	 */
	public function testANonDateNamesNoSchoolYear(): void {
		self::assertNull(actual: SchoolYear::of(date: 'yesterday'));
		self::assertNull(actual: SchoolYear::of(date: '2026-08'));
		self::assertNull(actual: SchoolYear::of(date: ''));
	}//end testANonDateNamesNoSchoolYear()
}//end class
