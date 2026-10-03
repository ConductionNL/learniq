<?php

/**
 * Learniq Lesson Note Reader
 *
 * Finds the notes of the lessons in a timetable and keeps the ones the caller
 * may read. A note names its lesson by `sessionId` (a learniq Session) or by
 * `timetableSessionRef` (`{sourceSystem, externalRef}` of a planninq lesson,
 * decision D10), and the reader matches on whichever key the lesson has.
 *
 * Learners have no direct read on `lesson-note` (its authorization is staff
 * only), so the notes are read here without the caller's RBAC and filtered by
 * one rule: a note for learners reaches everyone who has the lesson in their
 * timetable; a note for the covering teacher reaches only the lesson's
 * teachers, its substitute and staff. The timetable endpoint is the one place
 * a learner reads a note, so a cover note never reaches a learner.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/specs/personal-timetable/spec.md#requirement-learners-see-a-lessons-note-in-their-timetable
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads lesson notes for a set of lessons, filtered to what the caller may see.
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-learners-see-a-lessons-note-in-their-timetable
 */
class LessonNoteReader {

	private const REGISTER = 'learniq';
	private const SCHEMA = 'lesson-note';

	/**
	 * Groups that read every note of every lesson they can see.
	 */
	private const STAFF_GROUPS = ['team-leads', 'compliance-officers'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister object access.
	 * @param IGroupManager   $groupManager  Staff checks.
	 * @param LoggerInterface $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The visible notes of each lesson, keyed by the lesson's id.
	 *
	 * @param array<int,array<string,mixed>> $sessions        Lessons in learniq's session shape.
	 * @param string                         $uid             The caller.
	 * @param array<int,string>              $taughtCohortIds Cohorts the caller teaches.
	 *
	 * @return array<string,array<int,array<string,mixed>>> Notes per lesson id: `topic`, `text`, `audience`, `authorId`.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-learners-see-a-lessons-note-in-their-timetable
	 */
	public function forSessions(array $sessions, string $uid, array $taughtCohortIds): array {
		if ($sessions === []) {
			return [];
		}

		$staff = $this->isStaff(uid: $uid);
		$notes = $this->notesOfCohorts(cohortIds: $this->cohortsOf(sessions: $sessions));

		$out = [];
		foreach ($sessions as $session) {
			$sessionId = (string)($session['id'] ?? ($session['uuid'] ?? ''));
			if ($sessionId === '') {
				continue;
			}

			$seesCover = $staff === true || $this->teachesOrCovers(session: $session, uid: $uid, taughtCohortIds: $taughtCohortIds) === true;

			foreach ($notes as $note) {
				if ($this->belongsTo(note: $note, session: $session) === false) {
					continue;
				}

				$audience = (string)($note['audience'] ?? 'learners');
				if ($audience !== 'learners' && $seesCover === false) {
					continue;
				}

				$out[$sessionId][] = [
					'id' => (string)($note['id'] ?? ($note['uuid'] ?? '')),
					'topic' => $note['topic'] ?? null,
					'text' => (string)($note['text'] ?? ''),
					'audience' => $audience,
					'authorId' => (string)($note['authorId'] ?? ''),
				];
			}
		}//end foreach

		return $out;
	}//end forSessions()

	/**
	 * Mark each projected lesson with whether the caller may add a note to it:
	 * staff, the lesson's teachers and its substitute may (the same line
	 * {@see \OCA\Learniq\Listener\LessonNoteAuthorGuard} holds on the write).
	 *
	 * @param array<int,array<string,mixed>> $sessions        Projected lessons.
	 * @param string                         $uid             The caller.
	 * @param array<int,string>              $taughtCohortIds Cohorts the caller teaches.
	 *
	 * @return array<int,array<string,mixed>> The lessons with `canAddNote`.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
	 */
	public function markWritable(array $sessions, string $uid, array $taughtCohortIds): array {
		if ($sessions === []) {
			return [];
		}

		$staff = $this->isStaff(uid: $uid);
		foreach ($sessions as $index => $session) {
			$sessions[$index]['canAddNote'] = $staff === true
				|| $this->teachesOrCovers(session: $session, uid: $uid, taughtCohortIds: $taughtCohortIds) === true;
		}

		return $sessions;
	}//end markWritable()

	/**
	 * Whether the caller teaches or covers a lesson.
	 *
	 * @param array<string,mixed> $session         The lesson.
	 * @param string              $uid             The caller.
	 * @param array<int,string>   $taughtCohortIds Cohorts the caller teaches.
	 *
	 * @return bool
	 */
	private function teachesOrCovers(array $session, string $uid, array $taughtCohortIds): bool {
		return ($session['cover'] ?? false) === true
			|| (string)($session['substituteTeacherId'] ?? '') === $uid
			|| (string)($session['teacherUserId'] ?? '') === $uid
			|| in_array((string)($session['cohortId'] ?? ''), $taughtCohortIds, true) === true;
	}//end teachesOrCovers()

	/**
	 * Whether a note names this lesson.
	 *
	 * @param array<string,mixed> $note    The note.
	 * @param array<string,mixed> $session The lesson.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-learners-see-a-lessons-note-in-their-timetable
	 */
	public function belongsTo(array $note, array $session): bool {
		if ((string)($note['cohortId'] ?? '') !== (string)($session['cohortId'] ?? '')) {
			return false;
		}

		$noteSession = (string)($note['sessionId'] ?? '');
		if ($noteSession !== '') {
			return $noteSession === (string)($session['id'] ?? ($session['uuid'] ?? ''));
		}

		$ref = $note['timetableSessionRef'] ?? null;
		if (is_array($ref) === false || (string)($ref['externalRef'] ?? '') === '') {
			return false;
		}

		if ((string)$ref['externalRef'] !== (string)($session['externalRef'] ?? '')) {
			return false;
		}

		$system = (string)($ref['sourceSystem'] ?? '');
		return $system === '' || $system === (string)($session['sourceSystem'] ?? '');
	}//end belongsTo()

	/**
	 * The distinct cohorts of a set of lessons.
	 *
	 * @param array<int,array<string,mixed>> $sessions Lessons.
	 *
	 * @return array<int,string>
	 */
	private function cohortsOf(array $sessions): array {
		$ids = [];
		foreach ($sessions as $session) {
			$cohortId = (string)($session['cohortId'] ?? '');
			if ($cohortId !== '') {
				$ids[$cohortId] = true;
			}
		}

		return array_keys($ids);
	}//end cohortsOf()

	/**
	 * Every note on the lessons of the given cohorts.
	 *
	 * Read without the caller's RBAC: forSessions() decides what is returned.
	 *
	 * @param array<int,string> $cohortIds Cohort uuids.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function notesOfCohorts(array $cohortIds): array {
		$notes = [];
		foreach ($cohortIds as $cohortId) {
			try {
				$rows = $this->objectService->findAll(
					[
						'filters' => [
							'register' => self::REGISTER,
							'schema' => self::SCHEMA,
							'cohortId' => $cohortId,
						],
					],
					_rbac: false
				);
			} catch (Throwable $exception) {
				// A timetable without its notes beats no timetable.
				$this->logger->warning('[LessonNoteReader] Notes of cohort {id} not readable: {msg}', ['id' => $cohortId, 'msg' => $exception->getMessage()]);
				continue;
			}

			foreach ($rows as $row) {
				$note = $row;
				if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
					$note = (array)$row->jsonSerialize();
				}

				if (is_array($note) === true && (string)($note['cohortId'] ?? '') === $cohortId) {
					$notes[] = $note;
				}
			}
		}//end foreach

		return $notes;
	}//end notesOfCohorts()

	/**
	 * Whether the caller reads every note (admin or a staff group).
	 *
	 * @param string $uid The caller.
	 *
	 * @return bool
	 */
	private function isStaff(string $uid): bool {
		if ($this->groupManager->isAdmin($uid) === true) {
			return true;
		}

		foreach (self::STAFF_GROUPS as $group) {
			if ($this->groupManager->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		return false;
	}//end isStaff()
}//end class
