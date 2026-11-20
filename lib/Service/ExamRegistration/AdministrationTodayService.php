<?php

/**
 * Learniq AdministrationTodayService
 *
 * The administration's Today of a training provider: the exam registration
 * that asks for attention first, the course days whose certificates are to be
 * issued, the certificates expiring before a date with their renewal booking,
 * and the coming exams.
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
 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\ExamRegistration;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Kernel\Contracts\AdministrationTodayServiceInterface;
use OCA\Learniq\Kernel\Contracts\ExamRegistrationServiceInterface;
use OCA\Learniq\Service\CourseDay\CourseDayException;
use OCA\Learniq\Service\CourseDay\CourseDayRows;
use OCA\Learniq\Service\CourseDay\CourseDayStatus;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * What the administration's Today shows.
 *
 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
 */
class AdministrationTodayService implements AdministrationTodayServiceInterface {
	use CourseDayRows;

	/**
	 * Constructor.
	 *
	 * @param ObjectService                    $objectService The OpenRegister object service.
	 * @param ITimeFactory                     $time          The clock.
	 * @param ExamRegistrationServiceInterface $lists         The exam registration lists.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ITimeFactory $time,
		private readonly ExamRegistrationServiceInterface $lists,
	) {
	}//end __construct()

	/**
	 * The administration's Today.
	 *
	 * @return array<string, mixed> `today`, `first` (the list asking for attention first, or null),
	 *                              `registrations` (open lists by closing moment), `toIssue` (course
	 *                              days in rounding off), `expiring` {before, rows} and `comingExams`.
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
	 */
	public function today(): array {
		$today = $this->todayDate();
		$open  = $this->lists->openLists();
		usort($open, static fn (array $one, array $two): int => strcmp((string)($one['closesAt'] ?? ''), (string)($two['closesAt'] ?? '')));
		$before = (string)((int)substr($today, 0, 4) + 1) . '-01-01';

		return [
			'today' => $today,
			'first' => self::firstToday(lists: $open),
			'registrations' => $open,
			'toIssue' => $this->toIssue(),
			'expiring' => ['before' => $before, 'rows' => $this->expiring(before: $before)],
			'comingExams' => $this->comingExams(today: $today),
		];
	}//end today()

	/**
	 * The open list that comes first on Today: the soonest closing one with
	 * an incomplete participant, else the soonest closing one; null when none is open.
	 *
	 * @param list<array<string, mixed>> $lists Open lists, soonest closing first.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
	 */
	public static function firstToday(array $lists): ?array {
		foreach ($lists as $list) {
			if (($list['incomplete'] ?? []) !== []) {
				return $list;
			}
		}

		return ($lists[0] ?? null);
	}//end firstToday()

	/**
	 * The issued certificates expiring before a date, soonest first, with the renewal booking if any.
	 *
	 * @param string $before The date (Y-m-d); a certificate expiring on it is not included.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws CourseDayException When the date cannot be read.
	 * @throws \Throwable         When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/tasks.md
	 */
	public function expiring(string $before): array {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $before) !== 1) {
			throw new CourseDayException(message: 'Give the date as yyyy-mm-dd.', status: 400);
		}

		$today = $this->todayDate();
		$rows  = [];
		foreach ($this->many(schema: 'credential', filters: ['lifecycle' => 'issued']) as $credential) {
			$expires = substr((string)($credential['expiresAt'] ?? ''), 0, 10);
			if ($expires === '' || $expires >= $before || $expires < $today) {
				continue;
			}

			$rows[] = $this->expiringRow(credential: $credential, expires: $expires);
		}

		usort($rows, static fn (array $one, array $two): int => strcmp((string)$one['expiresOn'], (string)$two['expiresOn']));

		return $rows;
	}//end expiring()

	/**
	 * One expiring certificate as the list reads it.
	 *
	 * @param array<string, mixed> $credential The certificate.
	 * @param string               $expires    Its expiry date (Y-m-d).
	 *
	 * @return array<string, mixed>
	 */
	private function expiringRow(array $credential, string $expires): array {
		$renewal = null;
		$ref     = (string)($credential['renewalEnrolmentId'] ?? '');
		if ($ref !== '') {
			$enrolment = $this->one(schema: 'enrolment', id: $ref);
			if ($enrolment !== null && ($enrolment['lifecycle'] ?? '') !== 'withdrawn') {
				$renewal = [
					'enrolmentId' => $ref,
					'firstDay' => ($enrolment['firstDay'] ?? null),
					'dayLabel' => ($enrolment['dayLabel'] ?? null),
				];
			}
		}

		$line = ($credential['renewalLine'] ?? null);
		if ($line === null && $renewal !== null && ($renewal['dayLabel'] ?? null) !== null) {
			$line = 'Herhaling op ' . $renewal['dayLabel'];
		}

		return [
			'credentialId' => (string)($credential['id'] ?? ($credential['uuid'] ?? '')),
			'learnerName' => ($credential['learnerName'] ?? null),
			'organisationRef' => ($credential['organisationRef'] ?? null),
			'courseName' => ($credential['courseName'] ?? null),
			'expiresOn' => $expires,
			'renewal' => $renewal,
			'renewalLine' => ($line ?? 'Geen herhaling geboekt'),
		];
	}//end expiringRow()

	/**
	 * The course days in rounding off: their certificates are to be issued.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function toIssue(): array {
		$days = [];
		foreach ($this->many(schema: 'cohort', filters: ['dayStatus' => CourseDayStatus::ROUNDING_OFF]) as $cohort) {
			$days[] = [
				'cohortId' => (string)($cohort['id'] ?? ($cohort['uuid'] ?? '')),
				'name' => ($cohort['name'] ?? null),
				'dayDate' => ($cohort['dayDate'] ?? null),
			];
		}

		usort($days, static fn (array $one, array $two): int => strcmp((string)$one['dayDate'], (string)$two['dayDate']));

		return $days;
	}//end toIssue()

	/**
	 * The exams still to come, soonest first, whatever their list's state.
	 *
	 * @param string $today Today (Y-m-d).
	 *
	 * @return list<array<string, mixed>>
	 */
	private function comingExams(string $today): array {
		$exams = [];
		foreach ($this->many(schema: ExamRegistrationService::SCHEMA, filters: []) as $list) {
			$date = (string)($list['examDate'] ?? '');
			if ($date === '' || $date < $today) {
				continue;
			}

			$exams[] = [
				'registrationId' => (string)($list['id'] ?? ($list['uuid'] ?? '')),
				'examDate' => $date,
				'courseName' => ($list['courseName'] ?? null),
				'bodyName' => ($list['bodyName'] ?? null),
				'participantCount' => (int)($list['participantCount'] ?? 0),
				'lifecycle' => ($list['lifecycle'] ?? 'open'),
			];
		}

		usort($exams, static fn (array $one, array $two): int => strcmp($one['examDate'], $two['examDate']));

		return $exams;
	}//end comingExams()

	/**
	 * Today, in the zone the lists are read in.
	 *
	 * @return string
	 */
	private function todayDate(): string {
		return (new DateTimeImmutable('@' . $this->time->getDateTime()->getTimestamp()))->setTimezone(new DateTimeZone(ExamRegistrationLines::ZONE))->format('Y-m-d');
	}//end todayDate()
}//end class
