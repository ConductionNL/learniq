<?php

/**
 * Learniq Roll Call Marks
 *
 * The rules of one mark in the roll-call, without any I/O: what the page
 * starts a pupil on, what a mark must carry, and the AttendanceRecord it
 * becomes.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Attendance
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
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Attendance;

/**
 * Validates marks and turns them into AttendanceRecord bodies.
 *
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
 */
class RollCallMarks {

	public const STATUSES = ['present', 'late', 'absent-excused', 'absent-unexcused', 'left-early'];
	public const ABSENCES = ['absent-excused', 'absent-unexcused'];
	public const REASON_KINDS = ['illness', 'appointment', 'other'];
	public const MAX_LATE_MINUTES = 600;
	public const MAX_REASON_LENGTH = 500;

	/**
	 * ExcuseRequest.reasonKind values that are not `other` in the roll-call.
	 */
	private const REPORT_REASONS = ['illness' => 'illness', 'medical-appointment' => 'appointment'];

	/**
	 * The row of one pupil as the page shows it: the saved mark, else an
	 * approved report, else present.
	 *
	 * @param string                    $learnerId The pupil.
	 * @param string                    $name      Display name.
	 * @param array<string, mixed>|null $profile   The pupil's profile.
	 * @param array<string, mixed>|null $record    The saved record.
	 * @param array<string, mixed>|null $report    A report covering the day.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-an-approved-absence-report-fills-in-the-register
	 */
	public function pupilRow(string $learnerId, string $name, ?array $profile, ?array $record, ?array $report): array {
		$row = [
			'learnerId' => $learnerId,
			'learnerRef' => ($profile === null ? null : RollCallReader::idOf(row: $profile)),
			'name' => $name,
			'status' => 'present',
			'lateMinutes' => null,
			'absenceReasonKind' => null,
			'reason' => '',
			'recordId' => null,
			'markedVia' => null,
			'saved' => false,
			'report' => $this->reportSummary(report: $report),
		];

		if ($record !== null) {
			return array_merge(
				$row,
				[
					'status' => (string)($record['status'] ?? 'present'),
					'lateMinutes' => $this->intOrNull(value: ($record['lateMinutes'] ?? null)),
					'absenceReasonKind' => $this->kindOrNull(value: ($record['absenceReasonKind'] ?? null)),
					'reason' => (string)($record['reason'] ?? ''),
					'recordId' => RollCallReader::idOf(row: $record),
					'markedVia' => (string)($record['markedVia'] ?? 'teacher'),
					'saved' => true,
				]
			);
		}

		if ($report !== null && ($report['lifecycle'] ?? null) === 'approved') {
			$row['status'] = 'absent-excused';
			$row['absenceReasonKind'] = self::reasonFromReport(reasonKind: (string)($report['reasonKind'] ?? ''));
			$row['reason'] = (string)($report['reason'] ?? '');
		}

		return $row;
	}//end pupilRow()

	/**
	 * The roll-call reason for an ExcuseRequest.reasonKind.
	 *
	 * @param string $reasonKind ExcuseRequest.reasonKind.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-an-approved-absence-report-fills-in-the-register
	 */
	public static function reasonFromReport(string $reasonKind): string {
		return (self::REPORT_REASONS[$reasonKind] ?? 'other');
	}//end reasonFromReport()

	/**
	 * Why a mark cannot be saved, or null when it can. The keys name a message.
	 *
	 * @param array<string, mixed> $mark       The mark as posted.
	 * @param array<int, string>   $learnerIds The pupils of the group.
	 *
	 * @return string|null One of: not-in-group, status, late-minutes, reason-kind, reason.
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#scenario-a-late-mark-without-minutes-is-refused
	 */
	public function problemWith(array $mark, array $learnerIds): ?string {
		$status = ($mark['status'] ?? null);
		$problem = null;
		if (in_array(($mark['learnerId'] ?? null), $learnerIds, true) === false) {
			$problem = 'not-in-group';
		} else if (in_array($status, self::STATUSES, true) === false) {
			$problem = 'status';
		} else if ($status === 'late' && $this->validMinutes(value: ($mark['lateMinutes'] ?? null)) === false) {
			$problem = 'late-minutes';
		} else if ($status === 'absent-excused' && in_array(($mark['absenceReasonKind'] ?? null), self::REASON_KINDS, true) === false) {
			$problem = 'reason-kind';
		} else if ($status === 'absent-unexcused' && ($mark['absenceReasonKind'] ?? null) !== null && in_array($mark['absenceReasonKind'], self::REASON_KINDS, true) === false) {
			$problem = 'reason-kind';
		} else if (isset($mark['reason']) === true && (is_string($mark['reason']) === false || mb_strlen($mark['reason']) > self::MAX_REASON_LENGTH)) {
			$problem = 'reason';
		}

		return $problem;
	}//end problemWith()

