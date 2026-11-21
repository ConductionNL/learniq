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
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-learner-withdraws-from-an-optional-lesson-and-sees-when-sign-up-opens
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

	/**
	 * One offer of 13 places on a Thursday lesson, over the given sign-ups.
	 *
	 * @param array<int, array<string, mixed>> $signUps The offer's sign-ups as stored.
	 *
	 * @return ElectiveBoard
	 */
	private function thursdayBoard(array $signUps): ElectiveBoard {
		$offer = [
			'id' => 'offer-2', 'name' => 'Steunles Nederlands', 'lifecycle' => 'open', 'capacityPerLesson' => 13,
			'sessionIds' => ['lesson-thu'], 'eligibleCohortIds' => ['cohort-h4'], 'windowMode' => 'relative',
			'opensDaysBefore' => 7, 'closesHoursBefore' => 12,
		];
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('find')->willReturn(OrEntityFactory::make(data: $offer, schema: 'elective-offer'));
		$objects->method('findAll')->willReturnCallback(
			static fn (array $config): array => match ($config['filters']['schema'] ?? '') {
				'elective-offer' => [$offer],
				'session' => [
					['id' => 'lesson-thu', 'title' => 'Donderdag 15', 'startsAt' => '2026-10-15T10:15:00+02:00', 'endsAt' => '2026-10-15T11:05:00+02:00'],
				],
				'cohort' => [['id' => 'cohort-h4', 'learnerIds' => ['j.bakker', 't.smit']]],
				'elective-sign-up' => $signUps,
				default => [],
			}
		);

		return new ElectiveBoard(electives: new ElectiveService(objectService: $objects));
	}//end thursdayBoard()

	/**
	 * Scenario "A learner withdraws inside the window": 12 free with his sign-up, 13 and Sign up after it is withdrawn.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#scenario-a-learner-withdraws-inside-the-window
	 */
	public function testALearnerWithdrawsInsideTheWindow(): void {
		$now    = new DateTimeImmutable(datetime: '2026-10-12T12:00:00+02:00');
		$signUp = ['id' => 'su-9', 'offerId' => 'offer-2', 'sessionId' => 'lesson-thu', 'learnerId' => 'j.bakker', 'status' => 'signed-up'];

		$before = $this->thursdayBoard(signUps: [$signUp])->forLearner(learnerId: 'j.bakker', now: $now)[0]['lessons'][0];
		self::assertSame(expected: 12, actual: $before['freePlaces']);
		self::assertSame(expected: 'su-9', actual: $before['mySignUpId'], message: 'signed up, so the screen offers Withdraw');
		self::assertTrue(condition: $before['windowOpen']);

		$after = $this->thursdayBoard(signUps: [array_merge($signUp, ['status' => 'withdrawn'])])->forLearner(learnerId: 'j.bakker', now: $now)[0]['lessons'][0];
		self::assertSame(expected: 13, actual: $after['freePlaces'], message: 'the withdrawn sign-up frees its place');
		self::assertNull(actual: $after['mySignUpId'], message: 'no sign-up of his, so the screen offers Sign up again');
		self::assertTrue(condition: $after['windowOpen']);
	}//end testALearnerWithdrawsInsideTheWindow()

	/**
	 * Scenario "The window has not opened yet": three days before it opens, the lesson names the date and is closed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#scenario-the-window-has-not-opened-yet
	 */
	public function testTheWindowHasNotOpenedYet(): void {
		$lesson = $this->thursdayBoard(signUps: [])->forLearner(learnerId: 'j.bakker', now: new DateTimeImmutable(datetime: '2026-10-05T10:15:00+02:00'))[0]['lessons'][0];

		self::assertFalse(condition: $lesson['windowOpen'], message: 'no button while the window is closed');
		self::assertSame(expected: '2026-10-08T10:15:00+02:00', actual: $lesson['opensAt'], message: 'sign-up opens seven days before the lesson, three days from now');
		self::assertNull(actual: $lesson['mySignUpId']);
	}//end testTheWindowHasNotOpenedYet()
}//end class
