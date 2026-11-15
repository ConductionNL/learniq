<?php

/**
 * The lessons a SCORM import writes, validated against the shipped Lesson schema.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Service\CoursePackage
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/content-scorm-package-upload/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CoursePackage;

use OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter;
use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCA\Learniq\Service\CoursePackage\ImportOrigin;
use OCA\Learniq\Service\CoursePackage\ImportReportOutcome;
use OCA\Learniq\Service\CoursePackage\ScormCourseImporter;
use OCA\Learniq\Service\CoursePackage\ScormManifestParser;
use OCA\Learniq\Service\CoursePackage\ScormPackageUnpacker;
use OCA\Learniq\Service\FolderFileWriter;
use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use OCA\Learniq\Tests\Support\SaveCallShape;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ZipArchive;

/**
 * The importer runs over the real object writer; only OpenRegister's
 * ObjectService and Nextcloud's file tree are doubles. Every lesson payload
 * that reaches `saveObject()` must fit the Lesson schema as shipped in
 * learniq_register.json, so a field the schema does not know or a value its
 * enum refuses fails here instead of at the first real upload.
 *
 * @spec openspec/changes/content-scorm-package-upload/specs/course-management/spec.md#requirement-the-package-import-accepts-a-scorm-zip
 */
#[CoversClass(ScormCourseImporter::class)]
#[UsesClass(CoursePackageObjectWriter::class)]
#[UsesClass(ScormManifestParser::class)]
#[UsesClass(ScormPackageUnpacker::class)]
#[UsesClass(FolderFileWriter::class)]
#[UsesClass(ImportOrigin::class)]
#[UsesClass(ImportReportOutcome::class)]
#[UsesClass(CoursePackageImportReporter::class)]
class ScormLessonPayloadSchemaTest extends TestCase {
	use RegisterSchemaPayloads;

	private const TENANT = '11111111-1111-4111-8111-111111111111';

	/**
	 * Each object saved, as [schema, payload].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * The zips made for a test.
	 *
	 * @var list<string>
	 */
	private array $zips = [];

	/**
	 * Remove the zips.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->zips as $zip) {
			@unlink($zip);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * A zip holding the fixture manifest and one launch file.
	 *
	 * @param string $fixture The manifest fixture under tests/fixtures/scorm/.
	 *
	 * @return string The zip's path.
	 */
	private function zip(string $fixture): string {
		$path = tempnam(sys_get_temp_dir(), 'scorm') . '.zip';
		$zip  = new ZipArchive();
		$zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		$zip->addFromString('imsmanifest.xml', (string)file_get_contents(__DIR__ . '/../../../fixtures/scorm/' . $fixture));
		$zip->addFromString('index.html', '<html>SCO</html>');
		$zip->close();
		$this->zips[] = $path;

		return $path;
	}//end zip()

	/**
	 * The importer over the real object writer, with a recording ObjectService.
	 *
	 * @return ScormCourseImporter
	 */
	private function importer(): ScormCourseImporter {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			SaveCallShape::legacy(function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null): ObjectEntity {
				$this->saved[] = [(string)$schema, $object];
				$entity = new ObjectEntity();
				$entity->setUuid(sprintf('22222222-2222-4222-8222-%012d', count($this->saved)));
				return $entity;
			})
		);

		$reporter = $this->getMockBuilder(CoursePackageImportReporter::class)->disableOriginalConstructor()->onlyMethods(['persistReport'])->getMock();
		$reporter->method('persistReport')->willReturnCallback(
			static fn (ImportOrigin $origin, ImportReportOutcome $outcome): array => [
				'sourceFormat' => $outcome->sourceFormat,
				'courseId' => $outcome->courseId,
				'entries' => $outcome->entries,
			]
		);

		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willThrowException(new NotFoundException());
		$folder->method('nodeExists')->willReturn(false);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);

		return new ScormCourseImporter(
			new ScormManifestParser(),
			new CoursePackageObjectWriter($objectService),
			$reporter,
			new ScormPackageUnpacker($root, $reporter, new NullLogger())
		);
	}//end importer()

	/**
	 * The lesson payloads saved so far.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function lessons(): array {
		return array_values(array_map(static fn (array $s): array => $s[1], array_filter($this->saved, static fn (array $s): bool => $s[0] === 'lesson')));
	}//end lessons()

	/**
	 * Import a fixture as a tenant's user.
	 *
	 * @param string $fixture The manifest fixture.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function import(string $fixture): array {
		$origin = new ImportOrigin(sourceFilename: 'package.zip', importedBy: 'anna', importedAt: '2026-11-14T10:00:00Z', tenantId: self::TENANT);

		return $this->importer()->importPackage(packagePath: $this->zip(fixture: $fixture), origin: $origin);
	}//end import()

	/**
	 * Every lesson of a three-SCO 1.2 package fits the Lesson schema.
	 *
	 * @return void
	 */
	public function testEveryScorm12LessonFitsTheShippedLessonSchema(): void {
		$this->import(fixture: 'three-scos-12.xml');

		$lessons = $this->lessons();
		$this->assertCount(3, $lessons);
		foreach ($lessons as $lesson) {
			$this->assertNull(self::schemaError(slug: 'lesson', payload: $lesson), json_encode($lesson));
			$this->assertSame('scorm12', $lesson['contentType']);
			$this->assertSame(self::TENANT, $lesson['tenant_id']);
		}

		$this->assertSame([1, 2, 3], array_column($lessons, 'order'));
	}//end testEveryScorm12LessonFitsTheShippedLessonSchema()

	/**
	 * A SCORM 2004 lesson fits it too, with the 2004 content type.
	 *
	 * @return void
	 */
	public function testAScorm2004LessonFitsTheShippedLessonSchema(): void {
		$this->import(fixture: 'single-sco-2004.xml');

		$lessons = $this->lessons();
		$this->assertCount(1, $lessons);
		$this->assertSame('scorm2004', $lessons[0]['contentType']);
		$this->assertNull(self::schemaError(slug: 'lesson', payload: $lessons[0]), json_encode($lessons[0]));
	}//end testAScorm2004LessonFitsTheShippedLessonSchema()

	/**
	 * The lesson points at its course and at a launch file in the course's SCORM folder.
	 *
	 * @return void
	 */
	public function testTheLessonNamesItsCourseAndItsLaunchFile(): void {
		$this->import(fixture: 'single-sco-12.xml');

		$courses = array_values(array_filter($this->saved, static fn (array $s): bool => $s[0] === 'course'));
		$this->assertCount(1, $courses);
		$lesson = $this->lessons()[0];
		$this->assertSame('22222222-2222-4222-8222-000000000001', $lesson['courseId']);
		$this->assertSame('/Learniq/' . self::TENANT . '/22222222-2222-4222-8222-000000000001/scorm/index.html', $lesson['contentRef']);
		$this->assertSame('Fire safety basics', $lesson['name']);
	}//end testTheLessonNamesItsCourseAndItsLaunchFile()

	/**
	 * The check itself refuses a lesson the schema does not allow.
	 *
	 * @return void
	 */
	public function testTheSchemaCheckRefusesAnUnknownContentType(): void {
		$this->import(fixture: 'single-sco-12.xml');

		$lesson = $this->lessons()[0];
		$lesson['contentType'] = 'scorm3';
		$this->assertNotNull(self::schemaError(slug: 'lesson', payload: $lesson));
	}//end testTheSchemaCheckRefusesAnUnknownContentType()
}//end class
