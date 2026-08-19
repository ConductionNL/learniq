<?php

/**
 * Scholiq course-package round-trip smoke test.
 *
 * Exports a seeded course as scholiq-native JSON via CoursePackageExportService,
 * re-imports it via CoursePackageImportService, and diffs the resulting object
 * graph against the source — the lossless round-trip design.md's format-support
 * matrix promises for the scholiq-native JSON format.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/course-management/spec.md#requirement-export-a-full-course-as-common-cartridge-and-scholiq-native-json-with-resolved-file-attachments
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Service\CommonCartridgeParser;
use OCA\Learniq\Service\CoursePackage\CommonCartridgeCourseImporter;
use OCA\Learniq\Service\CoursePackage\CommonCartridgeResourceRouter;
use OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter;
use OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter;
use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCA\Learniq\Service\CoursePackage\MoodleActivityRouter;
use OCA\Learniq\Service\CoursePackage\MoodleCourseImporter;
use OCA\Learniq\Service\CoursePackage\PackageXmlValueReader;
use OCA\Learniq\Service\CoursePackage\ScholiqJsonCourseImporter;
use OCA\Learniq\Service\CoursePackageExportService;
use OCA\Learniq\Service\CoursePackageImportService;
use OCA\Learniq\Service\MbzExtractor;
use OCA\Learniq\Service\MoodleBackupParser;
use OCA\Learniq\Service\MoodleQuizQuestionMapper;
use OCA\Learniq\Service\QtiExportService;
use OCA\Learniq\Service\QtiImportService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Round-trip smoke test: export → re-import → diff.
 */
class CoursePackageRoundTripTest extends TestCase {

	/**
	 * Export a seeded Course as scholiq-native JSON, re-import it, and assert
	 * the re-created object graph reproduces the source Course/Lesson/
	 * Material/Rubric shapes (design.md's lossless round-trip promise).
	 *
	 * @return void
	 */
	public function testScholiqJsonExportReimportsToAnEquivalentObjectGraph(): void {
		// --- Seed source object graph (mocked ObjectService, export side). ---
		$exportObjectService = $this->createMock(ObjectService::class);
		$exportObjectService->method('find')->willReturnCallback(
			static function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) {
				$row = match ([$schema, $id]) {
					['course', 'course-source'] => ['id' => 'course-source', 'name' => 'Physics 101', 'tenant_id' => 't1'],
					['rubric', 'rubric-source'] => ['id' => 'rubric-source', 'name' => 'Essay rubric', 'criteria' => [], 'maxPoints' => 20],
					default => null,
				};

				if ($row === null) {
					return null;
				}

				return OrEntityFactory::make($row, (string)$schema);
			}
		);
		$exportObjectService->method('findAll')->willReturnCallback(
			static function (array $config): array {
				return match ($config['schema']) {
					'course' => [],
					'lesson' => [['id' => 'lesson-source', 'name' => 'Introduction', 'order' => 1, 'contentType' => 'text', 'contentRef' => 'material-source', 'courseId' => 'course-source']],
					'material' => [['id' => 'material-source', 'title' => 'Syllabus', 'kind' => 'document', 'fileRef' => '/Scholiq/materials/syllabus.pdf', 'courseId' => 'course-source']],
					'assessment' => [],
					'assignment' => [['id' => 'assignment-source', 'title' => 'Essay', 'rubricId' => 'rubric-source', 'courseId' => 'course-source']],
					'lti-tool-placement' => [],
					default => [],
				};
			}
		);

		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturnCallback(
			function (string $path) {
				if ($path === 'Scholiq/materials/syllabus.pdf') {
					$node = $this->createMock(File::class);
					$node->method('getContent')->willReturn('SYLLABUS-BYTES');
					return $node;
				}

				throw new NotFoundException($path);
			}
		);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($folder);

