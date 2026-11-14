<?php

/**
 * Tests for HourReturnSubject.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bpv-hours-per-day/specs/bpv/spec.md#requirement-a-returned-week-names-the-day-and-the-question
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\HourReturnSubject;
use PHPUnit\Framework\TestCase;

/**
 * The message line of a returned week names the day, else the week.
 */
class HourReturnSubjectTest extends TestCase {

	/**
	 * A returned day reads as its weekday, day and month, the way the board writes it.
	 *
	 * @return void
	 */
	public function testAReturnedDayIsNamedInFull(): void {
		self::assertSame('Vraag over je uren van dinsdag 29 september', HourReturnSubject::of(date: '2026-09-29', isoWeek: '2026-W40'));
		self::assertSame('Vraag over je uren van maandag 5 oktober', HourReturnSubject::of(date: '2026-10-05', isoWeek: '2026-W41'));
		self::assertSame('Vraag over je uren van zondag 1 maart', HourReturnSubject::of(date: '2026-03-01', isoWeek: '2026-W09'));
	}//end testAReturnedDayIsNamedInFull()

	/**
	 * Without a readable day the line names the week, and without a readable week "deze week".
	 *
	 * @return void
	 */
	public function testWithoutADayTheWeekIsNamed(): void {
		self::assertSame('Vraag over je uren van week 40', HourReturnSubject::of(date: '', isoWeek: '2026-W40'));
		self::assertSame('Vraag over je uren van week 9', HourReturnSubject::of(date: '2026-02-30', isoWeek: '2026-W09'));
		self::assertSame('Vraag over je uren van deze week', HourReturnSubject::of(date: '', isoWeek: 'week 40'));
	}//end testWithoutADayTheWeekIsNamed()
}//end class
