<?php

/**
 * Unit tests for the office-file-lesson-onboarding register delta.
 *
 * Pins the LessonOnboardingFile schema (lifecycle, notification, self-only
 * access, the fields the listener and the importer write) and that a teacher note
 * is no longer a block type on Lesson.blocks (teacher-notes-protection).
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
 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the office-file-lesson-onboarding schema delta.
 */
class LessonOnboardingRegisterTest extends TestCase {

	/**
	 * Decoded register configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Load the register configuration once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$raw = file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json');
		$this->assertNotFalse($raw);
		$decoded = json_decode($raw, true);
		$this->assertIsArray($decoded);
		$this->config = $decoded;

	}//end setUp()

	/**
	 * The schema.
	 *
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		$schema = ($this->config['components']['schemas']['LessonOnboardingFile'] ?? null);
		$this->assertIsArray($schema, 'LessonOnboardingFile must be declared');
		return $schema;

	}//end schema()

	/**
	 * The schema is registered, versioned and carries what the listener writes.
	 *
	 * @return void
	 */
	public function testTheSchemaCarriesTheDetectionFields(): void {
		$schema = $this->schema();
		$this->assertSame('lesson-onboarding-file', $schema['slug']);
		$this->assertSame('0.1.0', $schema['version']);
		$this->assertContains('lesson-onboarding-file', $this->config['components']['registers']['learniq']['schemas']);
		$this->assertSame(['teacherId', 'fileId', 'fileName', 'format', 'tenant_id'], $schema['required']);
		$this->assertSame(['docx', 'pptx'], $schema['properties']['format']['enum']);
		$this->assertSame('Course', $schema['properties']['courseId']['$ref']);
		$this->assertSame('Lesson', $schema['properties']['lessonId']['$ref']);
		foreach ($schema['properties'] as $name => $property) {
			$this->assertArrayHasKey('title', $property, $name . ' needs a title');
			$this->assertArrayHasKey('description', $property, $name . ' needs a description');
		}

		$this->assertArrayNotHasKey('appendOnly', $schema, 'a schema with a lifecycle is not append-only');

	}//end testTheSchemaCarriesTheDetectionFields()

	/**
	 * Detected, then imported with its inputs, or dismissed and restorable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-nothing-is-extracted-until-the-teacher-confirms-one-file-on-the-review-page
	 */
	public function testTheLifecycleRunsFromDetectedToImportedOrDismissed(): void {
		$lifecycle = $this->schema()['x-openregister-lifecycle'];
		$this->assertSame('detected', $lifecycle['initial']);
		$this->assertSame(['from' => 'detected', 'to' => 'dismissed'], $lifecycle['transitions']['dismiss']);
		$this->assertSame(['from' => 'dismissed', 'to' => 'detected'], $lifecycle['transitions']['restore']);
		$import = $lifecycle['transitions']['import'];
		$this->assertSame('detected', $import['from']);
		$this->assertSame('imported', $import['to']);
		$this->assertSame(['lessonId', 'courseId', 'importNote'], array_column($import['inputs'], 'field'));
		foreach (array_column($import['inputs'], 'field') as $field) {
			$this->assertArrayHasKey($field, $this->schema()['properties']);
		}

	}//end testTheLifecycleRunsFromDetectedToImportedOrDismissed()

	/**
	 * A created row notifies the teacher it is about, with a link to the review page.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-new-word-or-powerpoint-file-in-the-folder-is-detected-and-the-teacher-is-notified-and-nothing-is-read
	 */
	public function testACreatedRowNotifiesTheTeacherWithALinkToTheReviewPage(): void {
		$notification = $this->schema()['x-openregister-notifications']['detected'];
		$this->assertSame(['type' => 'created'], $notification['trigger']);
		$this->assertTrue($notification['enabled']);
		$this->assertSame(['nc-notification'], $notification['channels']);
		$this->assertSame([['kind' => 'field', 'field' => 'teacherId']], $notification['recipients']);
		$this->assertArrayHasKey('nl', $notification['subject']);
		$this->assertArrayHasKey('en', $notification['subject']);
		$this->assertSame(
			['kind' => 'route', 'app' => 'learniq', 'route' => 'course-packages/import'],
			$notification['actions'][0]['target']
		);

	}//end testACreatedRowNotifiesTheTeacherWithALinkToTheReviewPage()

	/**
	 * Only the teacher reads their rows, and updates them only before import.
	 *
	 * @return void
	 */
	public function testOnlyTheTeacherReadsAndUpdatesTheirRows(): void {
		$authorization = $this->schema()['authorization'];
		$self = ['group' => 'authenticated', 'match' => ['teacherId' => '$userId']];
		$this->assertSame([$self], $authorization['read']);
		$this->assertSame(['instructors'], $authorization['create']);
		$this->assertSame(
			[['group' => 'authenticated', 'match' => ['teacherId' => '$userId', 'lifecycle' => ['$in' => ['detected', 'dismissed']]]]],
			$authorization['update']
		);
		$this->assertArrayNotHasKey('delete', $authorization);

	}//end testOnlyTheTeacherReadsAndUpdatesTheirRows()

	/**
	 * A teacher note is no longer a Lesson block: office-file-lesson-onboarding
	 * added the teacherNote type, and teacher-notes-protection moved notes to
	 * the staff-only LessonTeacherNote schema, because every signed-in user
	 * reads a Lesson. Lesson's version moved with it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-lesson-a-learner-can-read-cannot-hold-a-teacher-note
	 */
	public function testTeacherNotesAreNotLessonBlocks(): void {
		$lesson = $this->config['components']['schemas']['Lesson'];
		$this->assertNotContains('teacherNote', $lesson['properties']['blocks']['items']['properties']['type']['enum']);
		$this->assertTrue(version_compare($lesson['version'], '0.5.0', '>='));
		$this->assertTrue(version_compare($this->config['info']['version'], '0.25.0', '>='));
		$this->assertStringContainsString('office-file-lesson-onboarding', $this->config['info']['description']);

	}//end testTeacherNotesAreNotLessonBlocks()
}//end class
