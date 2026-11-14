<?php

/**
 * Learniq Elective Offer Copies
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The readable copies an optional-lesson offer keeps of its next lesson, so
 * that a pupil's portal can show it without reading the lessons or the
 * sign-ups themselves: when and where the next lesson is that she can still
 * sign up for, and how many places it has left, as a number and as a line
 * ("Nog 6 plekken").
 *
 * The next lesson is the first lesson of the offer that has not started and
 * whose sign-up window has not closed. An offer with no such lesson keeps
 * empty copies.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\ElectiveServiceInterface;
use OCP\L10N\IFactory;
use Throwable;

/**
 * Computes an offer's next-lesson copies.
 *
 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
 */
class ElectiveOfferCopies {

	/**
	 * The copy fields, in the order they are written.
	 */
	public const FIELDS = [
		'nextLessonStartsAt',
		'nextLessonEndsAt',
		'nextLessonLocation',
		'placesLeft',
		'placesLeftLine',
	];

	/**
	 * Constructor.
	 *
	 * @param ElectiveServiceInterface $electives   Lessons, windows and sign-ups.
	 * @param IFactory                 $l10nFactory Translations for the line.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ElectiveServiceInterface $electives,
		private readonly IFactory $l10nFactory,
	) {
	}//end __construct()

	/**
	 * The copies for an offer, every field present, null when unknown.
	 *
	 * @param array<string, mixed> $offer The offer, with its `id` when it has one.
	 * @param DateTimeImmutable    $now   Now.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
	 */
	public function copies(array $offer, DateTimeImmutable $now): array {
		$lesson = $this->nextLesson(offer: $offer, now: $now);
		if ($lesson === null) {
			return array_fill_keys(self::FIELDS, null);
		}

		$offerId = (string)($offer['id'] ?? '');
		$taken = 0;
		if ($offerId !== '') {
			$taken = count($this->electives->activeSignUps(offerId: $offerId, key: (string)$lesson['key']));
		}

		$left = max(0, (int)($offer['capacityPerLesson'] ?? 0) - $taken);
		$location = trim((string)($lesson['location'] ?? ''));

		return [
			'nextLessonStartsAt' => (string)$lesson['startsAt'],
			'nextLessonEndsAt' => $this->orNull(value: (string)($lesson['endsAt'] ?? '')),
			'nextLessonLocation' => $this->orNull(value: $location),
			'placesLeft' => $left,
			'placesLeftLine' => $this->placesLeftLine(left: $left),
		];
	}//end copies()

	/**
	 * Whether the stored copies of an offer differ from fresh ones.
	 *
	 * @param array<string, mixed> $offer  The stored offer.
	 * @param array<string, mixed> $copies Fresh copies.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
	 */
	public function differ(array $offer, array $copies): bool {
		foreach (self::FIELDS as $field) {
			if (($offer[$field] ?? null) !== ($copies[$field] ?? null)) {
				return true;
			}
		}

		return false;
	}//end differ()

	/**
	 * The places left in words: "No places left" at none, else "%n places left".
	 *
	 * @param int $left The places left.
	 *
	 * @return string
	 */
	private function placesLeftLine(int $left): string {
		$l10n = $this->l10nFactory->get(AppIdentity::APP_ID);
		if ($left === 0) {
			return $l10n->t('No places left');
		}

		return $l10n->n('%n place left', '%n places left', $left);
	}//end placesLeftLine()

	/**
	 * The first lesson that has not started and whose window has not closed.
	 *
	 * @param array<string, mixed> $offer The offer.
	 * @param DateTimeImmutable    $now   Now.
	 *
	 * @return array<string, mixed>|null
	 */
	private function nextLesson(array $offer, DateTimeImmutable $now): ?array {
		$next = null;
		$nextStart = null;
		foreach ($this->electives->lessons(offer: $offer) as $lesson) {
			$start = $this->moment(value: (string)($lesson['startsAt'] ?? ''));
			if ($start === null || $start <= $now) {
				continue;
			}

			$window = $this->electives->window(offer: $offer, startsAt: (string)$lesson['startsAt']);
			if ($window['closesAt'] !== null && $window['closesAt'] < $now) {
				continue;
			}

			if ($nextStart === null || $start < $nextStart) {
				$next = $lesson;
				$nextStart = $start;
			}
		}

		return $next;
	}//end nextLesson()

	/**
	 * A moment, or null when the value is not one.
	 *
	 * @param string $value An ISO 8601 date-time.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function moment(string $value): ?DateTimeImmutable {
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $exception) {
			return null;
		}
	}//end moment()

	/**
	 * A string, or null when it is empty.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null
	 */
	private function orNull(string $value): ?string {
		if ($value === '') {
			return null;
		}

		return $value;
	}//end orNull()
}//end class
