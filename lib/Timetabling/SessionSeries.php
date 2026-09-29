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
 * @spec openspec/changes/timetabling-bulk-change-weeks/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
 */

declare(strict_types=1);

namespace OCA\Learniq\Timetabling;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Finds the lessons of a weekly slot.
 *
 * @spec openspec/changes/timetabling-bulk-change-weeks/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
 */
class SessionSeries {

	private const REGISTER = 'learniq';
	private const SESSION_SCHEMA = 'session';
	public const TIMEZONE = 'Europe/Amsterdam';
	public const OPEN_STATES = ['scheduled', 'in-progress'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister objects, as the caller.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
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
	 * @spec openspec/changes/timetabling-bulk-change-weeks/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
	 */
	public function series(string $sessionId, ?string $until=null): ?array {
		$anchor = $this->loadSession(sessionId: $sessionId);
		if ($anchor === null) {
			return null;
		}

		$tz = new DateTimeZone(self::TIMEZONE);
		$anchorStart = $this->localTime(value: (string)($anchor['startsAt'] ?? ''), tz: $tz);
		if ($anchorStart === null) {
			return [];
		}

		$untilDate = $anchorStart->modify('+8 weeks')->format('Y-m-d');
		if ($until !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) === 1) {
			$untilDate = $until;
		}

		$filters = [
			'register' => self::REGISTER,
			'schema' => self::SESSION_SCHEMA,
			'cohortId' => (string)($anchor['cohortId'] ?? ''),
		];
		if (($anchor['courseId'] ?? null) !== null && $anchor['courseId'] !== '') {
			$filters['courseId'] = (string)$anchor['courseId'];
		}

		$rows = $this->objectService->findAll(['filters' => $filters, 'sort' => ['startsAt' => 'ASC']]);

		$series = [];
		foreach ($rows as $row) {
			$data = $this->toArray(row: $row);
			if ($this->sameSlot(anchor: $anchor, anchorStart: $anchorStart, candidate: $data, untilDate: $untilDate, tz: $tz) === false) {
				continue;
			}

			$series[] = [
				'id' => (string)($data['id'] ?? ''),
				'title' => (string)($data['title'] ?? ''),
				'startsAt' => (string)($data['startsAt'] ?? ''),
				'endsAt' => (string)($data['endsAt'] ?? ''),
				'lifecycle' => (string)($data['lifecycle'] ?? 'scheduled'),
				'changeable' => in_array((string)($data['lifecycle'] ?? 'scheduled'), self::OPEN_STATES, true),
			];
		}

		usort($series, static fn (array $left, array $right): int => strcmp($left['startsAt'], $right['startsAt']));

		return $series;
	}//end series()

	/**
	 * Whether a candidate lesson is in the anchor's weekly slot, not before it
	 * and not after the until date.
	 *
	 * @param array<string, mixed> $anchor      The lesson the dialog opened on.
	 * @param DateTimeImmutable    $anchorStart Its local start.
	 * @param array<string, mixed> $candidate   Another lesson of the group.
	 * @param string               $untilDate   Last date (Y-m-d).
	 * @param DateTimeZone         $tz          The school's time zone.
	 *
	 * @return bool
	 */
	private function sameSlot(array $anchor, DateTimeImmutable $anchorStart, array $candidate, string $untilDate, DateTimeZone $tz): bool {
		if ((string)($candidate['cohortId'] ?? '') !== (string)($anchor['cohortId'] ?? '')
			|| (string)($candidate['courseId'] ?? '') !== (string)($anchor['courseId'] ?? '')
		) {
			return false;
		}

		$start = $this->localTime(value: (string)($candidate['startsAt'] ?? ''), tz: $tz);
		if ($start === null || $start < $anchorStart) {
			return false;
		}

		return $start->format('N H:i') === $anchorStart->format('N H:i') && $start->format('Y-m-d') <= $untilDate;
	}//end sameSlot()

	/**
	 * Parse a date-time into the school's time zone.
	 *
	 * @param string       $value An ISO 8601 date-time.
	 * @param DateTimeZone $tz    The school's time zone.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/changes/timetabling-bulk-change-weeks/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
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
	 * @spec openspec/changes/timetabling-bulk-change-weeks/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
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
	 * @spec openspec/changes/timetabling-bulk-change-weeks/specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks
	 */
	public function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end toArray()
}//end class
