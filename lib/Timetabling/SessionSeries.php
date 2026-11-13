<?php

/**
 * Learniq Session Series
 *
 * The lessons of one weekly slot: the same group, course, weekday and start
 * time as a given lesson, read as the caller from learniq's own Session rows.
 * Cancellations and substitutions stay learniq Session transitions under D10
 * (sessions-from-planninq: a planninq lesson has no Manage action), so this
 * reads learniq sessions only.
 *
 * @category Timetabling
 * @package  OCA\Learniq\Timetabling
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
 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
 */

declare(strict_types=1);

namespace OCA\Learniq\Timetabling;

use OCA\Learniq\Support\ObjectRow;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Finds the lessons of a weekly slot.
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
 */
class SessionSeries {

	private const REGISTER = 'learniq';
	private const SESSION_SCHEMA = 'session';
	public const TIMEZONE = 'Europe/Amsterdam';
	public const OPEN_STATES = ['scheduled', 'in-progress'];

	/**
	 * Turns one OpenRegister result into a plain array.
	 *
	 * @var ObjectRow
	 */
	private readonly ObjectRow $objectRow;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister objects, as the caller.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
		$this->objectRow = new ObjectRow();
	}//end __construct()

	/**
	 * The lessons of the same weekly slot as this one (same group, course,
	 * weekday and start time), from this lesson up to a date.
	 *
	 * @param string      $sessionId The lesson the dialog was opened on.
	 * @param string|null $until     Last date to include (Y-m-d); default eight weeks on.
	 *
	 * @return array<int, array<string, mixed>>|null The lessons, oldest first, or null when the lesson is not readable.
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
	 */
	public function series(string $sessionId, ?string $until=null): ?array {
		$anchor = $this->loadSession(sessionId: $sessionId);
		if ($anchor === null) {
			return null;
		}

		$slot = $this->slotOf(anchor: $anchor, until: $until);
		if ($slot === null) {
			return [];
		}

		$rows = $this->objectService->findAll(['filters' => self::seriesFilters(anchor: $anchor), 'sort' => ['startsAt' => 'ASC']]);
		$series = $this->slotRows(rows: $rows, slot: $slot);
		usort($series, static fn (array $left, array $right): int => strcmp($left['startsAt'], $right['startsAt']));

		return $series;
	}//end series()

	/**
	 * The anchor's weekly slot: the anchor, its local start, the last day and the time zone; null when its start is unreadable.
	 *
	 * @param array<string,mixed> $anchor The anchor session.
	 * @param string|null         $until  The requested last day.
	 *
	 * @return array{anchor: array<string,mixed>, anchorStart: DateTimeImmutable, untilDate: string, tz: DateTimeZone}|null
	 */
	private function slotOf(array $anchor, ?string $until): ?array {
		$tz = new DateTimeZone(self::TIMEZONE);
		$anchorStart = $this->localTime(value: (string)($anchor['startsAt'] ?? ''), tz: $tz);
		if ($anchorStart === null) {
			return null;
		}

		return ['anchor' => $anchor, 'anchorStart' => $anchorStart, 'untilDate' => self::untilDate(anchorStart: $anchorStart, until: $until), 'tz' => $tz];
	}//end slotOf()

	/**
	 * The last day of the series: the requested Y-m-d day, else eight weeks after the anchor.
	 *
	 * @param DateTimeImmutable $anchorStart The anchor's local start.
	 * @param string|null       $until       The requested last day.
	 *
	 * @return string
	 */
	private static function untilDate(DateTimeImmutable $anchorStart, ?string $until): string {
		if ($until !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) === 1) {
			return $until;
		}

		return $anchorStart->modify('+8 weeks')->format('Y-m-d');
	}//end untilDate()

	/**
	 * The filters for the anchor's cohort, and its course when it has one.
	 *
	 * @param array<string,mixed> $anchor The anchor session.
	 *
	 * @return array<string,string>
	 */
	private static function seriesFilters(array $anchor): array {
		$filters = [
			'register' => self::REGISTER,
			'schema' => self::SESSION_SCHEMA,
			'cohortId' => (string)($anchor['cohortId'] ?? ''),
		];
		if (($anchor['courseId'] ?? null) !== null && $anchor['courseId'] !== '') {
			$filters['courseId'] = (string)$anchor['courseId'];
		}

		return $filters;
	}//end seriesFilters()

	/**
	 * The rows that share the anchor's weekly slot, as series entries.
	 *
	 * @param iterable<mixed>     $rows The cohort's sessions.
	 * @param array<string,mixed> $slot The anchor, its local start, the last day and the time zone.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function slotRows(iterable $rows, array $slot): array {
		$series = [];
		foreach ($rows as $row) {
			$data = $this->toArray(row: $row);
			if ($this->sameSlot(slot: $slot, candidate: $data) === true) {
				$series[] = self::seriesEntry(data: $data);
			}
		}

		return $series;
	}//end slotRows()

	/**
	 * One series entry.
	 *
	 * @param array<string,mixed> $data The session.
	 *
	 * @return array{id: string, title: string, startsAt: string, endsAt: string, lifecycle: string, changeable: bool}
	 */
	private static function seriesEntry(array $data): array {
		return [
			'id' => (string)($data['id'] ?? ''),
			'title' => (string)($data['title'] ?? ''),
			'startsAt' => (string)($data['startsAt'] ?? ''),
			'endsAt' => (string)($data['endsAt'] ?? ''),
			'lifecycle' => (string)($data['lifecycle'] ?? 'scheduled'),
			'changeable' => in_array((string)($data['lifecycle'] ?? 'scheduled'), self::OPEN_STATES, true),
		];
	}//end seriesEntry()

	/**
	 * Whether a candidate lesson is in the anchor's weekly slot, not before it
	 * and not after the until date.
	 *
	 * @param array<string, mixed> $slot      The lesson the dialog opened on, its local start, the last date (Y-m-d) and the school's time zone.
	 * @param array<string, mixed> $candidate Another lesson of the group.
	 *
	 * @return bool
	 */
	private function sameSlot(array $slot, array $candidate): bool {
		$anchor = $slot['anchor'];
		$anchorStart = $slot['anchorStart'];
		if ((string)($candidate['cohortId'] ?? '') !== (string)($anchor['cohortId'] ?? '')
			|| (string)($candidate['courseId'] ?? '') !== (string)($anchor['courseId'] ?? '')
		) {
			return false;
		}

		$start = $this->localTime(value: (string)($candidate['startsAt'] ?? ''), tz: $slot['tz']);
		if ($start === null || $start < $anchorStart) {
			return false;
		}

		return $start->format('N H:i') === $anchorStart->format('N H:i') && $start->format('Y-m-d') <= $slot['untilDate'];
	}//end sameSlot()

	/**
	 * Parse a date-time into the school's time zone.
	 *
	 * @param string       $value An ISO 8601 date-time.
	 * @param DateTimeZone $tz    The school's time zone.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
	 */
	public function localTime(string $value, DateTimeZone $tz): ?DateTimeImmutable {
		if ($value === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->setTimezone($tz);
		} catch (Throwable $exception) {
			unset($exception);
			return null;
		}
	}//end localTime()

	/**
	 * Load one lesson as the caller.
	 *
	 * @param string $sessionId The lesson's uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
	 */
	public function loadSession(string $sessionId): ?array {
		try {
			$row = $this->objectService->find(id: $sessionId, register: self::REGISTER, schema: self::SESSION_SCHEMA);
		} catch (Throwable $exception) {
			unset($exception);
			return null;
		}

		if ($row === null) {
			return null;
		}

		$data = $this->toArray(row: $row);
		if (($data['id'] ?? '') === '') {
			$data['id'] = $sessionId;
		}

		return $data;
	}//end loadSession()

	/**
	 * An OpenRegister row as an array.
	 *
	 * @param mixed $row An entity or an array.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
	 */
	public function toArray(mixed $row): array {
		return $this->objectRow->asArray(object: $row);
	}//end toArray()
}//end class
