<?php

/**
 * Learniq ExamRegistrationDates
 *
 * The Dutch day and moment wording of the exam registration list: when the
 * employer was told, when the registration closes, and whether it is due.
 *
 * @category Service
 * @package  OCA\Learniq\Service\ExamRegistration
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
 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\ExamRegistration;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Words the days and moments of an exam registration list.
 *
 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
 */
class ExamRegistrationDates {

	/**
	 * The Dutch weekdays, Monday first.
	 *
	 * @var list<string>
	 */
	private const WEEKDAYS = ['maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag', 'zondag'];

	/**
	 * "De werkgever kreeg vanochtend bericht.", or null when the employer was not told.
	 *
	 * @param string|null $notifiedAt When the employer was told (ISO 8601).
	 * @param string      $now        Now (ISO 8601).
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
	 */
	public function notice(?string $notifiedAt, string $now): ?string {
		$told  = $this->moment(value: $notifiedAt);
		$today = $this->moment(value: $now);
		if ($told === null || $today === null) {
			return null;
		}

		$days = (int)$told->setTime(0, 0)->diff($today->setTime(0, 0))->format('%r%a');
		$when = 'op ' . $this->longDate(day: $told);
		if ($days === 0) {
			$when = $this->partOfDay(moment: $told);
		} else if ($days === 1) {
			$when = 'gisteren';
		}

		return 'De werkgever kreeg ' . $when . ' bericht.';
	}//end notice()

	/**
	 * "De aanmelding voor het examen van donderdag sluit woensdag om 12.00 uur."
	 *
	 * @param string|null $examDate The exam date (Y-m-d).
	 * @param string|null $closesAt The moment the list closes (ISO 8601).
	 *
	 * @return string|null Null when the list has no closing moment.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
	 */
	public function closingSentence(?string $examDate, ?string $closesAt): ?string {
		$closes = $this->moment(value: $closesAt);
		if ($closes === null) {
			return null;
		}

		$exam = 'het examen';
		$day  = $this->moment(value: $examDate);
		if ($day !== null) {
			$exam .= ' van ' . $this->weekday(day: $day);
		}

		return 'De aanmelding voor ' . $exam . ' sluit ' . $this->weekday(day: $closes) . ' om ' . $closes->format('H.i') . ' uur.';
	}//end closingSentence()

	/**
	 * Whether the list's closing moment has come.
	 *
	 * @param string|null $closesAt The moment (ISO 8601).
	 * @param string      $now      Now (ISO 8601).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	public function isDue(?string $closesAt, string $now): bool {
		$closes = $this->moment(value: $closesAt);
		$moment = $this->moment(value: $now);

		return $closes !== null && $moment !== null && $closes <= $moment;
	}//end isDue()

	/**
	 * "vanochtend", "vanmiddag" or "vanavond".
	 *
	 * @param DateTimeImmutable $moment The moment.
	 *
	 * @return string
	 */
	private function partOfDay(DateTimeImmutable $moment): string {
		$hour = (int)$moment->format('G');
		if ($hour < 12) {
			return 'vanochtend';
		}

		if ($hour < 18) {
			return 'vanmiddag';
		}

		return 'vanavond';
	}//end partOfDay()

	/**
	 * The Dutch weekday.
	 *
	 * @param DateTimeImmutable $day The day.
	 *
	 * @return string
	 */
	private function weekday(DateTimeImmutable $day): string {
		return self::WEEKDAYS[((int)$day->format('N') - 1)];
	}//end weekday()

	/**
	 * "8 oktober".
	 *
	 * @param DateTimeImmutable $day The day.
	 *
	 * @return string
	 */
	private function longDate(DateTimeImmutable $day): string {
		$months = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];

		return $day->format('j') . ' ' . $months[((int)$day->format('n') - 1)];
	}//end longDate()

	/**
	 * A moment read in the list's zone, or null.
	 *
	 * @param string|null $value An ISO 8601 date or moment.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function moment(?string $value): ?DateTimeImmutable {
		if ($value === null || trim($value) === '') {
			return null;
		}

		try {
			return (
				new DateTimeImmutable($value, new DateTimeZone(ExamRegistrationLines::ZONE))
			)->setTimezone(new DateTimeZone(ExamRegistrationLines::ZONE));
		} catch (Exception) {
			return null;
		}
	}//end moment()
}//end class
