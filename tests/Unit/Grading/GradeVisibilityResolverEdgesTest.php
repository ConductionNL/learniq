<?php

/**
 * GradeVisibilityResolver edge cases: the cutoff moment, unusable policy values, unknown modes.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Grading
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

namespace OCA\Learniq\Tests\Unit\Grading;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Grading\GradeVisibilityResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Edges of when a published grade becomes visible.
 */
#[CoversClass(GradeVisibilityResolver::class)]
class GradeVisibilityResolverEdgesTest extends TestCase {

	/**
	 * A moment in Amsterdam.
	 *
	 * @param string $local The local date and time.
	 *
	 * @return DateTimeImmutable
	 */
	private static function at(string $local): DateTimeImmutable {
		return new DateTimeImmutable($local, new DateTimeZone('Europe/Amsterdam'));
	}//end at()

	/**
	 * Publishing exactly at the cutoff counts as past it: the grade shows the next school day.
	 *
	 * @return void
	 */
	public function testPublishingExactlyAtTheCutoffRollsForward(): void {
		// Monday 2026-07-13 10:00 exactly.
		$result = (new GradeVisibilityResolver())->resolve(
			override: null,
			policy: ['mode' => 'nextSchoolDay', 'time' => '10:00', 'timezone' => 'Europe/Amsterdam'],
			publishedAt: self::at('2026-07-13 10:00:00')
		);

		self::assertSame('2026-07-14 10:00:00', $result->format('Y-m-d H:i:s'));
	}//end testPublishingExactlyAtTheCutoffRollsForward()

	/**
	 * Seconds after the cutoff minute are past it as well.
	 *
	 * @return void
	 */
	public function testSecondsPastTheCutoffRollForward(): void {
		$result = (new GradeVisibilityResolver())->resolve(
			override: null,
			policy: ['mode' => 'nextSchoolDay', 'time' => '10:00'],
			publishedAt: self::at('2026-07-13 10:00:01')
		);

		self::assertSame('2026-07-14 10:00:00', $result->format('Y-m-d H:i:s'));
	}//end testSecondsPastTheCutoffRollForward()

	/**
	 * An unknown timezone falls back to Amsterdam.
	 *
	 * @return void
	 */
	public function testAnUnknownTimezoneFallsBackToAmsterdam(): void {
		$result = (new GradeVisibilityResolver())->resolve(
			override: null,
			policy: ['mode' => 'nextSchoolDay', 'time' => '09:00', 'timezone' => 'Mars/Olympus'],
			publishedAt: new DateTimeImmutable('2026-07-13 05:00:00', new DateTimeZone('UTC'))
		);

		self::assertSame('Europe/Amsterdam', $result->getTimezone()->getName());
		self::assertSame('2026-07-13 09:00:00', $result->format('Y-m-d H:i:s'));
	}//end testAnUnknownTimezoneFallsBackToAmsterdam()

	/**
	 * A time that is no HH:MM in range falls back to midnight; a single-digit hour is accepted.
	 *
	 * @return void
	 */
	public function testAnUnusableTimeIsMidnight(): void {
		$resolver = new GradeVisibilityResolver();
		$monday = self::at('2026-07-13 08:00:00');

		foreach (['24:00', '10:60', 'ten', '10', 42] as $time) {
			$result = $resolver->resolve(override: null, policy: ['mode' => 'nextSchoolDay', 'time' => $time], publishedAt: $monday);
			self::assertSame('2026-07-14 00:00:00', $result->format('Y-m-d H:i:s'), 'time ' . var_export($time, true));
		}

		$single = $resolver->resolve(override: null, policy: ['mode' => 'nextSchoolDay', 'time' => '9:30'], publishedAt: $monday);
		self::assertSame('2026-07-13 09:30:00', $single->format('Y-m-d H:i:s'));
	}//end testAnUnusableTimeIsMidnight()

	/**
	 * Saturday after the cutoff still lands on Monday, not Sunday.
	 *
	 * @return void
	 */
	public function testSaturdayAfterTheCutoffLandsOnMonday(): void {
		$result = (new GradeVisibilityResolver())->resolve(
			override: null,
			policy: ['mode' => 'nextSchoolDay', 'time' => '07:00'],
			publishedAt: self::at('2026-07-18 20:00:00')
		);

		self::assertSame('2026-07-20 07:00:00', $result->format('Y-m-d H:i:s'));
		self::assertSame('1', $result->format('N'));
	}//end testSaturdayAfterTheCutoffLandsOnMonday()

	/**
	 * An unknown mode, or a policy without a mode, shows the grade at once.
	 *
	 * @return void
	 */
	public function testAnUnknownModeShowsTheGradeAtOnce(): void {
		$resolver = new GradeVisibilityResolver();
		$published = self::at('2026-07-18 20:00:00');

		self::assertSame($published, $resolver->resolve(override: null, policy: ['mode' => 'nextWeek'], publishedAt: $published));
		self::assertSame($published, $resolver->resolve(override: '', policy: ['time' => '07:00'], publishedAt: $published));
	}//end testAnUnknownModeShowsTheGradeAtOnce()

	/**
	 * An override wins even when it lies before the publish moment.
	 *
	 * @return void
	 */
	public function testAnOverrideWinsEvenInThePast(): void {
		$result = (new GradeVisibilityResolver())->resolve(
			override: '2026-01-01T12:00:00+00:00',
			policy: ['mode' => 'nextSchoolDay', 'time' => '07:00'],
			publishedAt: self::at('2026-07-18 20:00:00')
		);

		self::assertSame('2026-01-01T12:00:00+00:00', $result->format('c'));
	}//end testAnOverrideWinsEvenInThePast()
}//end class
