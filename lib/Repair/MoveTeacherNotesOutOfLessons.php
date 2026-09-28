<?php

/**
 * Learniq Move Teacher Notes Out Of Lessons
 *
 * Every signed-in user reads a Lesson (teacher-notes-protection), so a
 * `teacherNote` block inside Lesson.blocks was readable by learners through
 * the objects API, however well the lesson player hid it. Notes now live in
 * the staff-only `lesson-teacher-note` schema, and the Lesson schema no longer
 * accepts the block type. This step moves the notes lessons already hold.
 *
 * Per lesson that holds a note:
 *   1. split the blocks (TeacherNoteSplitter): learner blocks, anchored notes;
 *   2. create every note whose `lessonId` + `blockId` does not exist yet;
 *   3. only when every note exists, save the lesson with the learner blocks.
 *
 * Creating before stripping means a failure never loses a note: the note stays
 * in the lesson until its copy exists, and the next run finds the copy by
 * `lessonId` + `blockId`, creates nothing and strips the lesson. A lesson
 * without a note is not written at all, so the step is a no-op once done.
 *
 * Runs from `occ upgrade`, without a user, so it reads and writes as a system
 * operation. Logs lesson ids and counts, never note text.
 *
 * @category Repair
 * @package  OCA\Learniq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/teacher-notes-protection/specs/course-management/spec.md#requirement-an-upgrade-moves-the-notes-lessons-already-hold
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCA\Learniq\Service\LessonOnboarding\TeacherNoteSplitter;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moves `teacherNote` blocks from lessons into the staff-only note schema.
 */
class MoveTeacherNotesOutOfLessons implements IRepairStep {

	private const REGISTER = 'learniq';

	private const LESSON_SCHEMA = 'lesson';

	private const NOTE_SCHEMA = 'lesson-teacher-note';

	private const PAGE_SIZE = 200;

	private const MAX_PAGES = 1000;

	/**
	 * Constructor.
	 *
	 * @param ObjectService       $objectService OpenRegister object access.
	 * @param LoggerInterface     $logger        PSR logger; ids and counts only.
	 * @param TeacherNoteSplitter $splitter      The one split rule, shared with the importer.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly TeacherNoteSplitter $splitter=new TeacherNoteSplitter(),
	) {

	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/teacher-notes-protection/specs/course-management/spec.md#requirement-an-upgrade-moves-the-notes-lessons-already-hold
	 */
	public function getName(): string {
		return 'Move teacher notes out of lessons into the store only staff can read';

	}//end getName()

	/**
	 * Move every lesson's notes.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/teacher-notes-protection/specs/course-management/spec.md#requirement-an-upgrade-moves-the-notes-lessons-already-hold
	 */
	public function run(IOutput $output): void {
		$counts = ['lessons' => 0, 'notes' => 0, 'left' => 0];

		try {
			for ($page = 0; $page < self::MAX_PAGES; $page++) {
				$lessons = $this->rows(schema: self::LESSON_SCHEMA, filters: [], offset: ($page * self::PAGE_SIZE));
				foreach ($lessons as $lesson) {
					$this->moveLesson(lesson: $lesson, counts: $counts);
				}

				if (count($lessons) < self::PAGE_SIZE) {
					break;
				}
			}
		} catch (Throwable $e) {
			// OpenRegister absent or the register not imported yet: nothing
			// to move on this run, the next upgrade retries.
			$this->logger->warning('[MoveTeacherNotesOutOfLessons] Stopped early: {msg}', ['msg' => $e->getMessage()]);
		}

		$output->info(
			'Learniq teacher notes: ' . $counts['lessons'] . ' lesson(s) cleared, ' . $counts['notes']
			. ' note(s) moved, ' . $counts['left'] . ' lesson(s) left for the next run.'
		);

	}//end run()

