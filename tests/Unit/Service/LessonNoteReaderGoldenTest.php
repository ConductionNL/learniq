<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\LessonNoteReader;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\Tests\Support\GoldenFile;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins which notes forSessions() hands to which caller, and which lessons
 * markWritable() opens to them, against golden/LessonNoteReader.json. The
 * group manager answers yes only for the user it is asked about, and every
 * question it gets is recorded, so a check run for anyone but the caller
 * (or for a user id a lesson carries) changes the golden.
 *
 * @covers \OCA\Learniq\Service\LessonNoteReader
 * @uses   \OCA\Learniq\Service\StaffGroupCheck
 */
class LessonNoteReaderGoldenTest extends TestCase {
	use GoldenFile;

	private const GOLDEN = __DIR__ . '/golden/LessonNoteReader.json';

	/**
	 * Group questions, in order.
	 *
	 * @var list<mixed>
	 */
	private array $asked = [];

	/**
	 * Every caller sees and may write what was recorded.
	 *
	 * @return void
	 */
	public function testNotesPerCallerMatchGolden(): void {
		$reader = new LessonNoteReader($this->objects(), $this->groupManager(), new CapturingLogger());
		$callers = [
			'learner' => ['anna', []],
			'the lesson teacher' => ['jan', []],
			'the substitute' => ['sub', []],
			'a teacher of the cohort' => ['piet', ['c-2']],
			'a team lead' => ['lead', []],
			'a compliance officer' => ['officer', []],
			'an admin' => ['root', []],
			'an empty uid' => ['', []],
			'a uid only a lesson names' => ['forged', ['c-9']],
		];

		$actual = [];
		foreach ($callers as $name => [$uid, $taught]) {
			$this->asked = [];
			$actual['notes for ' . $name] = ['notes' => $reader->forSessions(sessions: $this->sessions(), uid: $uid, taughtCohortIds: $taught), 'asked' => $this->asked];
			$this->asked = [];
			$marked = $reader->markWritable(sessions: $this->sessions(), uid: $uid, taughtCohortIds: $taught);
			$actual['writable for ' . $name] = ['canAddNote' => array_column($marked, 'canAddNote'), 'asked' => $this->asked];
		}

		$this->asked = [];
		$actual['no lessons'] = [$reader->forSessions(sessions: [], uid: 'root', taughtCohortIds: []), $reader->markWritable(sessions: [], uid: 'root', taughtCohortIds: []), $this->asked];

		$matches = [];
		foreach ($this->notes() as $index => $note) {
			foreach ($this->sessions() as $session) {
				$matches[] = $index . '>' . (string)($session['id'] ?? ($session['uuid'] ?? '?')) . '=' . json_encode($reader->belongsTo(note: $note, session: $session));
			}
		}

		$actual['belongsTo'] = $matches;

		$this->assertMatchesGolden(file: self::GOLDEN, actual: $actual);
	}//end testNotesPerCallerMatchGolden()

	/**
	 * The notes per cohort; cohort c-3 cannot be read.
	 *
	 * @return ObjectService
	 */
	private function objects(): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$cohortId = $config['filters']['cohortId'];
				if ($cohortId === 'c-3') {
					throw new RuntimeException('down');
				}

				$rows = array_values(array_filter($this->notes(), static fn (array $note): bool => in_array($note['cohortId'] ?? null, [$cohortId, 'stray'], true)));
				return array_merge(array_slice($rows, 0, 2), OrEntityFactory::makeMany(array_slice($rows, 2), 'lesson-note'));
			}
		);

		return $objects;
	}//end objects()

	/**
	 * A group manager that says yes only for the user named in the question.
	 *
	 * @return IGroupManager
	 */
	private function groupManager(): IGroupManager {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(
			function (string $uid): bool {
				$this->asked[] = ['isAdmin' => $uid];
				return $uid === 'root';
			}
		);
		$groups->method('isInGroup')->willReturnCallback(
			function (string $uid, string $group): bool {
				$this->asked[] = ['isInGroup' => [$uid, $group]];
				return in_array($uid . ':' . $group, ['lead:team-leads', 'officer:compliance-officers'], true);
			}
		);

		return $groups;
	}//end groupManager()

	/**
	 * Lessons of three cohorts, by id and by external reference.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function sessions(): array {
		return [
			['id' => 's-1', 'cohortId' => 'c-1', 'teacherUserId' => 'jan'],
			['id' => 's-2', 'cohortId' => 'c-1', 'substituteTeacherId' => 'sub', 'externalRef' => 'ext-2', 'sourceSystem' => 'planninq'],
			['uuid' => 's-3', 'cohortId' => 'c-2', 'externalRef' => 'ext-3'],
			['id' => 's-4', 'cohortId' => 'c-2', 'cover' => true, 'teacherUserId' => 'forged'],
			['id' => 's-5', 'cohortId' => 'c-3'],
			['cohortId' => 'c-1', 'title' => 'zonder id'],
			['id' => 's-1', 'cohortId' => 'c-1', 'substituteTeacherId' => 'forged'],
			['id' => 's-7', 'cohortId' => 'c-9', 'cover' => 'yes'],
		];
	}//end sessions()

	/**
	 * Notes for learners and for cover, by lesson id and by external reference.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function notes(): array {
		return [
			['id' => 'n-1', 'cohortId' => 'c-1', 'sessionId' => 's-1', 'topic' => 'Breuken', 'text' => 'Boek mee', 'authorId' => 'jan'],
			['uuid' => 'n-2', 'cohortId' => 'c-1', 'sessionId' => 's-1', 'audience' => 'cover', 'text' => 'Toets ligt in la'],
			['id' => 'n-3', 'cohortId' => 'c-1', 'timetableSessionRef' => ['externalRef' => 'ext-2', 'sourceSystem' => 'planninq'], 'text' => 'Lokaal gewijzigd'],
			['id' => 'n-4', 'cohortId' => 'c-1', 'timetableSessionRef' => ['externalRef' => 'ext-2', 'sourceSystem' => 'zermelo'], 'text' => 'Ander systeem'],
			['id' => 'n-5', 'cohortId' => 'c-2', 'timetableSessionRef' => ['externalRef' => 'ext-3'], 'audience' => 'staff', 'text' => 'Alleen staf'],
			['id' => 'n-6', 'cohortId' => 'c-2', 'timetableSessionRef' => ['externalRef' => ''], 'text' => 'Lege ref'],
			['id' => 'n-7', 'cohortId' => 'c-2', 'timetableSessionRef' => 'ext-3', 'text' => 'Ref als tekst'],
			['id' => 'n-8', 'cohortId' => 'c-2', 'sessionId' => 's-4', 'audience' => 'cover', 'text' => 'Invaller'],
			['id' => 'n-9', 'cohortId' => 'stray', 'sessionId' => 's-1', 'text' => 'Ander cohort'],
			['id' => 'n-10', 'cohortId' => 'c-1', 'sessionId' => 's-2', 'audience' => 'learners'],
			['id' => 'n-11', 'cohortId' => 'c-9', 'sessionId' => 's-7', 'audience' => 'cover', 'text' => 'Cover als tekst'],
		];
	}//end notes()
}//end class
