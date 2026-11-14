<?php

/**
 * Learniq ElectiveOfferCopies unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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

namespace OCA\Learniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Learniq\Service\ElectiveOfferCopies;
use OCA\Learniq\Service\ElectiveService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The next lesson a pupil can still sign up for, and its places left.
 */
#[CoversClass(ElectiveOfferCopies::class)]
#[UsesClass(ElectiveService::class)]
class ElectiveOfferCopiesTest extends TestCase {

	/**
	 * The lessons the offer names.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sessions = [];

	/**
	 * The offer's sign-ups.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $signUps = [];

	/**
	 * The copies service over a real ElectiveService.
	 *
	 * @return ElectiveOfferCopies
	 */
	private function copies(): ElectiveOfferCopies {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config): array => match ($config['filters']['schema'] ?? '') {
				'session' => $this->sessions,
				'elective-sign-up' => $this->signUps,
				default => [],
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'nl:' . $text);
		$l10n->method('n')->willReturnCallback(
			static fn (string $one, string $many, int $count): string => str_replace('%n', (string)$count, ($count === 1) ? $one : $many)
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		return new ElectiveOfferCopies(electives: new ElectiveService($objects), l10nFactory: $factory);
	}//end copies()

	/**
	 * The support lesson of the board: Tuesdays, capacity 12.
	 *
	 * @return array<string, mixed>
	 */
	private function offer(): array {
		return [
			'id' => 'offer-1',
			'name' => 'Steunles wiskunde A',
			'sessionIds' => ['past', 'tue-13', 'tue-6'],
			'capacityPerLesson' => 12,
			'windowMode' => 'relative',
			'opensDaysBefore' => 7,
			'closesHoursBefore' => 2,
		];
	}//end offer()

	/**
	 * The next lesson is the first that has not started, in time order; its
	 * room and places left come with it, and only its own active sign-ups count.
	 *
	 * @return void
	 */
	public function testTheNextLessonAndItsPlacesLeft(): void {
		$this->sessions = [
			['id' => 'past', 'startsAt' => '2026-09-29T15:20:00+02:00', 'endsAt' => '2026-09-29T16:10:00+02:00', 'location' => '2.14'],
			['id' => 'tue-13', 'startsAt' => '2026-10-13T15:20:00+02:00', 'endsAt' => '2026-10-13T16:10:00+02:00', 'location' => '2.14'],
			['id' => 'tue-6', 'startsAt' => '2026-10-06T15:20:00+02:00', 'endsAt' => '2026-10-06T16:10:00+02:00', 'location' => '2.14'],
		];
		$this->signUps = [];
		for ($i = 0; $i < 6; $i++) {
			$this->signUps[] = ['offerId' => 'offer-1', 'sessionId' => 'tue-6', 'status' => 'signed-up'];
		}

		$this->signUps[] = ['offerId' => 'offer-1', 'sessionId' => 'tue-6', 'status' => 'withdrawn'];
		$this->signUps[] = ['offerId' => 'offer-1', 'sessionId' => 'tue-13', 'status' => 'signed-up'];

		$copies = $this->copies()->copies(offer: $this->offer(), now: new DateTimeImmutable('2026-10-05T12:00:00+02:00'));

		self::assertSame(
			[
				'nextLessonStartsAt' => '2026-10-06T15:20:00+02:00',
				'nextLessonEndsAt' => '2026-10-06T16:10:00+02:00',
				'nextLessonLocation' => '2.14',
				'placesLeft' => 6,
				'placesLeftLine' => '6 places left',
			],
			$copies
		);
	}//end testTheNextLessonAndItsPlacesLeft()

	/**
	 * A lesson whose window has closed is skipped; a full lesson reads as
	 * having no places left, and one place reads in the singular.
	 *
	 * @return void
	 */
	public function testAClosedWindowIsSkippedAndTheLineFollowsTheCount(): void {
		$this->sessions = [
			['id' => 'tue-6', 'startsAt' => '2026-10-06T15:20:00+02:00', 'endsAt' => '2026-10-06T16:10:00+02:00', 'location' => ''],
			['id' => 'tue-13', 'startsAt' => '2026-10-13T15:20:00+02:00', 'endsAt' => '2026-10-13T16:10:00+02:00', 'location' => ''],
		];
		$offer = array_merge($this->offer(), ['capacityPerLesson' => 1]);
		$this->signUps = [['offerId' => 'offer-1', 'sessionId' => 'tue-13', 'status' => 'signed-up']];

		// One hour before Tuesday the 6th its window (two hours before) has closed.
		$copies = $this->copies()->copies(offer: $offer, now: new DateTimeImmutable('2026-10-06T14:20:00+02:00'));
		self::assertSame('2026-10-13T15:20:00+02:00', $copies['nextLessonStartsAt']);
		self::assertNull($copies['nextLessonLocation'], 'an empty room is no room');
		self::assertSame(0, $copies['placesLeft']);
		self::assertSame('nl:No places left', $copies['placesLeftLine']);

		$this->signUps = [];
		self::assertSame('1 place left', $this->copies()->copies(offer: $offer, now: new DateTimeImmutable('2026-10-06T14:20:00+02:00'))['placesLeftLine']);
	}//end testAClosedWindowIsSkippedAndTheLineFollowsTheCount()

	/**
	 * An offer with no lesson left keeps every copy empty; an offer not yet
	 * saved counts no sign-ups; differ() compares every copy field.
	 *
	 * @return void
	 */
	public function testNoLessonLeftEmptiesTheCopiesAndDifferComparesThem(): void {
		$this->sessions = [['id' => 'past', 'startsAt' => '2026-09-29T15:20:00+02:00', 'endsAt' => '2026-09-29T16:10:00+02:00']];
		$copies = $this->copies()->copies(offer: $this->offer(), now: new DateTimeImmutable('2026-10-05T12:00:00+02:00'));
		self::assertSame(array_fill_keys(ElectiveOfferCopies::FIELDS, null), $copies);

		$this->sessions = [['id' => 'tue-6', 'startsAt' => '2026-10-06T15:20:00+02:00', 'endsAt' => '2026-10-06T16:10:00+02:00']];
		$this->signUps = [['offerId' => '', 'sessionId' => 'tue-6', 'status' => 'signed-up']];
		$unsaved = array_merge($this->offer(), ['id' => '']);
		$fresh = $this->copies()->copies(offer: $unsaved, now: new DateTimeImmutable('2026-10-05T12:00:00+02:00'));
		self::assertSame(12, $fresh['placesLeft'], 'an offer without an id has no sign-ups yet');

		$service = $this->copies();
		self::assertFalse($service->differ(offer: array_merge($this->offer(), $fresh), copies: $fresh));
		self::assertTrue($service->differ(offer: array_merge($this->offer(), $fresh, ['placesLeft' => 11]), copies: $fresh));
		self::assertTrue($service->differ(offer: $this->offer(), copies: $fresh), 'an offer without copies differs');
	}//end testNoLessonLeftEmptiesTheCopiesAndDifferComparesThem()
}//end class
