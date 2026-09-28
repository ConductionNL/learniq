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
 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-the-planninq-source-reads-through-planninqs-query-event-req-002
 */

declare(strict_types=1);

namespace OCA\Learniq\Timetabling\Source;

use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use RuntimeException;

/**
 * Reads timetable sessions from planninq.
 *
 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-the-planninq-source-reads-through-planninqs-query-event-req-002
 */
class PlanninqTimetableSource implements TimetableSource {

	public const NAME = 'planninq';

	public const PLANNINQ_APP = 'planninq';

	public const QUERY_EVENT = 'OCA\\Planninq\\Event\\TimetableSessionsQueryEvent';

	/**
	 * Planninq caps one read at this many lessons.
	 */
	private const LIMIT = 1000;

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
	 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-a-resolver-picks-planninq-when-it-is-installed-req-001
	 */
	public function isAvailable(): bool {
		return $this->appManager->isInstalled(self::PLANNINQ_APP) === true && class_exists($this->eventClass) === true;
	}//end isAvailable()

	/**
	 * The source's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-the-planninq-source-reads-through-planninqs-query-event-req-002
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
	 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-the-planninq-source-reads-through-planninqs-query-event-req-002
	 */
	public function sessionsForCohorts(array $cohortIds, ?string $from, ?string $to): array {
		$rows = [];
		foreach (array_unique($cohortIds) as $cohortId) {
			if ($cohortId !== '') {
				$rows = array_merge($rows, $this->query(identity: ['cohortId' => $cohortId], from: $from, to: $to));
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
	 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-the-planninq-source-reads-through-planninqs-query-event-req-002
	 */
	public function sessionsForTeacher(string $userId, ?string $from, ?string $to): array {
		if ($userId === '') {
			return [];
		}

		return $this->query(identity: ['teacherUserId' => $userId], from: $from, to: $to);
	}//end sessionsForTeacher()

	/**
	 * Dispatch one query and map planninq's lessons onto learniq's session shape.
	 *
	 * @param array<string,string> $identity The identity criterion.
	 * @param string|null          $from     ISO 8601 window start, or null.
	 * @param string|null          $to       ISO 8601 window end, or null.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws RuntimeException When planninq is absent, silent or refuses.
	 */
	private function query(array $identity, ?string $from, ?string $to): array {
		if (class_exists($this->eventClass) === false) {
			throw new RuntimeException('Planninq is not installed, so its timetable cannot be read.');
		}

		$criteria = array_merge(
			$identity,
			['limit' => self::LIMIT],
			array_filter(['from' => $from, 'to' => $to], static fn (?string $bound): bool => $bound !== null && $bound !== '')
		);

		// Named arguments work on a dynamic class name, and they are the
		// contract: a renamed parameter in planninq fails here, loudly.
		$event = new ($this->eventClass)(sourceApp: 'learniq', criteria: $criteria);
		$this->dispatcher->dispatchTyped($event);

		if ($event->isHandled() === false) {
			throw new RuntimeException('Planninq did not answer the timetable query.');
		}

		$sessions = $event->getSessions();
		if (is_array($sessions) === false) {
			throw new RuntimeException('Planninq refused the timetable query: ' . (string)$event->getError());
		}

		$rows = [];
		foreach ($sessions as $lesson) {
			if (is_array($lesson) === true) {
				$rows[] = $this->toSession(lesson: $lesson);
			}
		}

		return $rows;
	}//end query()

	/**
	 * Map one planninq lesson onto learniq's session shape.
	 *
	 * @param array<string,mixed> $lesson A lesson in planninq's read shape.
	 *
	 * @return array<string,mixed>
	 */
	private function toSession(array $lesson): array {
		$location = (string)($lesson['roomLabel'] ?? '');
		if ($location === '') {
			$location = (string)($lesson['roomReference'] ?? '');
		}

		$lifecycle = 'scheduled';
		if (($lesson['status'] ?? '') === 'cancelled') {
			$lifecycle = 'cancelled';
		}

		$title = (string)($lesson['title'] ?? '');
		if ($title === '') {
			$title = (string)($lesson['subject'] ?? '');
		}

		return [
			'id' => (string)($lesson['id'] ?? ''),
			'title' => $title,
			'startsAt' => (string)($lesson['startsAt'] ?? ''),
			'endsAt' => (string)($lesson['endsAt'] ?? ''),
			'location' => $location,
			'cohortId' => (string)($lesson['cohortId'] ?? ''),
			'lifecycle' => $lifecycle,
			'teacherUserId' => (string)($lesson['teacherUserId'] ?? ''),
			'roomReference' => (string)($lesson['roomReference'] ?? ''),
			'groupReference' => (string)($lesson['groupReference'] ?? ''),
			'externalRef' => (string)($lesson['externalRef'] ?? ''),
			'sourceSystem' => (string)($lesson['sourceSystem'] ?? ''),
			'source' => self::NAME,
		];
	}//end toSession()
}//end class
