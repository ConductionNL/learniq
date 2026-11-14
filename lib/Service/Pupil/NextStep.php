<?php

/**
 * Learniq Next Step
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The one next step the pupil's page puts in its "Wat nu?" card: the first
 * that applies of a booked conference with the guardians in the coming 14
 * days, a planned placement visit or assessment, and an open attendance
 * signal. It answers what the card needs to say (the kind, the moment, who
 * chose and confirmed a conference time, at most three preparatory lines and
 * one action) as data; the page words it. It holds no data of its own.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Pupil
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupils-page-opens-with-a-header-one-next-step-and-the-people-around-the-pupil
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Pupil;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;

/**
 * Picks the pupil's next step.
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupils-page-opens-with-a-header-one-next-step-and-the-people-around-the-pupil
 */
final class NextStep {

	public const CONFERENCE = 'conference';
	public const PLACEMENT_VISIT = 'placement-visit';
	public const SIGNAL = 'signal';

	/**
	 * How far ahead a booked conference counts, in days.
	 *
	 * @var int
	 */
	public const CONFERENCE_HORIZON_DAYS = 14;

	/**
	 * The most preparatory lines the card shows.
	 *
	 * @var int
	 */
	public const MAX_LINES = 3;

	/**
	 * Conference slot states in which the time is taken.
	 *
	 * @var list<string>
	 */
	private const BOOKED_SLOT = ['booked', 'acknowledged', 'confirmed'];

	/**
	 * Attendance flag states that still ask for action.
	 *
	 * @var list<string>
	 */
	private const OPEN_FLAG = ['open', 'in-handling'];

	/**
	 * The first applicable next step, or null when nothing applies.
	 *
	 * @param list<array<string, mixed>> $slots  The pupil's ConferenceSlots, each with the `guardianName` of who booked it when known.
	 * @param list<array<string, mixed>> $visits The pupil's BpvVisits.
	 * @param list<array<string, mixed>> $flags  The pupil's AttendanceFlags.
	 * @param DateTimeImmutable          $now    The moment the page is opened.
	 *
	 * @return array<string, mixed>|null kind, at, sourceId, chosenBy, confirmed, detail, lines (at most three) and action.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
	 */
	public function first(array $slots, array $visits, array $flags, DateTimeImmutable $now): ?array {
		return ($this->conference(slots: $slots, now: $now)
			?? $this->placementVisit(visits: $visits, now: $now)
			?? $this->signal(flags: $flags));

	}//end first()

	/**
	 * The earliest booked conference from now up to the horizon.
	 *
	 * @param list<array<string, mixed>> $slots The ConferenceSlots.
	 * @param DateTimeImmutable          $now   Now.
	 *
	 * @return array<string, mixed>|null
	 */
	private function conference(array $slots, DateTimeImmutable $now): ?array {
		$horizon = $now->modify('+' . self::CONFERENCE_HORIZON_DAYS . ' days');
		$slot = $this->earliest(
			rows: array_filter($slots, static fn (array $slot): bool => in_array((string)($slot['lifecycle'] ?? ''), self::BOOKED_SLOT, true)),
			field: 'startsAt',
			from: $now,
			until: $horizon
		);
		if ($slot === null) {
			return null;
		}

		$confirmed = trim((string)($slot['acknowledgedAt'] ?? '')) !== '' || (string)($slot['lifecycle'] ?? '') === 'acknowledged';

		return $this->step(
			kind: self::CONFERENCE,
			row: $slot,
			when: (string)$slot['startsAt'],
			fields: [
				'chosenBy' => trim((string)($slot['guardianName'] ?? ($slot['bookedByName'] ?? ''))),
				'confirmed' => $confirmed,
				'detail' => trim((string)($slot['location'] ?? '')),
				'lines' => ['review-results', 'review-attendance', 'review-notes'],
				'action' => 'record-conference',
			]
		);

	}//end conference()