	/**
	 * The fields a mark sets on a record, normalised.
	 *
	 * @param array<string, mixed> $mark A valid mark.
	 *
	 * @return array{status: string, lateMinutes: int|null, absenceReasonKind: string|null, reason: string|null}
	 */
	public function normalise(array $mark): array {
		$status = (string)$mark['status'];
		$reason = trim((string)($mark['reason'] ?? ''));

		return [
			'status' => $status,
			'lateMinutes' => ($status === 'late' ? (int)$mark['lateMinutes'] : null),
			'absenceReasonKind' => (in_array($status, self::ABSENCES, true) === true ? $this->kindOrNull(value: ($mark['absenceReasonKind'] ?? null)) : null),
			'reason' => ($reason === '' ? null : $reason),
		];
	}//end normalise()

	/**
	 * Whether a saved record already holds this mark.
	 *
	 * @param array<string, mixed>                                                                       $record The saved record.
	 * @param array{status: string, lateMinutes: int|null, absenceReasonKind: string|null, reason: string|null} $mark   A normalised mark.
	 *
	 * @return bool
	 */
	public function unchanged(array $record, array $mark): bool {
		$reason = trim((string)($record['reason'] ?? ''));

		return ($record['status'] ?? null) === $mark['status']
			&& $this->intOrNull(value: ($record['lateMinutes'] ?? null)) === $mark['lateMinutes']
			&& $this->kindOrNull(value: ($record['absenceReasonKind'] ?? null)) === $mark['absenceReasonKind']
			&& ($reason === '' ? null : $reason) === $mark['reason'];
	}//end unchanged()

	/**
	 * Minutes attended for a mark in a lesson of a length.
	 *
	 * @param array{status: string, lateMinutes: int|null} $mark     A normalised mark.
	 * @param int|null                                       $minutes Lesson length.
	 * @param array<string, mixed>|null                      $record  The saved record.
	 *
	 * @return int|null
	 */
	public function minutesAttended(array $mark, ?int $minutes, ?array $record): ?int {
		if (in_array($mark['status'], self::ABSENCES, true) === true) {
			return null;
		}

		if ($mark['status'] === 'left-early') {
			return $this->intOrNull(value: ($record['minutesAttended'] ?? null));
		}

		if ($minutes === null) {
			return null;
		}

		return max(0, ($minutes - (int)($mark['lateMinutes'] ?? 0)));
	}//end minutesAttended()

	/**
	 * What the page shows of a report.
	 *
	 * @param array<string, mixed>|null $report The report.
	 *
	 * @return array<string, mixed>|null
	 */
	private function reportSummary(?array $report): ?array {
		if ($report === null) {
			return null;
		}

		return [
			'id' => RollCallReader::idOf(row: $report),
			'lifecycle' => (string)($report['lifecycle'] ?? ''),
			'reasonKind' => (string)($report['reasonKind'] ?? ''),
			'reason' => (string)($report['reason'] ?? ''),
			'dateFrom' => substr((string)($report['dateFrom'] ?? ''), 0, 10),
			'dateTo' => substr((string)($report['dateTo'] ?? ''), 0, 10),
		];
	}//end reportSummary()

	/**
	 * Whether a value is a whole number of late minutes.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function validMinutes(mixed $value): bool {
		return is_int($value) === true && $value >= 1 && $value <= self::MAX_LATE_MINUTES;
	}//end validMinutes()

	/**
	 * An integer, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int|null
	 */
	private function intOrNull(mixed $value): ?int {
		if (is_int($value) === true || (is_string($value) === true && ctype_digit($value) === true)) {
			return (int)$value;
		}

		return null;
	}//end intOrNull()

	/**
	 * A known reason for absence, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private function kindOrNull(mixed $value): ?string {
		if (in_array($value, self::REASON_KINDS, true) === true) {
			return (string)$value;
		}

		return null;
	}//end kindOrNull()
}//end class
