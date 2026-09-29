<?php

/**
 * ElectiveBoard: free places, windows and the not-signed-up list.
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
 * @spec openspec/specs/enrolment/spec.md#requirement-a-coordinator-places-learners-who-missed-the-window
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Learniq\Service\ElectiveBoard;
use OCA\Learniq\Service\ElectiveService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * What the learner and the coordinator see.
 */
class ElectiveBoardTest extends TestCase {

	/**
	 * The board over one offer with a learniq lesson and a planninq lesson.
	 *
	 * @return ElectiveBoard
	 */
	private function board(): ElectiveBoard {
		$offer = [
			'id' => 'offer-1', 'name' => 'Keuzewerktijd wiskunde', 'lifecycle' => 'open', 'capacityPerLesson' => 24,
			'sessionIds' => ['lesson-1'], 'eligibleCohortIds' => ['cohort-h4'], 'windowMode' => 'relative',
			'opensDaysBefore' => 7, 'closesHoursBefore' => 12,
			'timetableSessionRefs' => [['sourceSystem' => 'planninq', 'externalRef' => 'zermelo-77', 'startsAt' => '2026-10-15T10:15:00+02:00', 'title' => 'Donderdag 15']],
		];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn(OrEntityFactory::make($offer, 'elective-offer'));
		$objects->method('findAll')->willReturnCallback(
			static fn (array $config): array => match ($config['filters']['schema'] ?? '') {
				'elective-offer' => [$offer],
				'session' => [['id' => 'lesson-1', 'title' => 'Donderdag 8', 'startsAt' => '2026-10-08T10:15:00+02:00', 'endsAt' => '2026-10-08T11:05:00+02:00']],
				'cohort' => [['id' => 'cohort-h4', 'learnerIds' => ['j.bakker', 't.smit', 'a.jansen']]],
				'elective-sign-up' => [
					['id' => 'su-1', 'offerId' => 'offer-1', 'sessionId' => 'lesson-1', 'learnerId' => 'j.bakker', 'status' => 'signed-up'],
					['id' => 'su-2', 'offerId' => 'offer-1', 'sessionId' => 'lesson-1', 'learnerId' => 'a.jansen', 'status' => 'withdrawn'],
					['id' => 'su-3', 'offerId' => 'offer-1', 'timetableSessionRef' => ['sourceSystem' => 'planninq', 'externalRef' => 'zermelo-77'], 'learnerId' => 't.smit', 'status' => 'placed'],
				],
				default => [],
			}
		);

		return new ElectiveBoard(new ElectiveService($objects));
	}//end board()

	/**
	 * The learner sees both lessons with free places, the window and their own sign-up.
	 *
	 * @return void
	 */
	public function testALearnerSeesFreePlacesAndTheWindow(): void {
		$offers = $this->board()->forLearner('j.bakker', new DateTimeImmutable('2026-10-05T12:00:00+02:00'));

		self::assertCount(1, $offers);
		$lessons = $offers[0]['lessons'];
		self::assertSame(['lesson-1', 'planninq:zermelo-77'], array_column($lessons, 'key'));
		self::assertSame([23, 23], array_column($lessons, 'freePlaces'));
		self::assertTrue($lessons[0]['windowOpen']);
		self::assertFalse($lessons[1]['windowOpen'], 'The planninq lesson opens 7 days before 15 October.');
		self::assertSame('su-1', $lessons[0]['mySignUpId']);
		self::assertNull($lessons[1]['mySignUpId']);
	}//end testALearnerSeesFreePlacesAndTheWindow()

	/**
	 * A learner outside the eligible groups sees no offer.
	 *
	 * @return void
	 */
	public function testIneligibleLearnerSeesNothing(): void {
		self::assertSame([], $this->board()->forLearner('v.other', new DateTimeImmutable('2026-10-05')));
	}//end testIneligibleLearnerSeesNothing()

	/**
	 * The coordinator sees who signed up and who did not, per lesson.
	 *
	 * @return void
	 */
	public function testRosterListsWhoDidNotSignUp(): void {
		$roster = $this->board()->roster('offer-1', new DateTimeImmutable('2026-10-08T09:00:00+02:00'));

		self::assertSame(['j.bakker'], array_column($roster['lessons'][0]['signUps'], 'learnerId'));
		self::assertSame(['t.smit', 'a.jansen'], $roster['lessons'][0]['notSignedUp']);
		self::assertFalse($roster['lessons'][0]['windowOpen']);
		self::assertSame(['j.bakker', 'a.jansen'], $roster['lessons'][1]['notSignedUp']);
	}//end testRosterListsWhoDidNotSignUp()
}//end class
