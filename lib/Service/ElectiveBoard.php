<?php

/**
 * Learniq Elective Board
 *
 * What the learner's "Optional lessons" page and the coordinator's roster
 * show: per lesson its times, free places and window, the learner's own
 * sign-up, and for staff who signed up and which eligible learners did not.
 * Reads only, through ElectiveService.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * The learner's offers and the coordinator's roster.
 *
 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
 */
class ElectiveBoard {

	/**
	 * Constructor.
	 *
	 * @param ElectiveService $electives Offers, lessons and places.
	 */
	public function __construct(
		private readonly ElectiveService $electives,
	) {
	}//end __construct()

	/**
	 * The open offers a learner may sign up for, with their lessons.
	 *
	 * @param string            $learnerId The learner.
	 * @param DateTimeImmutable|null $now       Now; the current time when null.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
	 */
	public function forLearner(string $learnerId, ?DateTimeImmutable $now=null): array {
		$now = ($now ?? new DateTimeImmutable('now'));
		$offers = [];
		foreach ($this->electives->openOffers() as $offer) {
			$eligible = $this->electives->eligibleLearners(offer: $offer);
			if ($eligible !== null && in_array($learnerId, $eligible, true) === false) {
				continue;
			}

			$signUps = $this->electives->activeSignUps(offerId: (string)$offer['id']);
			$lessons = [];
			foreach ($this->electives->lessons(offer: $offer) as $key => $lesson) {
				$lessons[] = array_merge(
					$this->lessonState(offer: $offer, lesson: $lesson, signUps: $signUps, now: $now),
					['mySignUpId' => $this->mine(signUps: $signUps, key: $key, learnerId: $learnerId)]
				);
			}

			$offers[] = [
				'id' => (string)$offer['id'],
				'name' => (string)($offer['name'] ?? ''),
				'description' => (string)($offer['description'] ?? ''),
				'lessons' => $lessons,
			];
		}//end foreach

		return $offers;
	}//end forLearner()

	/**
	 * Per lesson who signed up and which eligible learners did not.
	 *
	 * @param string            $offerId The offer.
	 * @param DateTimeImmutable|null $now     Now; the current time when null.
	 *
	 * @return array<string, mixed>|null The roster, or null for an unknown offer.
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-coordinator-places-learners-who-missed-the-window
	 */
	public function roster(string $offerId, ?DateTimeImmutable $now=null): ?array {
		$now = ($now ?? new DateTimeImmutable('now'));
		$offer = $this->electives->offer(offerId: $offerId);
		if ($offer === null) {
			return null;
		}

		$eligible = ($this->electives->eligibleLearners(offer: $offer) ?? []);
		$signUps = $this->electives->activeSignUps(offerId: $offerId);
		$lessons = [];
		foreach ($this->electives->lessons(offer: $offer) as $key => $lesson) {
			$here = array_values(array_filter($signUps, fn (array $row): bool => $this->electives->lessonKey(row: $row) === $key));
			$lessons[] = array_merge(
				$this->lessonState(offer: $offer, lesson: $lesson, signUps: $signUps, now: $now),
				[
					'signUps' => array_map(
						static fn (array $row): array => [
							'id' => (string)($row['id'] ?? ''),
							'learnerId' => (string)($row['learnerId'] ?? ''),
							'status' => (string)($row['status'] ?? ''),
							'madeVia' => $row['madeVia'] ?? null,
						],
						$here
					),
					'notSignedUp' => array_values(array_diff($eligible, array_column($here, 'learnerId'))),
				]
			);
		}

		return [
			'id' => $offerId,
			'name' => (string)($offer['name'] ?? ''),
			'capacityPerLesson' => (int)($offer['capacityPerLesson'] ?? 0),
			'lessons' => $lessons,
		];
	}//end roster()

	/**
	 * A lesson's times, window and free places.
	 *
	 * @param array<string, mixed>             $offer   The offer.
	 * @param array<string, mixed>             $lesson  The lesson.
	 * @param array<int, array<string, mixed>> $signUps The offer's active sign-ups.
	 * @param DateTimeImmutable                $now     Now.
	 *
	 * @return array<string, mixed>
	 */
	private function lessonState(array $offer, array $lesson, array $signUps, DateTimeImmutable $now): array {
		$window = $this->electives->window(offer: $offer, startsAt: (string)$lesson['startsAt']);
		$taken = count(array_filter($signUps, fn (array $row): bool => $this->electives->lessonKey(row: $row) === $lesson['key']));

		return [
			'key' => $lesson['key'],
			'sessionId' => $lesson['sessionId'],
			'timetableSessionRef' => $lesson['timetableSessionRef'],
			'title' => $lesson['title'],
			'startsAt' => $lesson['startsAt'],
			'endsAt' => $lesson['endsAt'],
			'freePlaces' => max(0, (int)($offer['capacityPerLesson'] ?? 0) - $taken),
			'opensAt' => $window['opensAt']?->format(DateTimeInterface::ATOM),
			'closesAt' => $window['closesAt']?->format(DateTimeInterface::ATOM),
			'windowOpen' => $this->electives->isOpen(window: $window, now: $now),
		];
	}//end lessonState()

	/**
	 * The learner's own active sign-up on a lesson.
	 *
	 * @param array<int, array<string, mixed>> $signUps   The offer's active sign-ups.
	 * @param string                           $key       The lesson key.
	 * @param string                           $learnerId The learner.
	 *
	 * @return string|null The sign-up's uuid.
	 */
	private function mine(array $signUps, string $key, string $learnerId): ?string {
		foreach ($signUps as $row) {
			if ((string)($row['learnerId'] ?? '') === $learnerId && $this->electives->lessonKey(row: $row) === $key) {
				return (string)($row['id'] ?? '');
			}
		}

		return null;
	}//end mine()
}//end class
