<?php

/**
 * Unit tests for CourseMetadataFilter and the metadata a JSON import carries
 * onto the copy's course.
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
 * @spec openspec/changes/lesson-sharing-via-store-plane/tasks.md#task-2-install-as-a-copy-that-keeps-the-credit
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CourseStore;

use OCA\Learniq\Service\CoursePackage\CourseMetadataFilter;
use OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter;
use OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter;
use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCA\Learniq\Service\CoursePackage\LearniqJsonCourseImporter;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\CoursePackage\CourseMetadataFilter
 * @covers \OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter
 * @covers \OCA\Learniq\Service\CoursePackage\LearniqJsonCourseImporter
 * @uses   \OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter
 */
class CourseMetadataFilterTest extends TestCase {

	/**
	 * The filter's enums are the register's, so they cannot drift apart.
	 *
	 * @return void
	 */
	public function testTheEnumsMatchTheRegister(): void {
		$register   = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/learniq_register.json'), true);
		$properties = $register['components']['schemas']['Course']['properties'];

		self::assertSame($properties['level']['enum'], CourseMetadataFilter::LEVELS);
		self::assertSame($properties['license']['enum'], CourseMetadataFilter::LICENSES);
		self::assertSame($properties['educationalLevels']['items']['enum'], CourseMetadataFilter::EDUCATIONAL_LEVELS);
	}//end testTheEnumsMatchTheRegister()

	/**
	 * Valid values pass; invalid ones, and fields outside the list, are dropped.
	 *
	 * @return void
	 */
	public function testOnlyValidMetadataPasses(): void {
		$filtered = (new CourseMetadataFilter())->filter(
			[
				'level'             => 'vo',
				'license'           => 'CC-BY-SA-4.0',
				'language'          => 'nl',
				'author'            => ' Sectie Nederlands ',
				'subject'           => 'Nederlandse taal',
				'description'       => 'Schrijven',
				'educationalLevels' => ['havo', 'klas-3b', 'vwo', 'havo'],
				'tenant_id'         => 'tenant-other',
				'code'              => 'NE-H4',
			]
		);

		self::assertSame(
			[
				'level'             => 'vo',
				'license'           => 'CC-BY-SA-4.0',
				'language'          => 'nl',
				'description'       => 'Schrijven',
				'author'            => 'Sectie Nederlands',
				'subject'           => 'Nederlandse taal',
				'educationalLevels' => ['havo', 'vwo'],
			],
			$filtered
		);

		self::assertSame(
			[],
			(new CourseMetadataFilter())->filter(['level' => 'other', 'license' => 'GPL', 'language' => 'nld', 'educationalLevels' => ['x'], 'author' => ' '])
		);
	}//end testOnlyValidMetadataPasses()

	/**
	 * A learniq JSON import writes the source course's licence and author
	 * onto a new draft course with a new code.
	 *
	 * @return void
	 */
	public function testAJsonImportKeepsTheCredit(): void {
		$saved         = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			static function (array $object, ?array $extend=[], $register=null, $schema=null) use (&$saved) {
				$saved[] = ['schema' => (string)$schema, 'object' => $object];
				return OrEntityFactory::make($object, (string)$schema, 'learniq', (string)$schema . '-' . count($saved));
			}
		);

		$importer = new LearniqJsonCourseImporter(
			new CoursePackageObjectWriter($objectService),
			$this->createMock(CoursePackageFileWriter::class),
			new CoursePackageImportReporter($objectService)
		);

		$path = tempnam(sys_get_temp_dir(), 'learniq_meta_');
		file_put_contents(
			$path,
			(string)json_encode(
				[
					'course'  => ['name' => 'Betoog', 'code' => 'NE-H4', 'level' => 'vo', 'language' => 'nl', 'license' => 'CC-BY-SA-4.0', 'author' => 'Sectie Nederlands'],
					'lessons' => [],
				]
			)
		);
		$importer->importPackage($path, 'course-package-betoog-1.json', 'docent-07', '2026-09-27T10:00:00Z', 'tenant-1');
		unlink($path);

		$course = $saved[0]['object'];
		self::assertSame('course', $saved[0]['schema']);
		self::assertSame('CC-BY-SA-4.0', $course['license']);
		self::assertSame('Sectie Nederlands', $course['author']);
		self::assertSame('vo', $course['level']);
		self::assertSame('nl', $course['language']);
		self::assertSame('draft', $course['lifecycle']);
		self::assertSame('tenant-1', $course['tenant_id']);
		self::assertStringStartsWith('IMPORT-', $course['code']);
	}//end testAJsonImportKeepsTheCredit()
}//end class
