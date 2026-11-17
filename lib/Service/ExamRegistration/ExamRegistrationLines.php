<?php

/**
 * Learniq ExamRegistrationLines
 *
 * The rules of an exam registration list, without any reads: which fields an
 * examining body requires, which of them a participant still lacks, the line
 * the administration's Today reads, when the employer was told, and the CSV
 * of a closed list in the body's column order.
 *
 * @category Service
 * @package  OCA\Learniq\Service\ExamRegistration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\ExamRegistration;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use OCA\Learniq\Service\CsvCellSanitizer;

/**
 * Pure rules of an exam registration list.
 *
 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
 */
final class ExamRegistrationLines {

	/**
	 * The fields a body may require, with their export column and their name in a sentence.
	 *
	 * @var array<string, array{column: string, noun: string}>
	 */
	public const FIELDS = [
		'fullName' => ['column' => 'Naam', 'noun' => 'naam'],
		'birthDate' => ['column' => 'Geboortedatum', 'noun' => 'geboortedatum'],
		'placeOfBirth' => ['column' => 'Geboorteplaats', 'noun' => 'geboorteplaats'],
	];

	/**
	 * What a body requires when the list names nothing.
	 *
	 * @var list<string>
	 */
	public const DEFAULT_REQUIRED = ['fullName', 'birthDate', 'placeOfBirth'];

	/**
	 * The zone the list's moments are read in.
	 *
	 * @var string
	 */
	public const ZONE = 'Europe/Amsterdam';

	/**
	 * The Dutch weekdays, Monday first.
	 *
	 * @var list<string>
	 */
	private const WEEKDAYS = ['maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag', 'zondag'];