	/**
	 * The earliest planned placement visit or assessment from now on.
	 *
	 * @param list<array<string, mixed>> $visits The BpvVisits.
	 * @param DateTimeImmutable          $now    Now.
	 *
	 * @return array<string, mixed>|null
	 */
	private function placementVisit(array $visits, DateTimeImmutable $now): ?array {
		$visit = $this->earliest(
			rows: array_filter($visits, static fn (array $visit): bool => (string)($visit['lifecycle'] ?? '') === 'planned'),
			field: 'plannedAt',
			from: $now,
			until: null
		);
		if ($visit === null) {
			return null;
		}

		return $this->step(
			kind: self::PLACEMENT_VISIT,
			row: $visit,
			when: (string)$visit['plannedAt'],
			fields: [
				'detail' => (string)($visit['visitKind'] ?? ''),
				'lines' => ['review-bpv-hours', 'review-werkprocessen', 'agree-with-trainer'],
				'action' => 'prepare-visit',
			]
		);

	}//end placementVisit()

	/**
	 * The oldest open attendance signal.
	 *
	 * @param list<array<string, mixed>> $flags The AttendanceFlags.
	 *
	 * @return array<string, mixed>|null
	 */
	private function signal(array $flags): ?array {
		$open = array_values(
			array_filter($flags, static fn (array $flag): bool => in_array((string)($flag['lifecycle'] ?? ''), self::OPEN_FLAG, true))
		);
		if ($open === []) {
			return null;
		}

		usort(
			$open,
			static fn (array $a, array $b): int => [(string)($a['windowEnd'] ?? ''), (string)($a['id'] ?? '')]
				<=> [(string)($b['windowEnd'] ?? ''), (string)($b['id'] ?? '')]
		);
		$flag = $open[0];
		$lines = ['contact-guardians'];
		if (($flag['reportOverdue'] ?? false) === true || (string)($flag['flagKind'] ?? '') !== 'signal-verzuim') {
			$lines[] = 'report-to-attendance-officer';
		}

		return $this->step(
			kind: self::SIGNAL,
			row: $flag,
			when: null,
			fields: [
				'detail' => (string)($flag['flagKind'] ?? ''),
				'lines' => $lines,
				'action' => 'open-signal',
			]
		);

	}//end signal()

	/**
	 * The step array with its defaults.
	 *
	 * @param string               $kind   The kind.
	 * @param array<string, mixed> $row    The record it comes from.
	 * @param string|null          $when   The moment, or null.
	 * @param array<string, mixed> $fields The kind's own fields.
	 *
	 * @return array<string, mixed> kind, at, sourceId, chosenBy, confirmed, detail, lines and action.
	 */
	private function step(string $kind, array $row, ?string $when, array $fields): array {
		return [
			'kind' => $kind,
			'at' => $when,
			'sourceId' => (string)($row['id'] ?? ''),
			'chosenBy' => (string)($fields['chosenBy'] ?? ''),
			'confirmed' => (bool)($fields['confirmed'] ?? false),
			'detail' => (string)($fields['detail'] ?? ''),
			'lines' => array_slice(array_values($fields['lines']), 0, self::MAX_LINES),
			'action' => (string)$fields['action'],
		];

	}//end step()

	/**
	 * The row with the earliest readable moment in [from, until].
	 *
	 * @param array<int, array<string, mixed>> $rows  The rows.
	 * @param string                           $field The moment field.
	 * @param DateTimeImmutable                $from  The earliest moment that counts.
	 * @param DateTimeImmutable|null           $until The latest, or null for no end.
	 *
	 * @return array<string, mixed>|null
	 */
	private function earliest(array $rows, string $field, DateTimeImmutable $from, ?DateTimeImmutable $until): ?array {
		$best = null;
		$bestAt = null;
		foreach ($rows as $row) {
			$when = $this->moment(value: ($row[$field] ?? null));
			if ($when === null || $when < $from || ($until !== null && $when > $until)) {
				continue;
			}

			if ($bestAt === null || $when < $bestAt) {
				$best = $row;
				$bestAt = $when;
			}
		}

		return $best;

	}//end earliest()

	/**
	 * A stored moment, or null when it is missing or unreadable.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function moment(mixed $value): ?DateTimeImmutable {
		if ($value instanceof DateTimeInterface) {
			return new DateTimeImmutable($value->format(DateTimeInterface::ATOM));
		}

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Exception $e) {
			return null;
		}

	}//end moment()
}//end class
