<?php

/**
 * Learniq CohortSyncLine
 *
 * The line under the groups list's title (board LqGroepen): when the groups
 * the caller may open were last brought in step with the student
 * administration, from which source and with how many changes, as in
 * "Bijgewerkt uit Magister om 07.00, 3 wijzigingen". Read from the synced
 * cohorts themselves (`externalSource`, `syncedAt`, `syncChanges`); a
 * failed run's reason stays with integriq's job.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Cohort
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/tasks.md#3-screen
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Cohort;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Words the last sync of a set of cohorts.
 *
 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/tasks.md#3-screen
 */
class CohortSyncLine {

	/**
	 * Source names as the school reads them, by `externalSource`.
	 */
	private const SOURCE_NAMES = ['magister' => 'Magister', 'somtoday' => 'Somtoday', 'oneroster' => 'OneRoster', 'eduarte' => 'Eduarte'];

	/**
	 * The last sync run among the cohorts: its source, its moment and the
	 * changes of the cohorts it wrote (those stamped in the same minute).
	 *
	 * @param list<array<string, mixed>> $cohorts The cohorts.
	 *
	 * @return array{source: string, at: DateTimeImmutable, changes: int}|null The run, or null when no cohort is synced.
	 *
	 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/tasks.md#3-screen
	 */
	public function lastRun(array $cohorts): ?array {
		$synced = [];
		foreach ($cohorts as $cohort) {
			$at     = $this->momentOf(value: ($cohort['syncedAt'] ?? null));
			$source = trim((string)($cohort['externalSource'] ?? ''));
			if ($at !== null && $source !== '') {
				$synced[] = ['source' => $source, 'at' => $at, 'minute' => $at->format('YmdHi'), 'changes' => max(0, (int)($cohort['syncChanges'] ?? 0))];
			}
		}

		if ($synced === []) {
			return null;
		}

		usort($synced, static fn (array $one, array $other): int => $other['at'] <=> $one['at']);
		$last    = $synced[0];
		$changes = 0;
		foreach ($synced as $row) {
			if ($row['minute'] === $last['minute'] && $row['source'] === $last['source']) {
				$changes += $row['changes'];
			}
		}

		return ['source' => $last['source'], 'at' => $last['at'], 'changes' => $changes];
	}//end lastRun()

	/**
	 * The source's name as the school reads it.
	 *
	 * @param string $source The `externalSource` value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/tasks.md#3-screen
	 */
	public function sourceName(string $source): string {
		return (self::SOURCE_NAMES[strtolower($source)] ?? ucfirst($source));
	}//end sourceName()

	/**
	 * The run's time of day in the school's zone, as "07.00".
	 *
	 * @param DateTimeImmutable $at   The moment.
	 * @param DateTimeZone      $zone The school's zone.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/tasks.md#3-screen
	 */
	public function timeOf(DateTimeImmutable $at, DateTimeZone $zone): string {
		return $at->setTimezone($zone)->format('H.i');
	}//end timeOf()

	/**
	 * A moment off a row, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function momentOf(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable) {
			return null;
		}
	}//end momentOf()
}//end class
