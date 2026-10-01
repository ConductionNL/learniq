<?php

/**
 * Learniq Timetable iCalendar Writer
 *
 * Writes calendar events as an RFC 5545 iCalendar document: CRLF line ends,
 * text values escaped, lines folded at 75 octets without splitting a UTF-8
 * character. Times are written in UTC (the `Z` form), which every calendar
 * app shows in the reader's own time zone, so no VTIMEZONE block is needed
 * (attendance-timetable-calendar-feed D4).
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 *
 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-calendar-subscription-feed
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Turns a list of events into an iCalendar document.
 */
class TimetableIcsWriter {
	/**
	 * Longest line in octets, before the CRLF (RFC 5545 section 3.1).
	 *
	 * @var int
	 */
	private const LINE_OCTETS = 75;

	/**
	 * Write a calendar.
	 *
	 * Each event: `uid`, `start` and `end` (ISO 8601; `end` may be empty),
	 * `summary`, `location`, `description` (plain text, may hold new lines),
	 * `cancelled` (bool). An event whose start does not parse is left out.
	 *
	 * @param string                         $name   The calendar's name.
	 * @param array<int,array<string,mixed>> $events The events.
	 * @param int                            $now    The stamp time (unix seconds).
	 *
	 * @return string The iCalendar document.
	 *
	 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-calendar-subscription-feed
	 */
	public function write(string $name, array $events, int $now): string {
		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Conduction//learniq timetable//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . $this->text(value: $name),
			'X-PUBLISHED-TTL:PT1H',
			'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
		];

		$stamp = gmdate('Ymd\THis\Z', $now);
		foreach ($events as $event) {
			$lines = array_merge($lines, $this->event(event: $event, stamp: $stamp));
		}

		$lines[] = 'END:VCALENDAR';

		$out = '';
		foreach ($lines as $line) {
			$out .= $this->fold(line: $line) . "\r\n";
		}

		return $out;
	}//end write()

	/**
	 * The lines of one event, or none when its start does not parse.
	 *
	 * @param array<string,mixed> $event The event.
	 * @param string              $stamp The DTSTAMP value.
	 *
	 * @return array<int,string>
	 */
	private function event(array $event, string $stamp): array {
		$start = $this->utc(value: (string)($event['start'] ?? ''));
		if ($start === null) {
			return [];
		}

		$lines = [
			'BEGIN:VEVENT',
			'UID:' . $this->text(value: (string)($event['uid'] ?? '')),
			'DTSTAMP:' . $stamp,
			'DTSTART:' . $start,
		];

		$end = $this->utc(value: (string)($event['end'] ?? ''));
		if ($end !== null) {
			$lines[] = 'DTEND:' . $end;
		}

		$lines[] = 'SUMMARY:' . $this->text(value: (string)($event['summary'] ?? ''));
		foreach (['location' => 'LOCATION', 'description' => 'DESCRIPTION'] as $field => $property) {
			$value = (string)($event[$field] ?? '');
			if ($value !== '') {
				$lines[] = $property . ':' . $this->text(value: $value);
			}
		}

		$status = 'CONFIRMED';
		if (($event['cancelled'] ?? false) === true) {
			$status = 'CANCELLED';
		}

		$lines[] = 'STATUS:' . $status;
		$lines[] = 'TRANSP:OPAQUE';
		$lines[] = 'END:VEVENT';

		return $lines;
	}//end event()

	/**
	 * An ISO 8601 time in the iCalendar UTC form, or null.
	 *
	 * @param string $value The time.
	 *
	 * @return string|null
	 */
	private function utc(string $value): ?string {
		if (trim($value) === '') {
			return null;
		}

		$timestamp = strtotime($value);
		if ($timestamp === false) {
			return null;
		}

		return gmdate('Ymd\THis\Z', $timestamp);
	}//end utc()

	/**
	 * Escape a TEXT value (RFC 5545 section 3.3.11).
	 *
	 * @param string $value The plain text.
	 *
	 * @return string
	 */
	private function text(string $value): string {
		$value = str_replace(["\r\n", "\r"], "\n", $value);
		return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $value);
	}//end text()

	/**
	 * Fold a content line at 75 octets, never inside a UTF-8 character.
	 *
	 * @param string $line The unfolded line.
	 *
	 * @return string The folded line, continuation lines starting with a space.
	 */
	private function fold(string $line): string {
		$parts = [];
		$current = '';
		$limit = self::LINE_OCTETS;
		$chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
		if ($chars === false) {
			$chars = str_split($line);
		}

		foreach ($chars as $char) {
			if (strlen($current) + strlen($char) > $limit) {
				$parts[] = $current;
				$current = '';
				// A continuation line starts with one space, which counts.
				$limit = (self::LINE_OCTETS - 1);
			}

			$current .= $char;
		}

		$parts[] = $current;

		return implode("\r\n ", $parts);
	}//end fold()
}//end class
