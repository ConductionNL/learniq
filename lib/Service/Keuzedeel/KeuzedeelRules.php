<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/specs/enrolment/spec.md#requirement-an-mbo-student-chooses-a-keuzedeel-inside-its-window
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Keuzedeel;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Service\Portal\CourseDayLines;

/**
 * Whether a student may choose a keuzedeel, and the page line of her choice.
 *
 * Pure: the choose endpoint, the profile restamp and a test read the same
 * rules. Days are compared in the school's time zone; the window's last day
 * counts in full.
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T5
 */
class KeuzedeelRules {

	/**
	 * The school's time zone.
	 *
	 * @var string
	 */
	private const ZONE = 'Europe/Amsterdam';

	/**
	 * The choice states that hold a place.
	 *
	 * @var list<string>
	 */
	public const OCCUPYING = ['chosen', 'approved', 'placed'];

	/**
	 * Constructor.
	 *
	 * @param CourseDayLines $lines Writes "16 oktober".
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T5
	 */
	public function __construct(
		private readonly CourseDayLines $lines = new CourseDayLines(),
	) {
	}//end __construct()

	/**
	 * Why the student may not choose this offer now, or null when she may.
	 *
	 * @param array<string, mixed>       $offer   The offer.
	 * @param list<array<string, mixed>> $taken   The offer's choices that hold a place (anyone's).
	 * @param list<array<string, mixed>> $mine    The student's own choices.
	 * @param DateTimeImmutable          $now     The moment.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T5
	 */
	public function refusal(array $offer, array $taken, array $mine, DateTimeImmutable $now): ?string {
		if (($offer['lifecycle'] ?? 'offered') !== 'offered') {
			return 'Dit keuzedeel wordt niet meer aangeboden.';
		}

		$today  = $now->setTimezone(new DateTimeZone(self::ZONE))->format('Y-m-d');
		$opens  = $this->day(value: $offer['choiceOpensOn'] ?? null);
		$closes = $this->day(value: $offer['choiceClosesOn'] ?? null);
		if ($opens !== null && $today < $opens->format('Y-m-d')) {
			return 'Je kunt dit keuzedeel kiezen vanaf ' . $this->lines->dayMonth($opens) . '.';
		}

		if ($closes === null || $today > $closes->format('Y-m-d')) {
			return 'De keuze voor dit keuzedeel is gesloten.';
		}

		$offerId = (string) ($offer['id'] ?? ($offer['uuid'] ?? ''));
		foreach (self::occupying(choices: $mine) as $choice) {
			if ((string) ($choice['offerId'] ?? '') === $offerId) {
				return 'Je hebt dit keuzedeel al gekozen.';
			}

			if ((string) ($choice['period'] ?? '') === (string) ($offer['period'] ?? '')) {
				return 'Je hebt voor periode ' . (string) ($offer['period'] ?? '') . ' al een keuzedeel gekozen.';
			}
		}

		if (is_numeric($offer['places'] ?? null) === true && count(self::occupying(choices: $taken)) >= (int) $offer['places']) {
			return 'Dit keuzedeel is vol.';
		}

		return null;
	}//end refusal()

	/**
	 * The page line for one period: "Keuzedeel periode 3: Nog niet gekozen, kan tot 16 oktober".
	 *
	 * Picks the period of the student's open offers that closes first, else
	 * the period of her latest choice. Null when there is nothing to say.
	 *
	 * @param list<array<string, mixed>> $offers The offers open to the student.
	 * @param list<array<string, mixed>> $mine   The student's own choices.
	 * @param DateTimeImmutable          $now    The moment.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T5
	 */
	public function line(array $offers, array $mine, DateTimeImmutable $now): ?string {
		$today   = $now->setTimezone(new DateTimeZone(self::ZONE))->format('Y-m-d');
		$chosen  = [];
		foreach (self::occupying(choices: $mine) as $choice) {
			$chosen[(string) ($choice['period'] ?? '')] = $choice;
		}

		$openPeriod = null;
		$openUntil  = null;
		foreach ($offers as $offer) {
			$closes = $this->day(value: $offer['choiceClosesOn'] ?? null);
			$period = (string) ($offer['period'] ?? '');
			if (($offer['lifecycle'] ?? 'offered') !== 'offered' || $closes === null || $today > $closes->format('Y-m-d')
				|| isset($chosen[$period]) === true
			) {
				continue;
			}

			if ($openUntil === null || $closes < $openUntil) {
				$openPeriod = $period;
				$openUntil  = $closes;
			}
		}

		if ($openPeriod !== null && $openUntil !== null) {
			return 'Keuzedeel periode ' . $openPeriod . ': Nog niet gekozen, kan tot ' . $this->lines->dayMonth($openUntil);
		}

		if ($chosen === []) {
			return null;
		}

		$period = (string) array_key_last($chosen);
		$name   = trim((string) ($chosen[$period]['offerName'] ?? ''));
		if ($name === '') {
			return 'Keuzedeel periode ' . $period . ': gekozen';
		}

		return 'Keuzedeel periode ' . $period . ': ' . $name . ' gekozen';
	}//end line()

	/**
	 * Whether the offer is meant for a student of these programmes and groups.
	 *
	 * @param array<string, mixed> $offer        The offer.
	 * @param list<string>         $programmeIds The student's programmes.
	 * @param list<string>         $cohortIds    The student's groups.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T5
	 */
	public function isFor(array $offer, array $programmeIds, array $cohortIds): bool {
		$programme = $offer['programmeId'] ?? null;
		if (is_string($programme) === true && $programme !== '' && in_array($programme, $programmeIds, true) === false) {
			return false;
		}

		$groups = array_values(array_filter((array) ($offer['cohortIds'] ?? []), 'is_string'));

		return $groups === [] || array_intersect($groups, $cohortIds) !== [];
	}//end isFor()

	/**
	 * The choices that hold a place.
	 *
	 * @param list<array<string, mixed>> $choices The choices.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function occupying(array $choices): array {
		return array_values(
			array_filter(
				$choices,
				static fn (array $choice): bool => in_array($choice['lifecycle'] ?? 'chosen', self::OCCUPYING, true)
			)
		);
	}//end occupying()

	/**
	 * A date field as a day in the school's zone.
	 *
	 * @param mixed $value The stored date.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function day(mixed $value): ?DateTimeImmutable {
		return $this->lines->date($value);
	}//end day()
}//end class
