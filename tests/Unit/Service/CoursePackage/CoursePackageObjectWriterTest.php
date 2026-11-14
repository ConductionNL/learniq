<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CoursePackage;

use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests CoursePackageObjectWriter: the payload of every object a package
 * import creates in the `learniq` register, the UUID it reads back, and the
 * delete it forwards.
 *
 * @covers \OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter
 * @uses \OCA\Learniq\Service\CoursePackage\CourseMetadataFilter
 */
class CoursePackageObjectWriterTest extends TestCase {

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Fresh ObjectService double per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
	}//end setUp()

	/**
	 * A Course is a draft with import defaults and a fresh IMPORT code.
	 *
	 * @return void
	 */
	public function testCreateCourseWritesDraftWithDefaults(): void {
		$saved = $this->expectSave(schema: 'course', uuid: 'course-uuid');

		$uuid = $this->writer()->createCourse(title: 'Breuken', parentCourseId: 'parent-1', tenantId: 't-1');

		$this->assertSame('course-uuid', $uuid);
		$this->assertMatchesRegularExpression('/^IMPORT-[0-9a-f]{8}$/', $saved->object['code']);
		unset($saved->object['code']);
		$this->assertSame(
			[
				'name' => 'Breuken',
				'level' => 'other',
				'language' => 'en',
				'parentCourseId' => 'parent-1',
				'lifecycle' => 'draft',
				'tenant_id' => 't-1',
			],
			$saved->object
		);
	}//end testCreateCourseWritesDraftWithDefaults()

	/**
	 * Valid source metadata overrides the defaults; invalid values and keys
	 * the filter does not know are dropped, and lifecycle/code stay the
	 * import's own.
	 *
	 * @return void
	 */
	public function testCreateCourseKeepsOnlyValidSourceMetadata(): void {
		$saved = $this->expectSave(schema: 'course', uuid: 'course-uuid');

		$this->writer()->createCourse(
			title: 'Kopie',
			parentCourseId: null,
			tenantId: 't-2',
			metadata: [
				'level' => 'hbo',
				'language' => 'nl',
				'license' => 'not-a-licence',
				'author' => '  Anna  ',
				'educationalLevels' => ['vwo', 'bogus', 'vwo'],
				'lifecycle' => 'published',
				'code' => 'SRC-1',
			]
		);

		unset($saved->object['code']);
		$this->assertSame(
			[
				'name' => 'Kopie',
				'level' => 'hbo',
				'language' => 'nl',
				'parentCourseId' => null,
				'lifecycle' => 'draft',
				'tenant_id' => 't-2',
				'author' => 'Anna',
				'educationalLevels' => ['vwo'],
			],
			$saved->object
		);
	}//end testCreateCourseKeepsOnlyValidSourceMetadata()

	/**
	 * A Lesson is created as a draft from the lesson row.
	 *
	 * @return void
	 */
	public function testCreateLessonWritesDraftLesson(): void {
		$saved = $this->expectSave(schema: 'lesson', uuid: 'lesson-uuid');

		$uuid = $this->writer()->createLesson(
			lesson: ['courseId' => 'c-1', 'title' => 'Les 1', 'order' => 3, 'contentType' => 'html', 'contentRef' => '/f/x.html'],
			tenantId: 't-1'
		);

		$this->assertSame('lesson-uuid', $uuid);
		$this->assertSame(
			[
				'courseId' => 'c-1',
				'name' => 'Les 1',
				'order' => 3,
				'contentType' => 'html',
				'contentRef' => '/f/x.html',
				'lifecycle' => 'draft',
				'tenant_id' => 't-1',
			],
			$saved->object
		);
	}//end testCreateLessonWritesDraftLesson()

	/**
	 * A Material without a file reference stores an empty one.
	 *
	 * @return void
	 */
	public function testCreateMaterialDefaultsAMissingFileRefToEmpty(): void {
		$saved = $this->expectSave(schema: 'material', uuid: 'mat-uuid');

		$uuid = $this->writer()->createMaterial(
			material: ['title' => 'Link', 'kind' => 'link', 'fileRef' => null, 'url' => 'https://example.org', 'courseId' => null],
			tenantId: 't-1'
		);

		$this->assertSame('mat-uuid', $uuid);
		$this->assertSame(
			['title' => 'Link', 'kind' => 'link', 'fileRef' => '', 'url' => 'https://example.org', 'courseId' => null, 'tenant_id' => 't-1'],
			$saved->object
		);
	}//end testCreateMaterialDefaultsAMissingFileRefToEmpty()

	/**
	 * A Material keeps the file reference it was given.
	 *
	 * @return void
	 */
	public function testCreateMaterialKeepsAGivenFileRef(): void {
		$saved = $this->expectSave(schema: 'material', uuid: 'mat-uuid');

		$this->writer()->createMaterial(
			material: ['title' => 'Sheet', 'kind' => 'document', 'fileRef' => '/Learniq/sheet.pdf', 'url' => null, 'courseId' => 'c-1'],
			tenantId: 't-1'
		);

		$this->assertSame('/Learniq/sheet.pdf', $saved->object['fileRef']);
		$this->assertNull($saved->object['url']);
	}//end testCreateMaterialKeepsAGivenFileRef()

	/**
	 * An LTI placement is created unbound, as a draft resource-link.
	 *
	 * @return void
	 */
	public function testCreateLtiPlacementWritesAnUnboundDraft(): void {
		$saved = $this->expectSave(schema: 'lti-tool-placement', uuid: 'lti-uuid');

		$uuid = $this->writer()->createLtiPlacement(courseId: 'c-1', tenantId: 't-1');

		$this->assertSame('lti-uuid', $uuid);
		$this->assertSame(
			[
				'openconnectorDeploymentId' => '',
				'launchMode' => 'resource-link',
				'courseId' => 'c-1',
				'lifecycle' => 'draft',
				'tenant_id' => 't-1',
			],
			$saved->object
		);
	}//end testCreateLtiPlacementWritesAnUnboundDraft()

	/**
	 * An item bank is a named draft.
	 *
	 * @return void
	 */
	public function testCreateItemBankWritesANamedDraft(): void {
		$saved = $this->expectSave(schema: 'item-bank', uuid: 'bank-uuid');

		$uuid = $this->writer()->createItemBank(name: 'Imported items', tenantId: 't-1');

		$this->assertSame('bank-uuid', $uuid);
		$this->assertSame(['name' => 'Imported items', 'tenant_id' => 't-1', 'lifecycle' => 'draft'], $saved->object);
	}//end testCreateItemBankWritesANamedDraft()

	/**
	 * A generic create passes the payload through unchanged.
	 *
	 * @return void
	 */
	public function testCreatePassesThePayloadThrough(): void {
		$saved = $this->expectSave(schema: 'rubric', uuid: 'rubric-uuid');

		$uuid = $this->writer()->create(schema: 'rubric', object: ['name' => 'R', 'maxPoints' => 10]);

		$this->assertSame('rubric-uuid', $uuid);
		$this->assertSame(['name' => 'R', 'maxPoints' => 10], $saved->object);
	}//end testCreatePassesThePayloadThrough()

	/**
	 * A saved object without a uuid resolves to null.
	 *
	 * @return void
	 */
	public function testCreateReturnsNullWhenTheSavedObjectHasNoUuid(): void {
		$this->expectSave(schema: 'exam', uuid: null);

		$this->assertNull($this->writer()->create(schema: 'exam', object: ['title' => 'X']));
	}//end testCreateReturnsNullWhenTheSavedObjectHasNoUuid()

	/**
	 * Delete forwards uuid, register and schema to OpenRegister.
	 *
	 * @return void
	 */
	public function testDeleteForwardsToTheLearniqRegister(): void {
		$this->objectService->expects($this->once())
			->method('deleteObject')
			->with('obj-1', 'learniq', 'lesson')
			->willReturn(true);

		$this->writer()->delete(schema: 'lesson', uuid: 'obj-1');
	}//end testDeleteForwardsToTheLearniqRegister()

	/**
	 * The writer under test.
	 *
	 * @return CoursePackageObjectWriter
	 */
	private function writer(): CoursePackageObjectWriter {
		return new CoursePackageObjectWriter($this->objectService);
	}//end writer()

	/**
	 * Expect exactly one save into the learniq register under $schema, and
	 * capture its payload.
	 *
	 * @param string      $schema Expected schema slug.
	 * @param string|null $uuid   UUID of the returned entity.
	 *
	 * @return \stdClass Holder whose `object` property receives the payload.
	 */
	private function expectSave(string $schema, ?string $uuid): \stdClass {
		$holder = new \stdClass();
		$holder->object = null;
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function (array $object, ?array $extend = [], $register = null, $savedSchema = null) use ($holder, $schema, $uuid) {
					$this->assertSame('learniq', $register);
					$this->assertSame($schema, $savedSchema);
					$holder->object = $object;
					return OrEntityFactory::make($object, $schema, 'learniq', $uuid);
				}
			);

		return $holder;
	}//end expectSave()
}//end class
