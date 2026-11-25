<?php

/**
 * Learniq Attendance Facts
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the First today rules about attendance count: pupils nearing the
 * limit, a mentor's pupils with unauthorised absence nobody took up, and
 * pupils over the limit not reported yet. Every method takes rows already
 * read, so each rule and its empty case is a unit test.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Today
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Today;

/**
 * The facts of the First today rules about attendances.
 *
 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
 */
final class AttendanceFacts {
	use RowValues;

	/**
	 * How far back an unauthorised absence still asks the mentor, in days.
	 *
	 * @var int
	 */
	public const ABSENCE_WINDOW_DAYS = 14;

	/**
	 * Attendance flag kinds that the school reports to the attendance officer.
	 *
	 * @var list<string>
	 */
	private const REPORTABLE_FLAG = ['langdurig-relatief-verzuim', 'thuiszitter'];

	/**
	 * Attendance flag states that are not reported yet.
	 *
	 * @var list<string>
	 */
	private const UNREPORTED_FLAG = ['open', 'in-handling'];

	/**
	 * Pupils of the caller's groups whose absence is nearing the attendance limit.
	 *
	 * @param list<array<string, mixed>> $summaries Attendance summaries.
	 * @param string                     $uid       The caller.
	 *
	 * @return array<string, mixed> count.
	 *
	 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 */
	public function nearingLimit(array $summaries, string $uid): array {
		$learners = [];
		foreach ($summaries as $summary) {
			if (($summary['thresholdNearing'] ?? false) !== true) {
				continue;
			}

			if (in_array($uid, (array)($summary['teacherIds'] ?? []), true) === false && ($summary['mentorId'] ?? null) !== $uid) {
				continue;
			}

			$learners[(string)($summary['learnerId'] ?? ($summary['learnerRef'] ?? self::idOf(row: $summary)))] = true;
		}

		return ['count' => count($learners)];
	}//end nearingLimit()

	/**
	 * The caller's mentor pupils with unauthorised absence in the last two weeks that nobody took up.
	 *
	 * @param list<array<string, mixed>> $flags   Attendance flags.
	 * @param TodayContext               $context The mentor and now.
	 *
	 * @return array<string, mixed> count (pupils), learnerId (the first).
	 *
	 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/specs/dashboard/spec.md#requirement-a-mentor-switches-today-to-her-mentor-class
	 */
	public function unauthorisedAbsence(array $flags, TodayContext $context): array {
		$uid = $context->uid();
		$since = $context->now->modify('-' . self::ABSENCE_WINDOW_DAYS . ' days')->format('Y-m-d');
		$learners = [];
		foreach ($flags as $flag) {
			if (($flag['mentorId'] ?? null) !== $uid || ($flag['lifecycle'] ?? '') !== 'open') {
				continue;
			}

			if (substr((string)($flag['windowEnd'] ?? ''), 0, 10) < $since) {
				continue;
			}

			$learners[] = (string)($flag['learnerId'] ?? '');
		}

		$learners = array_values(array_unique($learners));

		return ['count' => count($learners), 'learnerId' => ($learners[0] ?? '')];
	}//end unauthorisedAbsence()

	/**
	 * Pupils over the attendance limit whose flag is not reported yet.
	 *
	 * @param list<array<string, mixed>> $flags Attendance flags.
	 *
	 * @return array<string, mixed> count (pupils).
	 *
	 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 */
	public function overLimitNotReported(array $flags): array {
		$learners = [];
		foreach ($flags as $flag) {
			if (in_array(($flag['flagKind'] ?? ''), self::REPORTABLE_FLAG, true) === true
				&& in_array(($flag['lifecycle'] ?? ''), self::UNREPORTED_FLAG, true) === true
			) {
				$learners[(string)($flag['learnerId'] ?? '')] = true;
			}
		}

		return ['count' => count($learners)];
	}//end overLimitNotReported()
}//end class
