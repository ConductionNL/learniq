<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Learniq\Service\LessonNoteReader;
use OCA\Learniq\Service\TimetableProjector;
use OCA\Learniq\Tests\Support\CapturingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Pins resolveWindow() and which sessions windowedSessions() keeps against
 * golden/TimetableProjectorWindow.json, case by case. With RECORD_GOLDEN=1
 * the file is written instead of compared. The default window depends on the
 * clock, so it is checked against the current week instead.
 *
 * @covers \OCA\Learniq\Service\TimetableProjector
 */
class TimetableProjectorWindowGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/TimetableProjectorWindow.json';

	/**
	 * Every explicit window and every session-window pair answers as recorded.
	 *
	 * @return void
	 */
	public function testWindowsMatchGolden(): void {
		$logger = new CapturingLogger();
		$projector = new TimetableProjector(logger: $logger, noteReader: $this->createMock(originalClassName: LessonNoteReader::class));

		$actual = [];
		foreach ([['2026-11-02T08:00:00+01:00', '2026-11-03'], ['2026-11-02', null], ['2026-11-02', ''], ['2026-11-02', '  '], ['2026-11-02', 'nonsense']] as [$from, $to]) {
			$actual['window ' . json_encode([$from, $to])] = ['window' => $projector->resolveWindow(from: $from, to: $to), 'log' => $logger->records];
			$logger->records = [];
		}

		$sessions = [];
		foreach ($this->sessions() as $index => $session) {
			$sessions[] = ['id' => 's' . $index] + $session;
		}

		$windows = [
			'week' => ['2026-11-02T00:00:00+00:00', '2026-11-09T00:00:00+00:00'],
			'tuesday' => ['2026-11-03T00:00:00+00:00', '2026-11-04T00:00:00+00:00'],
			'unparseable start' => ['not a date', '2026-11-04T00:00:00+00:00'],
			'unparseable end' => ['2026-11-03T00:00:00+00:00', 'not a date'],
		];
		foreach ($windows as $name => $window) {
			$kept = $projector->windowedSessions(rawSessions: $sessions, window: $window, roomCache: [], notes: ['s1' => ['n']]);
			$actual['sessions in ' . $name] = array_map(static fn (array $session): array => [$session['id'], $session['notes']], $kept);
		}

		$actual = json_decode((string)json_encode($actual, JSON_UNESCAPED_SLASHES), true);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		self::assertSame(expected: array_keys($golden), actual: array_keys($actual));
		foreach ($golden as $name => $expected) {
			self::assertSame(expected: $expected, actual: $actual[$name], message: $name);
		}
	}//end testWindowsMatchGolden()

	/**
	 * A missing, blank or unparseable start falls back to this week's Monday, and the end to a week later.
	 *
	 * @return void
	 */
	public function testTheDefaultWindowIsThisWeek(): void {
		$logger = new CapturingLogger();
		$projector = new TimetableProjector(logger: $logger, noteReader: $this->createMock(originalClassName: LessonNoteReader::class));
		$monday = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('monday this week')->setTime(0, 0, 0);
		$week = [$monday->format(DateTimeInterface::ATOM), $monday->modify('+7 days')->format(DateTimeInterface::ATOM)];

		self::assertSame(expected: $week, actual: $projector->resolveWindow(from: null, to: null));
		self::assertSame(expected: $week, actual: $projector->resolveWindow(from: ' ', to: ''));
		self::assertSame(expected: [], actual: $logger->records);
		self::assertSame(expected: $week, actual: $projector->resolveWindow(from: 'garbage', to: null));
		self::assertSame(
			expected: [$week[0], '2026-12-01T00:00:00+00:00'],
			actual: $projector->resolveWindow(from: null, to: '2026-12-01')
		);
		self::assertCount(expectedCount: 1, haystack: $logger->records);
		self::assertSame(expected: ['from' => 'garbage'], actual: $logger->records[0]['context'] ?? null);
	}//end testTheDefaultWindowIsThisWeek()

	/**
	 * Sessions on both sides of each window edge, without times, and with unreadable times.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function sessions(): array {
		return [
			['startsAt' => '2026-11-03T09:00:00+00:00', 'endsAt' => '2026-11-03T10:00:00+00:00'],
			['startsAt' => '2026-11-02T23:00:00+00:00', 'endsAt' => '2026-11-03T00:00:00+00:00'],
			['startsAt' => '2026-11-02T22:00:00+00:00', 'endsAt' => '2026-11-02T23:59:59+00:00'],
			['startsAt' => '2026-11-04T00:00:00+00:00', 'endsAt' => '2026-11-04T01:00:00+00:00'],
			['startsAt' => '2026-11-03T23:59:59+00:00'],
			['startsAt' => '2026-11-02T23:30:00+00:00', 'endsAt' => ''],
			['startsAt' => '2026-11-02T23:30:00+00:00', 'endsAt' => 'garbage'],
			['startsAt' => '', 'endsAt' => '2026-11-03T10:00:00+00:00'],
			['endsAt' => '2026-11-03T10:00:00+00:00'],
			['startsAt' => 'garbage'],
			['startsAt' => '2026-11-01T09:00:00+00:00', 'endsAt' => '2026-11-05T10:00:00+00:00'],
			['startsAt' => '2026-11-03T08:00:00+00:00', 'endsAt' => '2026-11-03T07:00:00+00:00'],
		];
	}//end sessions()
}//end class
