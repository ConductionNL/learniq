<?php

/**
 * Learniq CourseDayStatusService
 *
 * Re-derives a course day's status from what is stored: its bookings and
 * enrolments, its sessions' dates and the provider's decision, and writes it
 * back only when it moved.
 *
 * @category Service
 * @package  OCA\Learniq\Service\CourseDay
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CourseDay;

use OCA\Learniq\Support\SerialisedRow;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Keeps `cohort.dayStatus` in step with the day's facts.
 *
 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
 */
class CourseDayStatusService {

	/**
	 * The register.
	 *
	 * @var string
	 */
	private const REGISTER = 'learniq';

	/**
	 * Enrolment states that hold a place.
	 *
	 * @var list<string>
	 */
	private const PLACED = ['pending', 'active', 'completed'];

	/**
	 * Enrolment states that leave a result or certificate open after the day.
	 *
	 * @var list<string>
	 */
	private const OPEN_RESULT = ['pending', 'active'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService The OpenRegister object service.
	 * @param ITimeFactory  $time          The clock.
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Re-derive one course day and store its status when it moved.
	 *
	 * A cohort that is no course day (no `courseId`) is left alone.
	 *
	 * @param string $cohortId The cohort uuid.
	 *
	 * @return string|null The status, or null when the cohort is not a course day or is gone.
	 *
	 * @throws \Throwable When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public function restamp(string $cohortId): ?string {
		$cohort = $this->one(schema: 'cohort', id: $cohortId);
		if ($cohort === null || (string)($cohort['courseId'] ?? '') === '') {
			return null;
		}

		$enrolments = $this->many(schema: 'enrolment', filters: ['cohortId' => $cohortId]);
		$status     = CourseDayStatus::derive(
			participants: self::participants(bookings: $this->many(schema: 'course-booking', filters: ['cohortId' => $cohortId]), enrolments: $enrolments),
			capacity: self::intOrNull(value: $cohort['capacity'] ?? null),
			minimum: self::intOrNull(value: $cohort['minParticipants'] ?? null),
			decision: self::decision(value: $cohort['goDecision'] ?? null),
			dayDate: self::lastDay(sessions: $this->many(schema: 'session', filters: ['cohortId' => $cohortId])),
			today: $this->time->getDateTime()->format('Y-m-d'),
			openResults: self::hasOpenResults(enrolments: $enrolments)
		);

		if (($cohort['dayStatus'] ?? null) !== $status) {
			$this->objectService->saveObject(
				object: array_merge($cohort, ['dayStatus' => $status]),
				register: self::REGISTER,
				schema: 'cohort',
				uuid: $cohortId,
				_rbac: false,
				_multitenancy: false
			);
		}

		return $status;
	}//end restamp()

	/**
	 * The places taken: each enrolment once, and each open booking for its
	 * places or for its enrolments, whichever is more.
	 *
	 * @param list<array<string, mixed>> $bookings   The day's company bookings.
	 * @param list<array<string, mixed>> $enrolments The day's enrolments.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
	 */
	public static function participants(array $bookings, array $enrolments): int {
		$byBooking = [];
		$loose     = 0;
		foreach ($enrolments as $enrolment) {
			if (in_array((string)($enrolment['lifecycle'] ?? 'pending'), self::PLACED, true) === false) {
				continue;
			}

			$booking = (string)($enrolment['bookingRef'] ?? '');
			if ($booking === '') {
				$loose++;
				continue;
			}

			$byBooking[$booking] = (($byBooking[$booking] ?? 0) + 1);
		}

		$total = $loose;
		$seen  = [];
		foreach ($bookings as $booking) {
			$id = (string)($booking['id'] ?? '');
			$seen[$id] = true;
			if ((string)($booking['lifecycle'] ?? 'received') === 'cancelled') {
				continue;
			}

			$total += max((int)($booking['participantCount'] ?? 0), ($byBooking[$id] ?? 0));
		}

		// Enrolments on a booking that is not on this day still hold their places here.
		foreach ($byBooking as $id => $count) {
			if (isset($seen[$id]) === false) {
				$total += $count;
			}
		}

		return $total;
	}//end participants()

	/**
	 * The day's last date from its sessions, or null when it has none.
	 *
	 * @param list<array<string, mixed>> $sessions The day's sessions.
	 *
	 * @return string|null Y-m-d
	 */
	private static function lastDay(array $sessions): ?string {
		$last = null;
		foreach ($sessions as $session) {
			$date = substr((string)($session['startsAt'] ?? ''), 0, 10);
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && ($last === null || $date > $last)) {
				$last = $date;
			}
		}

		return $last;
	}//end lastDay()

	/**
	 * Whether any participant's result or certificate is still open.
	 *
	 * @param list<array<string, mixed>> $enrolments The day's enrolments.
	 *
	 * @return bool
	 */
	private static function hasOpenResults(array $enrolments): bool {
		foreach ($enrolments as $enrolment) {
			if (in_array((string)($enrolment['lifecycle'] ?? 'pending'), self::OPEN_RESULT, true) === true) {
				return true;
			}
		}

		return false;
	}//end hasOpenResults()

	/**
	 * A stored decision, or null when undecided.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null
	 */
	private static function decision(mixed $value): ?string {
		if ($value === 'go' || $value === 'no-go') {
			return $value;
		}

		return null;
	}//end decision()

	/**
	 * A stored count, or null when there is none.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return int|null
	 */
	private static function intOrNull(mixed $value): ?int {
		if (is_int($value) === true || (is_string($value) === true && ctype_digit($value) === true)) {
			return (int)$value;
		}

		return null;
	}//end intOrNull()

	/**
	 * The row with this uuid, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function one(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$objects = $this->objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => $schema], 'ids' => [$id], 'limit' => 1],
			_rbac: false,
			_multitenancy: false
		);
		foreach ($objects as $object) {
			$row = (new SerialisedRow())->asArray(object: $object);
			if (($row['id'] ?? ($row['uuid'] ?? null)) === $id) {
				return $row;
			}
		}

		return null;
	}//end one()

	/**
	 * The rows of a schema whose fields equal the filters.
	 *
	 * @param string                $schema  The schema slug.
	 * @param array<string, string> $filters Field equals value.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function many(string $schema, array $filters): array {
		$objects = $this->objectService->findAll(
			config: ['filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters), 'limit' => 500],
			_rbac: false,
			_multitenancy: false
		);
		$rows = [];
		foreach ($objects as $object) {
			$row = (new SerialisedRow())->asArray(object: $object);
			// Never trust the store to have applied a filter on a field.
			foreach ($filters as $field => $value) {
				if (($row[$field] ?? null) !== $value) {
					continue 2;
				}
			}

			$rows[] = $row;
		}

		return $rows;
	}//end many()
}//end class
