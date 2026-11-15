<?php

/**
 * LearningRecordPeriod unit tests.
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

use OCA\Learniq\Service\LearningRecordPeriod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Whether a learning record row falls in a requested period.
 */
#[CoversClass(LearningRecordPeriod::class)]
class LearningRecordPeriodTest extends TestCase {

	/**
	 * Both bounds are inclusive; dates outside are out.
	 *
	 * @return void
	 */
	public function testBothBoundsAreInclusive(): void {
		$period = new LearningRecordPeriod(from: '2026-01-01', to: '2026-06-30');

		self::assertTrue($period->covers(row: ['issuedAt' => '2026-01-01'], dateField: 'issuedAt'));
		self::assertTrue($period->covers(row: ['issuedAt' => '2026-06-30'], dateField: 'issuedAt'));
		self::assertFalse($period->covers(row: ['issuedAt' => '2025-12-31'], dateField: 'issuedAt'));
		self::assertFalse($period->covers(row: ['issuedAt' => '2026-07-01'], dateField: 'issuedAt'));
	}//end testBothBoundsAreInclusive()

	/**
	 * A period open at one end only limits the other.
	 *
	 * @return void
	 */
	public function testAnOpenEndLimitsOnlyTheOther(): void {
		$since = new LearningRecordPeriod(from: '2026-03-01', to: null);
		$until = new LearningRecordPeriod(from: null, to: '2026-03-01');

		self::assertTrue($since->covers(row: ['d' => '2030-01-01'], dateField: 'd'));
		self::assertFalse($since->covers(row: ['d' => '2026-02-28'], dateField: 'd'));
		self::assertTrue($until->covers(row: ['d' => '1999-01-01'], dateField: 'd'));
		self::assertFalse($until->covers(row: ['d' => '2026-03-02'], dateField: 'd'));
	}//end testAnOpenEndLimitsOnlyTheOther()

	/**
	 * Without a date field or any bound every row is in scope.
	 *
	 * @return void
	 */
	public function testWithoutAFieldOrBoundsEveryRowIsIn(): void {
		self::assertTrue((new LearningRecordPeriod(from: '2026-01-01', to: '2026-01-02'))->covers(row: ['d' => '1990-01-01'], dateField: null));
		self::assertTrue((new LearningRecordPeriod(from: null, to: null))->covers(row: ['d' => '1990-01-01'], dateField: 'd'));
	}//end testWithoutAFieldOrBoundsEveryRowIsIn()

	/**
	 * A row without a usable date stays in scope rather than being guessed at.
	 *
	 * @return void
	 */
	public function testARowWithoutAUsableDateStaysIn(): void {
		$period = new LearningRecordPeriod(from: '2026-01-01', to: '2026-01-31');

		self::assertTrue($period->covers(row: [], dateField: 'd'));
		self::assertTrue($period->covers(row: ['d' => ''], dateField: 'd'));
		self::assertTrue($period->covers(row: ['d' => 'not a date'], dateField: 'd'));
		self::assertTrue($period->covers(row: ['d' => 20260601], dateField: 'd'));
	}//end testARowWithoutAUsableDateStaysIn()

	/**
	 * An unparseable bound is no bound; times within the day are compared.
	 *
	 * @return void
	 */
	public function testAnUnparseableBoundIsIgnoredAndTimesCount(): void {
		$period = new LearningRecordPeriod(from: 'garbage', to: '2026-01-31');

		self::assertTrue($period->covers(row: ['d' => '1900-01-01'], dateField: 'd'));
		self::assertFalse($period->covers(row: ['d' => '2026-01-31T00:00:01'], dateField: 'd'));
	}//end testAnUnparseableBoundIsIgnoredAndTimesCount()
}//end class
