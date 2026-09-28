<?php

/**
 * Unit tests for CourseStoreDescriptor and CourseStoreRegistryObject: what
 * the store plane searches with, and what a registry stores.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\CourseStore
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lesson-sharing-via-store-plane/tasks.md#task-1-store-plane-descriptor-registry-object-and-sharedcoursepackage-schema
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CourseStore;

use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CourseStore\CourseStoreDescriptor;
use OCA\Learniq\Service\CourseStore\CourseStoreRegistryObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\CourseStore\CourseStoreDescriptor
 * @covers \OCA\Learniq\Service\CourseStore\CourseStoreRegistryObject
 */
class CourseStoreRegistryObjectTest extends TestCase {

	/**
	 * A share package as the sharing gate produces it.
	 *
	 * @return array<string, mixed>
	 */
	private function package(): array {
		return [
			'course'  => ['name' => 'Betoog schrijven, havo 4'],
			'lessons' => [['name' => 'Een betoog opbouwen']],
			'sharing' => [
				'title'             => 'Betoog schrijven, havo 4',
				'description'       => 'Twee lessen schrijfvaardigheid.',
				'license'           => 'CC-BY-SA-4.0',
				'author'            => 'Sectie Nederlands, OSG De Vaart',
				'subject'           => 'Nederlandse taal',
				'educationalLevels' => ['havo', 'vwo'],
				'language'          => 'nl',
				'goalsCovered'      => ['Een betoog opbouwen', 'Bronnen vermelden'],
				'lessonCount'       => 2,
				'sharedAt'          => '2026-09-27T10:00:00+00:00',
			],
		];
	}//end package()

	/**
	 * The descriptor names learniq, the schema, the register and the card fields.
	 *
	 * @return void
	 */
	public function testTheDescriptorNamesTheCourseStore(): void {
		$descriptor = (new CourseStoreDescriptor($this->createMock(ActionAuthService::class)))->descriptor();

		self::assertSame('learniq', $descriptor->appId);
		self::assertSame('shared-course-package', $descriptor->schema);
		self::assertSame('learniq', $descriptor->defaultRegister);
		foreach (['title', 'subject', 'level', 'goals', 'language', 'license', 'author'] as $field) {
			self::assertArrayHasKey($field, $descriptor->cardFields);
		}

		self::assertSame('cardLine', $descriptor->cardFields['typeName']);
		self::assertSame('author', $descriptor->cardFields['publisher']);
		self::assertFalse($descriptor->isFederated());
	}//end testTheDescriptorNamesTheCourseStore()

	/**
	 * Every property a card field maps to is a string on the registry object,
	 * because the store plane casts card properties to strings.
	 *
	 * @return void
	 */
	public function testEveryCardPropertyIsAString(): void {
		$object = (new CourseStoreRegistryObject())->build($this->package());

		foreach (CourseStoreDescriptor::CARD_FIELDS as $property) {
			self::assertIsString($object[$property], "$property must be a string.");
		}

		self::assertSame('havo, vwo', $object['level']);
		self::assertSame(['havo', 'vwo'], $object['levels']);
		self::assertSame('Een betoog opbouwen; Bronnen vermelden', $object['goals']);
		self::assertSame('Nederlandse taal · havo, vwo · CC-BY-SA-4.0', $object['cardLine']);
		self::assertSame('course-package', $object['kind']);
		self::assertSame(2, $object['lessonCount']);
		self::assertSame($this->package(), $object['package']);
	}//end testEveryCardPropertyIsAString()

	/**
	 * The slug is readable, differs per content and matches the store pattern.
	 *
	 * @return void
	 */
	public function testTheSlugIsReadableAndDiffersPerContent(): void {
		$builder    = new CourseStoreRegistryObject();
		$descriptor = new CourseStoreDescriptor($this->createMock(ActionAuthService::class));
		$slug       = $builder->slug($this->package());
		$other      = $builder->slug([...$this->package(), 'lessons' => []]);

		self::assertMatchesRegularExpression('/^course-package-betoog-schrijven-havo-4-[0-9a-f]{8}$/', $slug);
		self::assertNotSame($slug, $other);
		self::assertTrue($descriptor->isCourseSlug($slug));
		self::assertStringStartsWith('course-package-course-', $builder->slug(['sharing' => ['title' => '!!!']]));
	}//end testTheSlugIsReadableAndDiffersPerContent()

	/**
	 * Only course-package slugs count as course slugs.
	 *
	 * @return void
	 */
	public function testOnlyCoursePackageSlugsCount(): void {
		$descriptor = new CourseStoreDescriptor($this->createMock(ActionAuthService::class));

		self::assertFalse($descriptor->isCourseSlug('openregister-configset-owner-repo'));
		self::assertFalse($descriptor->isCourseSlug('course-package-'));
		self::assertFalse($descriptor->isCourseSlug('course-package-../etc'));
	}//end testOnlyCoursePackageSlugsCount()
}//end class
