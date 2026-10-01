<?php

/**
 * Unit tests for TimetableIcsWriter.
 *
 * Every document is read back with sabre/vobject, the iCalendar parser
 * Nextcloud itself uses, so escaping and folding are proven by a round trip.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\TimetableIcsWriter;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;

/**
 * Tests for the iCalendar writer.
 */
class TimetableIcsWriterTest extends TestCase {
	/**
	 * Special characters and long multi-byte text survive the round trip,
	 * and no line is longer than 75 octets.
	 *
	 * @return void
	 */
	public function testEscapingAndFoldingRoundTrip(): void {
		$summary = 'Wiskunde; hoofdstuk 3, opgaven \\ herhaling ' . str_repeat('é€', 40);
		$description = "Regel één\nRegel twee; met komma, en backslash \\";
		$ics = (new TimetableIcsWriter())->write(
			name: 'Mijn rooster',
			events: [
				['uid' => 's-1@learniq', 'start' => '2026-03-02T08:30:00+01:00', 'end' => '2026-03-02T09:20:00+01:00', 'summary' => $summary, 'location' => 'Lokaal 2, vleugel B', 'description' => $description, 'cancelled' => false],
			],
			now: 1767772800
		);

		foreach (explode("\r\n", rtrim($ics, "\r\n")) as $line) {
			$this->assertLessThanOrEqual(75, strlen($line), $line);
			$this->assertTrue(mb_check_encoding($line, 'UTF-8'), 'a fold never splits a character');
		}

		$calendar = Reader::read($ics);
		$this->assertSame([], $calendar->validate());
		$event = $calendar->VEVENT;
		$this->assertSame($summary, (string)$event->SUMMARY);
		$this->assertSame($description, (string)$event->DESCRIPTION);
		$this->assertSame('Lokaal 2, vleugel B', (string)$event->LOCATION);
		$this->assertSame('20260302T073000Z', $event->DTSTART->getValue());
		$this->assertSame('20260302T082000Z', $event->DTEND->getValue());
		$this->assertSame('Mijn rooster', (string)$calendar->{'X-WR-CALNAME'});
	}//end testEscapingAndFoldingRoundTrip()

	/**
	 * A cancelled event is CANCELLED; an event without a parseable start is left out.
	 *
	 * @return void
	 */
	public function testCancelledStatusAndUnparseableStart(): void {
		$ics = (new TimetableIcsWriter())->write(
			name: 'x',
			events: [
				['uid' => 'a', 'start' => '2026-03-02T08:30:00Z', 'end' => '', 'summary' => 'A', 'cancelled' => true],
				['uid' => 'b', 'start' => 'not a time', 'summary' => 'B'],
				['uid' => 'c', 'start' => '', 'summary' => 'C'],
			],
			now: 1767772800
		);

		$calendar = Reader::read($ics);
		$events = array_values($calendar->select('VEVENT'));
		$this->assertCount(1, $events);
		$this->assertSame('CANCELLED', (string)$events[0]->STATUS);
		$this->assertFalse(isset($events[0]->DTEND));
		$this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
	}//end testCancelledStatusAndUnparseableStart()
}//end class
