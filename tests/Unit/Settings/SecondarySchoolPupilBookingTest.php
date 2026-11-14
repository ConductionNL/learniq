<?php

/**
 * The secondary school example set's support lesson and mentor-talk round,
 * as the pupil books them herself.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Steunles wiskunde with six places left, and a mentor-talk round Noor books herself.
 *
 * @coversNothing
 */
class SecondarySchoolPupilBookingTest extends TestCase {

	/**
	 * The seed buckets of the secondary school set.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function objects(): array {
		$path = dirname(path: __DIR__, levels: 3) . '/lib/Settings/profiles/vo.json';

		return json_decode(json: (string)file_get_contents(filename: $path), associative: true)['x-openregister']['seedData']['objects'];
	}//end objects()

	/**
	 * The support lesson is open for her group, Tuesday's eighth hour in 2.14,
	 * twelve places of which six are taken, and its copies say so.
	 *
	 * @return void
	 */
	public function testTheSupportLessonHasSixPlacesLeft(): void {
		$objects = self::objects();
		$offers = array_values(array_filter($objects['elective-offer'], static fn (array $o): bool => $o['name'] === 'Steunles wiskunde'));
		self::assertCount(1, $offers);
		$offer = $offers[0];

		self::assertSame(['open', 12, 'Meneer Bos'], [$offer['lifecycle'], $offer['capacityPerLesson'], $offer['teacherName']]);
		self::assertNotEmpty($offer['eligibleCohortIds']);
		$taken = array_filter(
			$objects['elective-sign-up'],
			static fn (array $s): bool => $s['offerId'] === $offer['uuid'] && $s['status'] !== 'withdrawn'
		);
		self::assertCount(6, $taken);
		self::assertSame([6, 'Nog 6 plekken', 'Lokaal 2.14'], [$offer['placesLeft'], $offer['placesLeftLine'], $offer['nextLessonLocation']]);

		$lesson = array_values(array_filter($objects['session'], static fn (array $s): bool => $s['uuid'] === $offer['sessionIds'][0]))[0];
		self::assertSame([$lesson['startsAt'], $lesson['endsAt']], [$offer['nextLessonStartsAt'], $offer['nextLessonEndsAt']]);
		self::assertSame('2026-10-06T15:20:00+02:00', $lesson['startsAt']);
	}//end testTheSupportLessonHasSixPlacesLeft()

	/**
	 * The mentor-talk round lets pupils book, and so does every one of its
	 * times; Tuesday 16.30 is still free for Noor.
	 *
	 * @return void
	 */
	public function testTheMentorTalksLetThePupilBook(): void {
		$objects = self::objects();
		$rounds = array_values(array_filter($objects['conference-round'], static fn (array $r): bool => str_starts_with($r['name'], 'Mentorgesprekken')));
		self::assertCount(1, $rounds);
		self::assertTrue($rounds[0]['pupilMayBook']);
		self::assertSame('booking-open', $rounds[0]['lifecycle']);

		$times = array_values(array_filter($objects['conference-slot'], static fn (array $s): bool => $s['conferenceRoundId'] === $rounds[0]['uuid']));
		self::assertNotEmpty($times);
		self::assertSame(array_fill(0, count($times), true), array_column($times, 'pupilMayBook'));
		$tuesday = array_values(array_filter($times, static fn (array $s): bool => $s['startsAt'] === '2026-10-13T16:30:00+02:00'));
		self::assertSame('free', $tuesday[0]['lifecycle']);

		$others = array_filter($objects['conference-round'], static fn (array $r): bool => $r['uuid'] !== $rounds[0]['uuid']);
		self::assertSame([], array_filter(array_column($others, 'pupilMayBook')), 'no other round lets pupils book');
	}//end testTheMentorTalksLetThePupilBook()
}//end class
