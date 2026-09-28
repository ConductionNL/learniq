<?php

/**
 * Unit tests for the `timetabling-lesson-note` register declaration.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/timetabling-lesson-note/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the LessonNote schema: its two lesson keys, the audience, and a
 * read line that keeps learners out of the object API.
 */
class LessonNoteRegisterTest extends TestCase {

	/**
	 * The LessonNote schema.
	 *
	 * @var array<string, mixed>
	 */
	private array $schema;

	/**
	 * The register's schema slug list.
	 *
	 * @var array<int, string>
	 */
	private array $listed;

	/**
	 * Load the register once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$config = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$this->schema = $config['components']['schemas']['LessonNote'];
		$this->listed = $config['components']['registers']['learniq']['schemas'];
	}//end setUp()

	/**
	 * The schema is registered under its slug.
	 *
	 * @return void
	 */
	public function testSchemaIsListed(): void {
		self::assertSame('lesson-note', $this->schema['slug']);
		self::assertContains('lesson-note', $this->listed);
	}//end testSchemaIsListed()

	/**
	 * A note names its lesson by a learniq Session or a planninq reference, and
	 * always carries the cohort, the text and the audience.
	 *
	 * @return void
	 */
	public function testLessonKeysAndRequiredFields(): void {
		$props = $this->schema['properties'];

		self::assertSame('Session', $props['sessionId']['$ref']);
		self::assertTrue($props['sessionId']['nullable']);
		self::assertSame(['sourceSystem', 'externalRef'], array_keys($props['timetableSessionRef']['properties']));
		self::assertSame(['cohortId', 'text', 'audience', 'tenant_id'], $this->schema['required']);
		// The server stamps the author (LessonNoteAuthorGuard), so a client never has to send it.
		self::assertArrayHasKey('authorId', $props);
		self::assertSame(120, $props['topic']['maxLength']);
		self::assertSame(2000, $props['text']['maxLength']);
	}//end testLessonKeysAndRequiredFields()

	/**
	 * The audience is for learners or for the covering teacher, with a label for each.
	 *
	 * @return void
	 */
	public function testAudience(): void {
		$audience = $this->schema['properties']['audience'];

		self::assertSame(['learners', 'cover'], $audience['enum']);
		self::assertSame(['learners', 'cover'], array_keys($audience['x-enum-labels']));
	}//end testAudience()

	/**
	 * Learners have no direct read: they read notes through the timetable endpoint.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-lesson-note/specs/personal-timetable/spec.md#requirement-learners-see-a-lessons-note-in-their-timetable
	 */
	public function testLearnersHaveNoDirectRead(): void {
		$auth = $this->schema['authorization'];

		self::assertSame(['instructors', 'team-leads', 'compliance-officers'], $auth['read']);
		self::assertNotContains('learners', $auth['read']);
		self::assertNotContains('authenticated', $auth['read']);
		self::assertSame($auth['create'], $auth['update']);
	}//end testLearnersHaveNoDirectRead()

	/**
	 * Every property carries a title and a description.
	 *
	 * @return void
	 */
	public function testEveryPropertyHasTitleAndDescription(): void {
		foreach ($this->schema['properties'] as $name => $prop) {
			self::assertArrayHasKey('title', $prop, "LessonNote.{$name} missing title");
			self::assertArrayHasKey('description', $prop, "LessonNote.{$name} missing description");
		}
	}//end testEveryPropertyHasTitleAndDescription()
}//end class
