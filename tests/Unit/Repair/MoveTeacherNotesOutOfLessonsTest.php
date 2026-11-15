<?php

/**
 * Unit tests for MoveTeacherNotesOutOfLessons: existing teacher notes leave
 * the lessons learners read, a note is never lost, and a rerun duplicates
 * nothing (teacher-notes-protection).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/course-management/spec.md#requirement-an-upgrade-moves-the-notes-lessons-already-hold
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use OCA\Learniq\Repair\MoveTeacherNotesOutOfLessons;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Repair\MoveTeacherNotesOutOfLessons
 * @uses   \OCA\Learniq\Service\LessonOnboarding\TeacherNoteSplitter
 */
class MoveTeacherNotesOutOfLessonsTest extends TestCase {

	private const LESSON = '00000000-0000-0000-0000-00000000a001';

	/**
	 * Every saveObject call, in order: [schema, object, uuid].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>, 2: string|null}>
	 */
	private array $saves = [];

	/**
	 * A lesson with a note between two blocks, and one without notes.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function lessons(): array {
		return [
			[
				'id'        => self::LESSON,
				'@self'     => ['id' => self::LESSON, 'register' => 'learniq', 'schema' => 'lesson'],
				'name'      => 'Licht en schaduw',
				'courseId'  => '00000000-0000-0000-0000-00000000c001',
				'tenant_id' => '00000000-0000-0000-0000-000000000001',
				'blocks'    => [
					['blockId' => 'a', 'type' => 'richText', 'order' => 1, 'text' => 'Wat is licht?'],
					['blockId' => 'n', 'type' => 'teacherNote', 'order' => 2, 'text' => 'Sem heeft hier extra uitleg nodig.'],
					['blockId' => 'b', 'type' => 'richText', 'order' => 3, 'text' => 'Proefje'],
				],
			],
			[
				'id'     => '00000000-0000-0000-0000-00000000a002',
				'name'   => 'Zonder notities',
				'blocks' => [['blockId' => 'x', 'type' => 'richText', 'order' => 1, 'text' => 'Tekst']],
			],
		];
	}//end lessons()

	/**
	 * Build the step over an ObjectService double.
	 *
	 * @param list<array<string, mixed>> $existingNotes Notes the store already holds.
	 * @param bool                       $noteFails     Whether creating a note throws.
	 * @param bool                       $lessonFails   Whether saving a lesson throws.
	 *
	 * @return MoveTeacherNotesOutOfLessons
	 */
	private function step(array $existingNotes=[], bool $noteFails=false, bool $lessonFails=false): MoveTeacherNotesOutOfLessons {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($existingNotes): array {
				$filters = $config['filters'];
				if ($filters['schema'] === 'lesson') {
					return [OrEntityFactory::make($this->lessons()[0], 'lesson', 'learniq', self::LESSON), $this->lessons()[1]];
				}

				self::assertSame('lesson-teacher-note', $filters['schema']);
				self::assertSame(self::LESSON, $filters['lessonId'], 'notes are looked up per lesson');
				return $existingNotes;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], $register=null, $schema=null, ?string $uuid=null) use ($noteFails, $lessonFails) {
				if ($schema === 'lesson-teacher-note' && $noteFails === true) {
					throw new RuntimeException('validation failed');
				}

				if ($schema === 'lesson' && $lessonFails === true) {
					throw new RuntimeException('lesson locked');
				}

				$this->saves[] = [(string)$schema, $object, $uuid];
				return OrEntityFactory::make($object, (string)$schema);
			}
		);

		return new MoveTeacherNotesOutOfLessons($objectService, new NullLogger());
	}//end step()

	/**
	 * The note is created first, then the lesson is saved without it; a lesson
	 * without notes is not written at all.
	 *
	 * @return void
	 */
	public function testANoteMovesAndTheLessonIsStripped(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('1 lesson(s) cleared, 1 note(s) moved, 0 lesson(s) left'));

		$this->step()->run($output);

		self::assertCount(2, $this->saves);
		[$noteSchema, $note] = $this->saves[0];
		self::assertSame('lesson-teacher-note', $noteSchema, 'the note is created before the lesson changes');
		self::assertSame(
			['blockId' => 'n', 'afterBlockId' => 'a', 'position' => 0, 'text' => 'Sem heeft hier extra uitleg nodig.', 'lessonId' => self::LESSON, 'tenant_id' => '00000000-0000-0000-0000-000000000001'],
			$note
		);

		[$lessonSchema, $lesson, $uuid] = $this->saves[1];
		self::assertSame('lesson', $lessonSchema);
		self::assertSame(self::LESSON, $uuid);
		self::assertSame(['a', 'b'], array_column($lesson['blocks'], 'blockId'));
		self::assertNotContains('teacherNote', array_column($lesson['blocks'], 'type'));
		self::assertSame('Licht en schaduw', $lesson['name'], 'the rest of the lesson is kept');
		self::assertArrayNotHasKey('@self', $lesson);
	}//end testANoteMovesAndTheLessonIsStripped()

	/**
	 * After a run that created the note but could not save the lesson, the
	 * next run creates nothing and only strips the lesson.
	 *
	 * @return void
	 */
	public function testARerunCreatesNothingTwice(): void {
		$this->step(existingNotes: [['blockId' => 'n', 'lessonId' => self::LESSON]])->run($this->createMock(IOutput::class));

		self::assertSame(['lesson'], array_column($this->saves, 0));
		self::assertSame(['a', 'b'], array_column($this->saves[0][1]['blocks'], 'blockId'));
	}//end testARerunCreatesNothingTwice()

	/**
	 * A note that cannot be created keeps its block in the lesson.
	 *
	 * @return void
	 */
	public function testAFailedCreateKeepsTheNoteInTheLesson(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('0 lesson(s) cleared, 0 note(s) moved, 1 lesson(s) left'));

		$this->step(noteFails: true)->run($output);

		self::assertSame([], $this->saves, 'no lesson is saved when its note could not be created');
	}//end testAFailedCreateKeepsTheNoteInTheLesson()

	/**
	 * A lesson that cannot be saved is left for the next run; the note it
	 * already has is counted.
	 *
	 * @return void
	 */
	public function testAFailedLessonSaveIsLeftForTheNextRun(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('0 lesson(s) cleared, 1 note(s) moved, 1 lesson(s) left'));

		$this->step(lessonFails: true)->run($output);

		self::assertSame(['lesson-teacher-note'], array_column($this->saves, 0));
	}//end testAFailedLessonSaveIsLeftForTheNextRun()

	/**
	 * Without OpenRegister the step stops quietly and says what it did.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheStepStopsQuietly(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willThrowException(new RuntimeException('register not imported'));
		$objectService->expects(self::never())->method('saveObject');
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('0 lesson(s) cleared'));

		(new MoveTeacherNotesOutOfLessons($objectService, new NullLogger()))->run($output);
	}//end testWithoutOpenRegisterTheStepStopsQuietly()
}//end class
