<?php

/**
 * Tests for LessonNoteReader.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/timetabling-lesson-note/specs/personal-timetable/spec.md#requirement-learners-see-a-lessons-note-in-their-timetable
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\LessonNoteReader;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Matches notes to lessons on either key and filters them by audience.
 */
class LessonNoteReaderTest extends TestCase {

	/**
	 * Build the reader over note rows.
	 *
	 * @param array<int,array<string,mixed>> $notes  Stored notes.
	 * @param array<int,string>              $groups The caller's groups.
	 * @param bool                           $fails  Whether the read throws.
	 *
	 * @return LessonNoteReader
	 */
	private function reader(array $notes, array $groups = [], bool $fails = false): LessonNoteReader {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config, bool $_rbac = true) use ($notes, $fails): array {
				if ($fails === true) {
					throw new RuntimeException('down');
				}

				// Notes are read without the caller's RBAC: the reader filters.
				self::assertFalse($_rbac);
				$cohort = $config['filters']['cohortId'];
				return OrEntityFactory::makeMany(array_values(array_filter($notes, static fn (array $n): bool => $n['cohortId'] === $cohort)), 'lesson-note');
			}
		);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $u, string $g): bool => in_array($g, $groups, true));

		return new LessonNoteReader($objects, $groupManager, new NullLogger());
	}//end reader()

	/**
	 * A planninq lesson gets its notes through the timetable reference.
	 *
	 * @return void
	 */
	public function testPlanninqLessonMatchesOnTheReference(): void {
		$notes = [
			['id' => 'n-1', 'cohortId' => 'c-1', 'timetableSessionRef' => ['sourceSystem' => 'roster-zermelo', 'externalRef' => 'zm-1'], 'text' => 'a', 'audience' => 'learners'],
			['id' => 'n-2', 'cohortId' => 'c-1', 'timetableSessionRef' => ['sourceSystem' => 'roster-zermelo', 'externalRef' => 'zm-2'], 'text' => 'b', 'audience' => 'learners'],
			['id' => 'n-3', 'cohortId' => 'c-1', 'timetableSessionRef' => ['sourceSystem' => 'roster-untis', 'externalRef' => 'zm-1'], 'text' => 'c', 'audience' => 'learners'],
		];
		$lesson = ['id' => 'p-1', 'cohortId' => 'c-1', 'externalRef' => 'zm-1', 'sourceSystem' => 'roster-zermelo', 'source' => 'planninq'];

		$out = $this->reader(notes: $notes)->forSessions(sessions: [$lesson], uid: 'alice', taughtCohortIds: []);

		self::assertSame(['n-1'], array_column($out['p-1'], 'id'));
	}//end testPlanninqLessonMatchesOnTheReference()

	/**
	 * The planninq lesson's own teacher and staff read the cover note; a learner does not.
	 *
	 * @return void
	 */
	public function testCoverNoteAudience(): void {
		$notes = [['id' => 'n-1', 'cohortId' => 'c-1', 'sessionId' => 's-1', 'text' => 'cover', 'audience' => 'cover']];
		$lesson = ['id' => 's-1', 'cohortId' => 'c-1', 'teacherUserId' => 'jan'];

		self::assertSame([], $this->reader(notes: $notes)->forSessions(sessions: [$lesson], uid: 'alice', taughtCohortIds: []));
		self::assertCount(1, $this->reader(notes: $notes)->forSessions(sessions: [$lesson], uid: 'jan', taughtCohortIds: [])['s-1']);
		self::assertCount(1, $this->reader(notes: $notes, groups: ['team-leads'])->forSessions(sessions: [$lesson], uid: 'lead', taughtCohortIds: [])['s-1']);
	}//end testCoverNoteAudience()

	/**
	 * A failing read leaves the timetable without notes, not broken.
	 *
	 * @return void
	 */
	public function testAFailingReadYieldsNoNotes(): void {
		$lesson = ['id' => 's-1', 'cohortId' => 'c-1'];

		self::assertSame([], $this->reader(notes: [], fails: true)->forSessions(sessions: [$lesson], uid: 'alice', taughtCohortIds: []));
		self::assertSame([], $this->reader(notes: [])->forSessions(sessions: [], uid: 'alice', taughtCohortIds: []));
	}//end testAFailingReadYieldsNoNotes()
}//end class