	/**
	 * Move one lesson's notes, if it has any.
	 *
	 * @param array<string, mixed> $lesson The lesson as OpenRegister serialises it.
	 * @param array<string, int>   $counts Running counts, updated in place.
	 *
	 * @return void
	 */
	private function moveLesson(array $lesson, array &$counts): void {
		$blocks = ($lesson['blocks'] ?? null);
		$uuid   = $this->uuidOf(row: $lesson);
		if (is_array($blocks) === false || $uuid === null || $this->splitter->hasNotes(blocks: $blocks) === false) {
			return;
		}

		$split   = $this->splitter->split(blocks: $blocks);
		$created = $this->createMissingNotes(lessonId: $uuid, tenantId: (string)($lesson['tenant_id'] ?? ''), notes: $split['notes']);
		if ($created === null) {
			$counts['left']++;
			return;
		}

		try {
			$this->objectService->saveObject(
				object: array_merge($this->dataOf(row: $lesson), ['blocks' => $split['blocks']]),
				register: self::REGISTER,
				schema: self::LESSON_SCHEMA,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			// The notes exist now; the next run finds them and only strips.
			$this->logger->warning('[MoveTeacherNotesOutOfLessons] Lesson {id} kept its note blocks: {msg}', ['id' => $uuid, 'msg' => $e->getMessage()]);
			$counts['left']++;
			$counts['notes'] += $created;
			return;
		}

		$counts['lessons']++;
		$counts['notes'] += $created;

	}//end moveLesson()

	/**
	 * Create the notes the store does not have yet, by `blockId`.
	 *
	 * @param string                                                                            $lessonId The lesson.
	 * @param string                                                                            $tenantId The lesson's tenant.
	 * @param list<array{blockId: string, afterBlockId: string, position: int, text: string}> $notes    The split notes.
	 *
	 * @return int|null How many were created, or null when one failed (the lesson must keep its notes).
	 */
	private function createMissingNotes(string $lessonId, string $tenantId, array $notes): ?int {
		try {
			$existing = [];
			foreach ($this->rows(schema: self::NOTE_SCHEMA, filters: ['lessonId' => $lessonId], offset: 0) as $note) {
				$existing[] = (string)($note['blockId'] ?? '');
			}

			$created = 0;
			foreach ($notes as $note) {
				if (in_array($note['blockId'], $existing, true) === true) {
					continue;
				}

				$this->objectService->saveObject(
					object: [...$note, 'lessonId' => $lessonId, 'tenant_id' => $tenantId],
					register: self::REGISTER,
					schema: self::NOTE_SCHEMA,
					_rbac: false,
					_multitenancy: false
				);
				$created++;
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'[MoveTeacherNotesOutOfLessons] Lesson {id} kept its note blocks, a note could not be created: {msg}',
				['id' => $lessonId, 'msg' => $e->getMessage()]
			);
			return null;
		}

		return $created;

	}//end createMissingNotes()

	/**
	 * One page of a schema's objects, as arrays.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Extra property filters.
	 * @param int                  $offset  The page offset.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $schema, array $filters, int $offset): array {
		$objects = $this->objectService->findAll(
			config: [
				'filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters),
				'limit'   => self::PAGE_SIZE,
				'offset'  => $offset,
			],
			_rbac: false,
			_multitenancy: false
		);

		$rows = [];
		foreach ($objects as $object) {
			if (is_array($object) === true) {
				$rows[] = $object;
			} else if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$rows[] = (array)$object->jsonSerialize();
			}
		}

		return $rows;

	}//end rows()

	/**
	 * The object's uuid, from any shape OpenRegister serialises.
	 *
	 * @param array<string, mixed> $row The object.
	 *
	 * @return string|null
	 */
	private function uuidOf(array $row): ?string {
		$uuid = ($row['id'] ?? ($row['uuid'] ?? ($row['@self']['id'] ?? null)));
		if (is_string($uuid) === false || $uuid === '') {
			return null;
		}

		return $uuid;

	}//end uuidOf()

	/**
	 * The object's own fields, without OpenRegister's metadata envelope.
	 *
	 * @param array<string, mixed> $row The object.
	 *
	 * @return array<string, mixed>
	 */
	private function dataOf(array $row): array {
		unset($row['@self']);

		return $row;

	}//end dataOf()
}//end class
