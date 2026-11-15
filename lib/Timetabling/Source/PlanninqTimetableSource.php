<?php

/**
 * Learniq Planninq Timetable Source
 *
 * Planninq's school timetable as learniq's timetable source (decision D10).
 * Reads through planninq's own typed event,
 * `OCA\Planninq\Event\TimetableSessionsQueryEvent` (contract v1, planninq
 * change `school-timetable-target`), looked up by name so learniq runs
 * without planninq installed (ADR-041). Planninq answers with RBAC on, as the
 * current user.
 *
 * An unanswered event or an answered error RAISES. A timetable that silently
 * reads empty because the other app did not answer is exactly the failure
 * this is written to avoid.
 *
 * @category Timetabling
 * @package  OCA\Learniq\Timetabling\Source
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
 * @spec openspec/specs/timetable-source/spec.md#requirement-the-planninq-source-reads-through-planninqs-query-event-req-002
 */

declare(strict_types=1);

namespace OCA\Learniq\Timetabling\Source;

use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use RuntimeException;

/**
 * Reads timetable sessions from planninq.
 *
 * @spec openspec/specs/timetable-source/spec.md#requirement-the-planninq-source-reads-through-planninqs-query-event-req-002
 */
class PlanninqTimetableSource implements TimetableSource {

	public const NAME = 'planninq';

	public const PLANNINQ_APP = 'planninq';

	public const QUERY_EVENT = 'OCA\\Planninq\\Event\\TimetableSessionsQueryEvent';

	/**
	 * The session's fields, in order. Title, location, onlineMeetingUrl,
	 * lifecycle and source are derived; the others are copied as strings.
	 */
	private const SESSION_FIELDS = [
		'id',
		'title',
		'startsAt',
		'endsAt',
		'location',
		'cohortId',
		'courseId',
		'onlineMeetingUrl',
		'lifecycle',
		'teacherUserId',
		'roomReference',
		'groupReference',
		'subject',
		'teacherReference',
		'externalRef',
		'sourceSystem',
		'source',
	];

	/**
	 * Planninq caps one read at this many lessons.
	 */
	private const LIMIT = 1000;

	/**
	 * The planninq contract version that answers a course query and carries
	 * a lesson's course and online link.
	 */
	public const COURSE_QUERY_CONTRACT = 2;

	/**
	 * Constructor.
	 *
	 * @param IAppManager      $appManager Tells whether planninq is installed.
	 * @param IEventDispatcher $dispatcher Dispatches planninq's query event.
	 * @param string           $eventClass The query event class name (tests only).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IEventDispatcher $dispatcher,
		private readonly string $eventClass = self::QUERY_EVENT,
	) {
	}//end __construct()

	/**
	 * Whether planninq is installed and ships the query event.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/timetable-source/spec.md#requirement-a-resolver-picks-planninq-when-it-is-installed-req-001
	 */
	public function isAvailable(): bool {
		return $this->appManager->isInstalled(self::PLANNINQ_APP) === true && class_exists($this->eventClass) === true;
	}//end isAvailable()

	/**
	 * The source's name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/timetable-source/spec.md#requirement-the-planninq-source-reads-through-planninqs-query-event-req-002
	 */
	public function name(): string {
		return self::NAME;
	}//end name()

	/**
	 * The lessons planninq holds for the given cohorts.
	 *
	 * @param array<int,string> $cohortIds Cohort UUIDs.
	 * @param string|null       $from      ISO 8601 window start, or null.
	 * @param string|null       $to        ISO 8601 window end, or null.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws RuntimeException When planninq is absent, silent or refuses.
	 *
	 * @spec openspec/specs/timetable-source/spec.md#requirement-the-planninq-source-reads-through-planninqs-query-event-req-002
	 */
	public function sessionsForCohorts(array $cohortIds, ?string $from, ?string $to): array {
		$rows = [];
		foreach (array_unique($cohortIds) as $cohortId) {
			if ($cohortId !== '') {
				$rows = array_merge($rows, $this->query(identity: ['cohortId' => $cohortId], window: new TimetableWindow(from: $from, to: $to)));
			}
		}

		return $rows;
	}//end sessionsForCohorts()