	/**
	 * The fields a list requires: the known ones it names, in its order, else the default.
	 *
	 * @param mixed $required The list's `requiredFields`.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	public static function required(mixed $required): array {
		$fields = [];
		foreach ((array)$required as $field) {
			$field = (string)$field;
			if (isset(self::FIELDS[$field]) === true && in_array($field, $fields, true) === false) {
				$fields[] = $field;
			}
		}

		if ($fields === []) {
			return self::DEFAULT_REQUIRED;
		}

		return $fields;
	}//end required()

	/**
	 * The value of a required field on a learner profile, '' when it is empty.
	 *
	 * @param array<string, mixed> $profile The learner profile.
	 * @param string               $field   The field.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	public static function value(array $profile, string $field): string {
		if ($field === 'fullName') {
			return self::fullName(profile: $profile);
		}

		return trim((string)($profile[$field] ?? ''));
	}//end value()

	/**
	 * The given and family name as one line; the profile's readable copy when it has no parts.
	 *
	 * @param array<string, mixed> $profile The learner profile.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	public static function fullName(array $profile): string {
		$name = trim(trim((string)($profile['givenName'] ?? '')) . ' ' . trim((string)($profile['familyName'] ?? '')));
		if ($name !== '' && trim((string)($profile['givenName'] ?? '')) !== '' && trim((string)($profile['familyName'] ?? '')) !== '') {
			return $name;
		}

		// A name needs both parts for the body; a one-part name counts as missing.
		return '';
	}//end fullName()

	/**
	 * One participant's line: who, which company, which required fields are
	 * missing, and the task the employer's portal gives for it. The employer
	 * has a task when the enrolment carries one (`openTask`, written by the
	 * company booking's projection, for example "Vul de geboortedatum van
	 * Youssef El Amrani in"). The result, the moment the employer was told and
	 * the certificate of an earlier line for the same enrolment are kept; the
	 * moment the employer was told is set the first time the task is seen and
	 * cleared once the line is complete.
	 *
	 * @param array<string, mixed>      $enrolment    The enrolment.
	 * @param array<string, mixed>      $profile      The learner profile ([] when unknown).
	 * @param array<string, mixed>      $organisation The employer ([] when none).
	 * @param array<string, mixed>      $state        `required` (the required fields), `previous` (the
	 *                                                line the list held for this enrolment, or null) and
	 *                                                `now` (ISO 8601, the moment a new task is given).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	public static function line(array $enrolment, array $profile, array $organisation, array $state): array {
		$required = array_values(array_map('strval', (array)($state['required'] ?? [])));
		$previous = null;
		if (is_array($state['previous'] ?? null) === true) {
			$previous = $state['previous'];
		}

		$now = (string)($state['now'] ?? '');
		$missing = [];
		foreach ($required as $field) {
			if (self::value(profile: $profile, field: $field) === '') {
				$missing[] = $field;
			}
		}

		$name = self::fullName(profile: $profile);
		if ($name === '') {
			$name = trim((string)($profile['fullName'] ?? ($enrolment['learnerName'] ?? '')));
		}

		$task       = self::textOrNull(value: ($enrolment['openTask'] ?? null));
		$notifiedAt = null;
		if ($missing !== [] && $task !== null) {
			$notifiedAt = ($previous['employerNotifiedAt'] ?? $now);
		}

		if ($missing === []) {
			$task = null;
		}

		return [
			'enrolmentId' => (string)($enrolment['id'] ?? ''),
			'learnerRef' => self::textOrNull(value: ($profile['id'] ?? ($enrolment['learnerRef'] ?? null))),
			'learnerName' => self::textOrNull(value: $name),
			'organisationRef' => self::textOrNull(value: ($organisation['id'] ?? ($enrolment['organisationRef'] ?? null))),
			'organisationName' => self::textOrNull(value: ($organisation['name'] ?? null)),
			'contactName' => self::textOrNull(value: ($organisation['contactName'] ?? null)),
			'missing' => $missing,
			'complete' => $missing === [],
			'employerTask' => $task,
			'employerNotifiedAt' => $notifiedAt,
			'result' => ($previous['result'] ?? null),
			'resultBy' => ($previous['resultBy'] ?? null),
			'credentialId' => ($previous['credentialId'] ?? null),
		];
	}//end line()

	/**
	 * "10 van de 11 deelnemers zijn compleet."
	 *
	 * @param list<array<string, mixed>> $lines The lines.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
	 */
	public static function summary(array $lines): string {
		$total    = count($lines);
		$complete = self::completeCount(lines: $lines);
		if ($total === 1) {
			if ($complete === 1) {
				return 'De deelnemer is compleet.';
			}

			return 'De deelnemer is nog niet compleet.';
		}

		if ($complete === $total) {
			return 'Alle ' . $total . ' deelnemers zijn compleet.';
		}

		$verb = 'zijn';
		if ($complete === 1) {
			$verb = 'is';
		}

		return $complete . ' van de ' . $total . ' deelnemers ' . $verb . ' compleet.';
	}//end summary()

	/**
	 * How many lines are complete.
	 *
	 * @param list<array<string, mixed>> $lines The lines.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
	 */
	public static function completeCount(array $lines): int {
		return count(array_filter($lines, static fn (array $line): bool => ($line['complete'] ?? false) === true));
	}//end completeCount()