		$qtiExportService = $this->createMock(QtiExportService::class);
		$exportService = new CoursePackageExportService($exportObjectService, $qtiExportService, $rootFolder, new NullLogger());
		$json = $exportService->exportScholiqJson('course-source', 'teacher1');

		// --- Re-import the exported JSON into a fresh object graph. ---
		$savedByschema = [];
		$importObjectService = $this->createMock(ObjectService::class);
		$importObjectService->method('saveObject')->willReturnCallback(
			static function (array $object, ?array $extend = [], $register = null, $schema = null) use (&$savedByschema) {
				$schemaSlug = (string)$schema;

				$savedByschema[$schemaSlug] ??= [];
				$savedByschema[$schemaSlug][] = $object;

				return OrEntityFactory::make(
					$object,
					$schemaSlug,
					(string)$register,
					$schemaSlug . '-reimported-' . count($savedByschema[$schemaSlug])
				);
			}
		);

		$importFolder = $this->createMock(Folder::class);
		$importFolder->method('get')->willThrowException(new NotFoundException('not found'));
		$importFolder->method('nodeExists')->willReturn(false);
		$importFolder->method('newFolder')->willReturn($this->createMock(Folder::class));
		$importFolder->method('newFile')->willReturn($this->createMock(File::class));
		$importRootFolder = $this->createMock(IRootFolder::class);
		$importRootFolder->method('getUserFolder')->willReturn($importFolder);

		$tmpJsonFile = tempnam(sys_get_temp_dir(), 'scholiq_roundtrip_');
		file_put_contents($tmpJsonFile, $json);

		$importLogger = new NullLogger();
		$objectWriter = new CoursePackageObjectWriter($importObjectService);
		$fileWriter = new CoursePackageFileWriter($importRootFolder, $importLogger);
		$reporter = new CoursePackageImportReporter($importObjectService);
		$xmlReader = new PackageXmlValueReader();

		$importService = new CoursePackageImportService(
			new CommonCartridgeCourseImporter(
				new QtiImportService($importObjectService, $importLogger),
				new CommonCartridgeParser(),
				new CommonCartridgeResourceRouter($objectWriter, $fileWriter, $reporter, $xmlReader, $importLogger),
				$objectWriter,
				$reporter,
			),
			new MoodleCourseImporter(
				new MbzExtractor(),
				new MoodleBackupParser(),
				new MoodleActivityRouter(
					new MoodleQuizQuestionMapper(),
					$objectWriter,
					$fileWriter,
					$reporter,
					$xmlReader,
					$importLogger
				),
				$objectWriter,
				$reporter,
			),
			new ScholiqJsonCourseImporter($objectWriter, $fileWriter, $reporter),
			$fileWriter,
			$reporter,
			$importLogger,
		);

		$report = $importService->import($tmpJsonFile, 'course-export.json', 'teacher1', 't1');
		unlink($tmpJsonFile);

		// --- Diff: the re-created object graph reproduces the source shapes. ---
		self::assertSame('scholiq-json', $report['sourceFormat']);
		self::assertNotSame('failed', $report['lifecycle']);
		self::assertNotNull($report['courseId']);

		self::assertSame('Physics 101', $savedByschema['course'][0]['name']);
		self::assertSame('Introduction', $savedByschema['lesson'][0]['name']);
		self::assertSame('Syllabus', $savedByschema['material'][0]['title']);
		self::assertSame('Essay rubric', $savedByschema['rubric'][0]['name']);
		self::assertSame(20, $savedByschema['rubric'][0]['maxPoints']);

		// The Material's file bytes were resolved on export (base64) and written
		// back into nc:files on import — never referencing a path the recipient
		// tenant cannot resolve.
		self::assertNotNull($savedByschema['material'][0]['fileRef']);
		self::assertNotSame('', $savedByschema['material'][0]['fileRef']);
	}//end testScholiqJsonExportReimportsToAnEquivalentObjectGraph()
}//end class