	/**
	 * The lessons planninq holds for one teacher account.
	 *
	 * @param string      $userId The teacher's Nextcloud user id.
	 * @param string|null $from   ISO 8601 window start, or null.
	 * @param string|null $to     ISO 8601 window end, or null.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws RuntimeException When planninq is absent, silent or refuses.
	 *
	 * @spec openspec/specs/timetable-source/spec.md#requirement-the-planninq-source-reads-through-planninqs-query-event-req-002
	 */
	public function sessionsForTeacher(string $userId, ?string $from, ?string $to): array {
		if ($userId === '') {
			return [];
		}

		return $this->query(identity: ['teacherUserId' => $userId], window: new TimetableWindow(from: $from, to: $to));
	}//end sessionsForTeacher()

	/**
	 * The lessons of the given courses (electives from a subject choice).
	 * Planninq answers a `courseId` query from contract version 2 on
	 * (DECISIONS row 53, live pass D8; the contract is in
	 * for-ruben/planninq-timetable-course-query-and-lesson-link.md). An older
	 * planninq has no course on a lesson, so nothing is asked and the answer
	 * is empty, as before.
	 *
	 * @param array<int,string> $courseIds Course UUIDs.
	 * @param string|null       $from      ISO 8601 window start, or null.
	 * @param string|null       $to        ISO 8601 window end, or null.
	 *
	 * @return array<int,array<string,mixed>> Sessions in learniq's session shape.
	 *
	 * @throws RuntimeException When planninq is absent, silent or refuses.
	 *
	 * @spec openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#requirement-elective-sessions-in-the-personal-timetable
	 */
	public function sessionsForCourses(array $courseIds, ?string $from, ?string $to): array {
		if ($this->contractVersion() < self::COURSE_QUERY_CONTRACT) {
			return [];
		}

		return $this->courseSessions(courseIds: $courseIds, window: new TimetableWindow(from: $from, to: $to));
	}//end sessionsForCourses()

	/**
	 * The lessons of each distinct, non-empty course, one query per course.
	 *
	 * @param array<int,string> $courseIds Course UUIDs.
	 * @param TimetableWindow   $window    The time window.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws RuntimeException When planninq is absent, silent or refuses.
	 */
	private function courseSessions(array $courseIds, TimetableWindow $window): array {
		$rows = [];
		foreach (array_unique($courseIds) as $courseId) {
			if ($courseId !== '') {
				$rows = array_merge($rows, $this->lessonsOfCourse(courseId: $courseId, window: $window));
			}
		}

		return $rows;
	}//end courseSessions()

	/**
	 * One course's lessons; never lets another course's lesson through.
	 *
	 * @param mixed           $courseId The course UUID.
	 * @param TimetableWindow $window   The time window.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws RuntimeException When planninq is absent, silent or refuses.
	 */
	private function lessonsOfCourse(mixed $courseId, TimetableWindow $window): array {
		$rows = [];
		foreach ($this->query(identity: ['courseId' => $courseId], window: $window) as $session) {
			if ($session['courseId'] === $courseId) {
				$rows[] = $session;
			}
		}

		return $rows;
	}//end lessonsOfCourse()

	/**
	 * The contract version of the planninq event class, 0 when it has none.
	 *
	 * @return int The version.
	 *
	 * @spec openspec/changes/timetabling-student-choice-placement/specs/timetable-student-choice/spec.md#requirement-elective-sessions-in-the-personal-timetable
	 */
	private function contractVersion(): int {
		$constant = $this->eventClass . '::CONTRACT_VERSION';
		if (class_exists($this->eventClass) === false || defined($constant) === false) {
			return 0;
		}

		return (int)constant($constant);
	}//end contractVersion()

