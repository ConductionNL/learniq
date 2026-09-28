<?php

/**
 * Learniq teacher note access, from the learner's side.
 *
 * A teacher note used to be a `teacherNote` block inside Lesson.blocks. The
 * lesson player hid it, but Lesson is readable by every signed-in user, so a
 * learner who asked the objects API for a lesson read every note in full
 * (learniq #1080, "hidden, not protected"). teacher-notes-protection moves
 * notes to LessonTeacherNote.
 *
 * These tests take the learner's and the guardian's position and evaluate the
 * shipped authorization blocks the way OpenRegister does: a plain group entry
 * admits a member of that group, `authenticated` admits every signed-in user,
 * and a `{group, match}` entry can admit a member of the group when the row
 * matches. A match entry is counted as admitting, because a learner might be
 * the matched person; only an entry the learner's groups cannot reach at all
 * counts as closed.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Register
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/teacher-notes-protection/specs/course-management/spec.md#requirement-teacher-notes-live-in-a-store-only-staff-can-read
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Asserts a learner or guardian can never read a teacher note.
 */
class TeacherNoteLearnerAccessTest extends TestCase {

	/**
	 * A signed-in learner's groups, as OpenRegister sees them.
	 */
	private const LEARNER = ['learners', 'authenticated'];

	/**
	 * A signed-in guardian's groups.
	 */
	private const GUARDIAN = ['guardians', 'authenticated'];

	/**
	 * A signed-in teacher's groups.
	 */
	private const TEACHER = ['instructors', 'authenticated'];

	/**
	 * The shipped register's schemas.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemas(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);

		return $register['components']['schemas'];
	}//end schemas()

	/**
	 * Whether any rule of an action could admit a principal with these groups.
	 *
	 * @param array<int, mixed>  $rules  The action's rules.
	 * @param array<int, string> $groups The principal's groups.
	 *
	 * @return bool
	 */
	private static function admits(array $rules, array $groups): bool {
		foreach ($rules as $rule) {
			$group = $rule;
			if (is_array($rule) === true) {
				$group = ($rule['group'] ?? null);
			}

			if (is_string($group) === true && in_array($group, $groups, true) === true) {
				return true;
			}
		}

		return false;
	}//end admits()

	/**
	 * A learner and a guardian match no rule of any action on a teacher note.
	 *
	 * @return void
	 */
	public function testALearnerAndAGuardianCannotReachATeacherNote(): void {
		$authorization = ($this->schemas()['LessonTeacherNote']['authorization'] ?? null);
		self::assertIsArray($authorization, 'LessonTeacherNote must declare an authorization block, or the register cascade decides');

		foreach (['read', 'create', 'update', 'delete'] as $action) {
			$rules = ($authorization[$action] ?? null);
			self::assertIsArray($rules, $action . ' must be declared, not inherited');
			self::assertNotSame([], $rules, $action . ' must name staff, an empty list is not a decision');
			self::assertFalse(self::admits(rules: $rules, groups: self::LEARNER), 'a learner reaches ' . $action);
			self::assertFalse(self::admits(rules: $rules, groups: self::GUARDIAN), 'a guardian reaches ' . $action);
		}
	}//end testALearnerAndAGuardianCannotReachATeacherNote()

	/**
	 * No rule names the audiences a note must never reach, with or without a match.
	 *
	 * @return void
	 */
	public function testNoRuleNamesAnAudienceANoteMustNeverReach(): void {
		$authorization = $this->schemas()['LessonTeacherNote']['authorization'];
		$flat          = (string)json_encode($authorization);

		foreach (['authenticated', 'learners', 'guardians', 'public'] as $group) {
			self::assertStringNotContainsString('"' . $group . '"', $flat, $group . ' must not appear in the note authorization');
		}
	}//end testNoRuleNamesAnAudienceANoteMustNeverReach()

	/**
	 * Teachers read and write notes; the write groups are the lesson writers.
	 *
	 * @return void
	 */
	public function testTeachersReadAndWriteNotes(): void {
		$schemas       = $this->schemas();
		$authorization = $schemas['LessonTeacherNote']['authorization'];

		self::assertTrue(self::admits(rules: $authorization['read'], groups: self::TEACHER));
		self::assertTrue(self::admits(rules: $authorization['create'], groups: self::TEACHER));
		self::assertSame($schemas['Lesson']['authorization']['create'], $authorization['create'], 'note writers are the lesson writers');
		self::assertSame($authorization['create'], $authorization['update']);
		self::assertSame($authorization['create'], $authorization['delete']);
		self::assertFalse(($schemas['LessonTeacherNote']['x-openregister']['searchable'] ?? true), 'notes stay out of search');
	}//end testTeachersReadAndWriteNotes()

	/**
	 * The lesson itself is what a learner reads, so it must be unable to hold
	 * a note. Validated with the block type's own schema, the way
	 * OpenRegister validates a write.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/teacher-notes-protection/specs/course-management/spec.md#requirement-a-lesson-a-learner-can-read-cannot-hold-a-teacher-note
	 */
	public function testTheLessonALearnerReadsCannotHoldANote(): void {
		$lesson = $this->schemas()['Lesson'];
		self::assertTrue(self::admits(rules: $lesson['authorization']['read'], groups: self::LEARNER), 'precondition: a learner reads lessons');

		$blockType = $lesson['properties']['blocks']['items']['properties']['type'];
		$schema    = json_encode(['type' => 'string', 'enum' => $blockType['enum']]);
		$validator = new Validator();

		self::assertFalse($validator->validate('teacherNote', $schema)->isValid(), 'a lesson block of type teacherNote must be refused');
		self::assertTrue($validator->validate('richText', $schema)->isValid(), 'control: an ordinary block type passes');
	}//end testTheLessonALearnerReadsCannotHoldANote()
}//end class
