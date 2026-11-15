<?php

/**
 * Learniq Today State
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A pupil's state today, in one word the list and the page header can show:
 * in class, reported ill, at an appointment, reported absent for another
 * reason, late, absent, or not yet registered. Worked out from the day's
 * attendance marks and the absence report that covers the day; it holds no
 * data of its own.
 *
 * An absence report wins over the marks, because it says why. Without one,
 * the latest marked lesson of the day says where the pupil is now, and a
 * late arrival earlier in the day still reads as late while the pupil is in.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Attendance
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupil-list-shows-what-the-school-kind-acts-on-and-its-saved-views-count
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Attendance;

/**
 * Classifies a pupil's day.
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupil-list-shows-what-the-school-kind-acts-on-and-its-saved-views-count
 */
final class TodayState {

	public const IN_CLASS = 'in-class';
	public const REPORTED_ILL = 'reported-ill';
	public const APPOINTMENT = 'appointment';
	public const REPORTED_ABSENT = 'reported-absent';
	public const LATE = 'late';
	public const ABSENT = 'absent';
	public const NOT_REGISTERED = 'not-registered';

	/**
	 * The state of a pupil today.
	 *
	 * @param array<int, array<string, mixed>> $marks  The pupil's marks of today's lessons, in lesson order.
	 * @param array<string, mixed>|null        $report The absence report that covers today, approved or still open.
	 *
	 * @return string One of the constants.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupil-list-shows-what-the-school-kind-acts-on-and-its-saved-views-count
	 */
	public static function of(array $marks, ?array $report): string {
		if ($report !== null) {
			return match ($report['reasonKind'] ?? null) {
				'illness' => self::REPORTED_ILL,
				'medical-appointment' => self::APPOINTMENT,
				default => self::REPORTED_ABSENT,
			};
		}

		$marks = array_values(array_filter($marks, static fn (array $mark): bool => is_string($mark['status'] ?? null) === true));
		if ($marks === []) {
			return self::NOT_REGISTERED;
		}

		$latest = $marks[count($marks) - 1];
		return match ($latest['status']) {
			'absent-excused', 'absent-unexcused' => self::absenceOf(mark: $latest),
			'left-early' => self::ABSENT,
			default => self::presenceOf(marks: $marks),
		};
	}//end of()

	/**
	 * An absence mark in words: its reason when the teacher noted one.
	 *
	 * @param array<string, mixed> $mark The mark.
	 *
	 * @return string
	 */
	private static function absenceOf(array $mark): string {
		return match ($mark['absenceReasonKind'] ?? null) {
			'illness' => self::REPORTED_ILL,
			'appointment' => self::APPOINTMENT,
			default => self::ABSENT,
		};
	}//end absenceOf()

	/**
	 * A pupil who is in: late when any lesson today was marked late.
	 *
	 * @param array<int, array<string, mixed>> $marks The day's marks.
	 *
	 * @return string
	 */
	private static function presenceOf(array $marks): string {
		foreach ($marks as $mark) {
			if ($mark['status'] === 'late') {
				return self::LATE;
			}
		}

		return self::IN_CLASS;
	}//end presenceOf()
}//end class