	/**
	 * Dispatch one query and map planninq's lessons onto learniq's session shape.
	 *
	 * @param array<string,mixed> $identity The identity criterion.
	 * @param TimetableWindow     $window   The time window.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws RuntimeException When planninq is absent, silent or refuses.
	 */
	private function query(array $identity, TimetableWindow $window): array {
		if (class_exists($this->eventClass) === false) {
			throw new RuntimeException('Planninq is not installed, so its timetable cannot be read.');
		}

		$criteria = array_merge($identity, ['limit' => self::LIMIT], $window->bounds());

		// Named arguments work on a dynamic class name, and they are the
		// contract: a renamed parameter in planninq fails here, loudly.
		$event = new ($this->eventClass)(sourceApp: 'learniq', criteria: $criteria);
		$this->dispatcher->dispatchTyped($event);

		return $this->toSessions(lessons: $this->answerOf(event: $event));
	}//end query()

	/**
	 * The lessons planninq answered on a dispatched query.
	 *
	 * @param object $event The dispatched query event.
	 *
	 * @return array<mixed>
	 *
	 * @throws RuntimeException When planninq did not answer or refused.
	 */
	private function answerOf(object $event): array {
		if ($event->isHandled() === false) {
			throw new RuntimeException('Planninq did not answer the timetable query.');
		}

		$sessions = $event->getSessions();
		if (is_array($sessions) === false) {
			throw new RuntimeException('Planninq refused the timetable query: ' . (string)$event->getError());
		}

		return $sessions;
	}//end answerOf()

	/**
	 * Map each lesson of an answer onto learniq's session shape, skipping rows that are not lessons.
	 *
	 * @param array<mixed> $lessons The answered lessons.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function toSessions(array $lessons): array {
		$rows = [];
		foreach ($lessons as $lesson) {
			if (is_array($lesson) === true) {
				$rows[] = $this->toSession(lesson: $lesson);
			}
		}

		return $rows;
	}//end toSessions()

	/**
	 * Map one planninq lesson onto learniq's session shape.
	 *
	 * @param array<string,mixed> $lesson A lesson in planninq's read shape.
	 *
	 * @return array<string,mixed>
	 */
	private function toSession(array $lesson): array {
		$session = [];
		foreach (self::SESSION_FIELDS as $field) {
			$session[$field] = (string)($lesson[$field] ?? '');
		}

		$session['title'] = self::firstFilled(lesson: $lesson, fields: ['title', 'subject']);
		$session['location'] = self::firstFilled(lesson: $lesson, fields: ['roomLabel', 'roomReference']);
		// The lesson's online link (contract v2); the projector shows only https.
		$session['onlineMeetingUrl'] = $this->linkOf(lesson: $lesson);
		$session['lifecycle'] = 'scheduled';
		if (($lesson['status'] ?? '') === 'cancelled') {
			$session['lifecycle'] = 'cancelled';
		}

		$session['source'] = self::NAME;

		return $session;
	}//end toSession()

	/**
	 * The first of the fields that holds a non-empty value, as a string; '' when none does.
	 *
	 * @param array<string,mixed> $lesson The planninq lesson.
	 * @param list<string>        $fields The fields, in order of preference.
	 *
	 * @return string
	 */
	private static function firstFilled(array $lesson, array $fields): string {
		foreach ($fields as $field) {
			$value = (string)($lesson[$field] ?? '');
			if ($value !== '') {
				return $value;
			}
		}

		return '';
	}//end firstFilled()

	/**
	 * A lesson's online link, or null when it has none.
	 *
	 * @param array<string,mixed> $lesson The planninq lesson.
	 *
	 * @return string|null The link.
	 *
	 * @spec openspec/changes/timetabling-online-lesson-link/specs/timetable-online-lesson-link/spec.md#requirement-online-meeting-link-on-a-lesson
	 */
	private function linkOf(array $lesson): ?string {
		$link = ($lesson['onlineMeetingUrl'] ?? null);
		if (is_string($link) === false || trim($link) === '') {
			return null;
		}

		return trim($link);
	}//end linkOf()
}//end class