	/**
	 * "Van Youssef El Amrani (Jansen Installatietechniek) ontbreekt de geboortedatum."
	 *
	 * @param array<string, mixed> $line A line with something missing.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
	 */
	public static function missingSentence(array $line): string {
		$nouns = array_map(static fn (string $field): string => self::FIELDS[$field]['noun'], (array)($line['missing'] ?? []));
		$who   = trim((string)($line['learnerName'] ?? ''));
		if ($who === '') {
			$who = 'een deelnemer';
		}

		$company = trim((string)($line['organisationName'] ?? ''));
		if ($company !== '') {
			$who .= ' (' . $company . ')';
		}

		$what = 'de ' . array_pop($nouns);
		if ($nouns !== []) {
			$what = 'de ' . implode(', de ', $nouns) . ' en ' . $what;
		}

		return 'Van ' . $who . ' ontbreekt ' . $what . '.';
	}//end missingSentence()

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
	public static function notice(?string $notifiedAt, string $now): ?string {
		$told  = self::moment(value: $notifiedAt);
		$today = self::moment(value: $now);
		if ($told === null || $today === null) {
			return null;
		}

		$days = (int)$told->setTime(0, 0)->diff($today->setTime(0, 0))->format('%r%a');
		$when = 'op ' . self::longDate(day: $told);
		if ($days === 0) {
			$when = self::partOfDay(moment: $told);
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
	public static function closingSentence(?string $examDate, ?string $closesAt): ?string {
		$closes = self::moment(value: $closesAt);
		if ($closes === null) {
			return null;
		}

		$exam = 'het examen';
		$day  = self::moment(value: $examDate);
		if ($day !== null) {
			$exam .= ' van ' . self::weekday(day: $day);
		}

		return 'De aanmelding voor ' . $exam . ' sluit ' . self::weekday(day: $closes) . ' om ' . $closes->format('H.i') . ' uur.';
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
	public static function isDue(?string $closesAt, string $now): bool {
		$closes = self::moment(value: $closesAt);
		$moment = self::moment(value: $now);

		return $closes !== null && $moment !== null && $closes <= $moment;
	}//end isDue()

	/**
	 * The CSV of a closed list: a header of the body's columns, then one row
	 * per participant with the values read from the profiles. Cells are
	 * guarded against spreadsheet formulas.
	 *
	 * @param list<string>                        $required The required fields, in column order.
	 * @param list<array<string, mixed>>          $lines    The list's lines.
	 * @param array<string, array<string, mixed>> $profiles The profiles by learner ref.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	public static function csv(array $required, array $lines, array $profiles): string {
		$sanitizer = new CsvCellSanitizer();
		$rows      = [array_map(static fn (string $field): string => self::FIELDS[$field]['column'], $required)];
		foreach ($lines as $line) {
			$profile = ($profiles[(string)($line['learnerRef'] ?? '')] ?? []);
			$row     = [];
			foreach ($required as $field) {
				$value = self::value(profile: $profile, field: $field);
				if ($field === 'fullName' && $value === '') {
					$value = (string)($line['learnerName'] ?? '');
				}

				$row[] = $sanitizer->sanitize(value: $value);
			}

			$rows[] = $row;
		}

		return implode("\r\n", array_map(static fn (array $row): string => implode(';', array_map(self::cell(...), $row)), $rows)) . "\r\n";
	}//end csv()

	/**
	 * One CSV cell, quoted when it holds a separator, a quote or a line break.
	 *
	 * @param string $value The value.
	 *
	 * @return string
	 */
	private static function cell(string $value): string {
		if (strpbrk($value, ";\"\r\n") === false) {
			return $value;
		}

		return '"' . str_replace('"', '""', $value) . '"';
	}//end cell()

	/**
	 * "vanochtend", "vanmiddag" or "vanavond".
	 *
	 * @param DateTimeImmutable $moment The moment.
	 *
	 * @return string
	 */
	private static function partOfDay(DateTimeImmutable $moment): string {
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
	private static function weekday(DateTimeImmutable $day): string {
		return self::WEEKDAYS[((int)$day->format('N') - 1)];
	}//end weekday()

	/**
	 * "8 oktober".
	 *
	 * @param DateTimeImmutable $day The day.
	 *
	 * @return string
	 */
	private static function longDate(DateTimeImmutable $day): string {
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
	private static function moment(?string $value): ?DateTimeImmutable {
		if ($value === null || trim($value) === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value, new DateTimeZone(self::ZONE)))->setTimezone(new DateTimeZone(self::ZONE));
		} catch (Exception) {
			return null;
		}
	}//end moment()

	/**
	 * A trimmed text, or null when empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private static function textOrNull(mixed $value): ?string {
		$text = '';
		if (is_scalar($value) === true) {
			$text = trim((string)$value);
		}

		if ($text === '') {
			return null;
		}

		return $text;
	}//end textOrNull()
}//end class
