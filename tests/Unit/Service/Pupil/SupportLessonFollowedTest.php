<?php

/**
 * Unit tests for SupportLessonFollowed.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Service\Pupil
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T4
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Pupil;

use OCA\Learniq\Service\Pupil\SupportLessonFollowed;
use PHPUnit\Framework\TestCase;

/**
 * The support lesson a pupil follows is her first coming place.
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T4
 */
class SupportLessonFollowedTest extends TestCase {

	/**
	 * Noor signed up for the wiskunde A support lesson on Tuesday 6 October.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T4
	 */
	public function testNoorsSupportLessonFromSixOctober(): void {
		$signUps = [
			['offerId' => 'off-old', 'status' => 'placed', 'lessonStartsAt' => '2026-09-29T14:00:00+02:00', 'offerName' => 'Steunles Engels'],
			['offerId' => 'off-gone', 'status' => 'withdrawn', 'lessonStartsAt' => '2026-10-05T14:00:00+02:00', 'offerName' => 'Steunles Frans'],
			['offerId' => 'off-later', 'status' => 'signed-up', 'lessonStartsAt' => '2026-10-13T14:00:00+02:00', 'offerName' => 'Steunles Duits'],
			['offerId' => 'off-wia', 'status' => 'signed-up', 'lessonStartsAt' => '2026-10-06T14:00:00+02:00', 'offerName' => 'Wiskunde A'],
		];
		$followed = new SupportLessonFollowed();

		$signUp = $followed->pick(signUps: $signUps, today: '2026-10-05');
		self::assertSame(expected: 'off-wia', actual: $signUp['offerId']);

		$line = $followed->line(signUp: $signUp, offer: ['name' => 'Steunles wiskunde A', 'teacherName' => 'Meneer Demir']);
		self::assertSame(expected: 'Steunles wiskunde A · Meneer Demir', actual: $line['name']);
		self::assertSame(expected: ['support-lesson', null, '2026-10-06'], actual: [$line['role'], $line['userId'], $line['from']]);
	}//end testNoorsSupportLessonFromSixOctober()

	/**
	 * No sign-up, only a withdrawn or a passed one: none. Without its offer the
	 * line reads the sign-up's own copy of the name; an undated place counts.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T4
	 */
	public function testNoSupportLessonWithoutAComingPlace(): void {
		$followed = new SupportLessonFollowed();
		$gone     = [
			['status' => 'withdrawn', 'lessonStartsAt' => '2026-10-06T14:00:00+02:00'],
			['status' => 'placed', 'lessonStartsAt' => '2026-10-01T14:00:00+02:00'],
		];

		self::assertNull(actual: $followed->pick(signUps: [], today: '2026-10-05'));
		self::assertNull(actual: $followed->pick(signUps: $gone, today: '2026-10-05'));

		$undated = $followed->pick(signUps: [['status' => 'placed', 'offerName' => 'Huiswerkklas']], today: '2026-10-05');
		$line    = $followed->line(signUp: $undated, offer: null);
		self::assertSame(expected: ['Huiswerkklas', null], actual: [$line['name'], $line['from']]);
	}//end testNoSupportLessonWithoutAComingPlace()
}//end class
