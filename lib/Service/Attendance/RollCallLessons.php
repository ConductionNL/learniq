<?php

/**
 * Learniq Roll Call Lessons
 *
 * The lesson a roll-call writes against: the asked one among the day's
 * lessons, else the first, and a new one when the group has no lesson that
 * day. A new lesson is timed like the group's most recent earlier lesson on
 * the same weekday, else like its most recent lesson, else 08:30 to 14:30.
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
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-day-without-a-lesson-gets-one-when-the-register-is-saved
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Attendance;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Picks or creates the lesson of a roll-call.
 *
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-day-without-a-lesson-gets-one-when-the-register-is-saved
 */
class RollCallLessons {
	use RollCallRows;

	private const REGISTER = 'learniq';
	private const DEFAULT_START = '08:30';
	private const DEFAULT_END = '14:30';

	/**
	 * How far back a new lesson looks for its model.
	 */
	private const MODEL_DAYS = 60;

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister object access.
	 * @param IL10N           $l10n          The lesson title's date.
	 * @param LoggerInterface $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The asked lesson among the day's lessons, else the first, else none.
	 *
	 * @param array<int, array<string, mixed>> $sessions  The day's lessons.
	 * @param string|null                      $sessionId The asked lesson.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-day-without-a-lesson-gets-one-when-the-register-is-saved
	 */
	public function chosen(array $sessions, ?string $sessionId): ?array {
		foreach ($sessions as $session) {
			if ($this->idOf(row: $session) === $sessionId) {
				return $session;
			}
		}

		return ($sessions[0] ?? null);
	}//end chosen()

	/**
	 * A lesson's length in minutes, or null.
	 *
	 * @param array<string, mixed> $session The lesson.
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
	 */
	public function lengthOf(array $session): ?int {
		$start = $this->moment(value: ($session['startsAt'] ?? null));
		$end = $this->moment(value: ($session['endsAt'] ?? null));
		if ($start === null || $end === null || $end <= $start) {
			return null;
		}

		return intdiv(($end->getTimestamp() - $start->getTimestamp()), 60);
	}//end lengthOf()

	/**
	 * Create the day's lesson for a group.
	 *
	 * @param array<string, mixed> $cohort The group.
	 * @param string               $date   The day.
	 * @param DateTimeZone         $zone   The caller's time zone.
	 *
	 * @return array<string, mixed> The created lesson.
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-day-without-a-lesson-gets-one-when-the-register-is-saved
	 */
	public function create(array $cohort, string $date, DateTimeZone $zone): array {
		$cohortId = $this->idOf(row: $cohort);
		$day = new DateTimeImmutable($date . 'T12:00:00', $zone);
		[$start, $end] = $this->timesFor(cohortId: $cohortId, day: $day, zone: $zone);

		$session = [
			'cohortId' => $cohortId,
			'title' => (string)($cohort['name'] ?? '') . ', ' . $this->dayName(day: $day),
			'startsAt' => (new DateTimeImmutable($date . 'T' . $start . ':00', $zone))->format(DATE_ATOM),
			'endsAt' => (new DateTimeImmutable($date . 'T' . $end . ':00', $zone))->format(DATE_ATOM),
			'tenant_id' => (string)($cohort['tenant_id'] ?? ''),
		];
		if (is_string($cohort['courseId'] ?? null) === true && $cohort['courseId'] !== '') {
			$session['courseId'] = $cohort['courseId'];
		}

		$created = $this->objectService->saveObject(object: $session, register: self::REGISTER, schema: 'session', _rbac: false);
		$row = (array)$created->jsonSerialize();
		$row['id'] = $this->idOf(row: $row);
		if ($row['id'] === '') {
			$row['id'] = (string)$created->getUuid();
		}

		$this->logger->info('[RollCallLessons] Created the lesson {title} for a roll-call.', ['title' => $session['title']]);

		return array_merge($session, $row);
	}//end create()

	/**
	 * The day in the user's language, for instance "donderdag 17 september 2026".
	 *
	 * IL10N::l() on Nextcloud 34 takes a mutable \DateTime; a DateTimeImmutable
	 * falls through to `(int)$data` and is formatted as 1 January 1970.
	 *
	 * @param DateTimeImmutable $day Noon of the day, in the caller's zone.
	 *
	 * @return string
	 */
	private function dayName(DateTimeImmutable $day): string {
		return (string)$this->l10n->l('date', new DateTime($day->format(DATE_ATOM), $day->getTimezone()), ['width' => 'full']);
	}//end dayName()

	/**
	 * The start and end time (`H:i`) of a new lesson.
	 *
	 * @param string            $cohortId The group.
	 * @param DateTimeImmutable $day      Noon of the day, in the caller's zone.
	 * @param DateTimeZone      $zone     The caller's time zone.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function timesFor(string $cohortId, DateTimeImmutable $day, DateTimeZone $zone): array {
		$earlier = $this->lessonsBefore(cohortId: $cohortId, day: $day);
		$model = ($earlier[0] ?? null);
		foreach ($earlier as $lesson) {
			$moment = $this->moment(value: $lesson['startsAt']);
			if ($moment !== null && $moment->setTimezone($zone)->format('N') === $day->format('N')) {
				$model = $lesson;
				break;
			}
		}

		$start = $this->moment(value: ($model['startsAt'] ?? null));
		$end = $this->moment(value: ($model['endsAt'] ?? null));
		if ($start === null || $end === null) {
			return [self::DEFAULT_START, self::DEFAULT_END];
		}

		return [$start->setTimezone($zone)->format('H:i'), $end->setTimezone($zone)->format('H:i')];
	}//end timesFor()

	/**
	 * The group's lessons in the weeks before a day, newest first.
	 *
	 * @param string            $cohortId The group.
	 * @param DateTimeImmutable $day      Noon of the day, in the caller's zone.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function lessonsBefore(string $cohortId, DateTimeImmutable $day): array {
		$midnight = $day->setTime(0, 0);
		$rows = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => 'session',
					'cohortId' => $cohortId,
					'startsAt' => ['lt' => $midnight->format(DATE_ATOM), 'gte' => $midnight->modify('-' . self::MODEL_DAYS . ' days')->format(DATE_ATOM)],
				],
				'sort' => ['startsAt' => 'DESC'],
				'limit' => 20,
			],
			_rbac: false,
			_multitenancy: false
		);

		$lessons = [];
		foreach ($rows as $row) {
			$lesson = $row;
			if (is_array($row) === false) {
				$lesson = (array)$row->jsonSerialize();
			}

			$start = $this->moment(value: ($lesson['startsAt'] ?? null));
			if (($lesson['cohortId'] ?? null) === $cohortId && $start !== null && $start < $midnight) {
				$lessons[] = ['at' => $start->getTimestamp()] + $lesson;
			}
		}

		usort($lessons, static fn (array $left, array $right): int => ($right['at'] <=> $left['at']));

		return $lessons;
	}//end lessonsBefore()
}//end class
