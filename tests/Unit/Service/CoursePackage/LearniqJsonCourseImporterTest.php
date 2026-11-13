<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CoursePackage;

use OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter;
use OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter;
use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCA\Learniq\Service\CoursePackage\ImportOrigin;
use OCA\Learniq\Service\CoursePackage\LearniqJsonCourseImporter;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * Tests LearniqJsonCourseImporter::importPackage(): the objects a
 * scholiq-native JSON export re-creates, the Material bytes written back to
 * nc:files, and the import report it persists.
 *
 * The object writer, file writer and reporter are real, built over a mocked
 * ObjectService and a mocked IRootFolder.
 *
 * @covers \OCA\Learniq\Service\CoursePackage\LearniqJsonCourseImporter
 * @uses \OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter
 * @uses \OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter
 * @uses \OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter
 * @uses \OCA\Learniq\Service\CoursePackage\CourseMetadataFilter
 * @uses \OCA\Learniq\Service\CoursePackage\ImportOrigin
 * @uses \OCA\Learniq\Service\CoursePackage\ImportReportOutcome
 * @uses \OCA\Learniq\Service\FolderFileWriter
 */
class LearniqJsonCourseImporterTest extends TestCase {

	private const REPORT_SCHEMA = 'course-package-import-report';

	/**
	 * Every saveObject() call: schema and payload, in order.
	 *
	 * @var list<array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * Every call on the user folders, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $fileCalls = [];

	/**
	 * Temp directory of the current test.
	 *
	 * @var string
	 */
	private string $dir = '';

	/**
	 * Fresh recorders and temp directory per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saved = [];
		$this->fileCalls = [];
		$this->dir = sys_get_temp_dir() . '/learniq-ljci-' . uniqid('', true);
		mkdir($this->dir, 0777, true);
	}//end setUp()

	/**
	 * Remove the temp directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ((array)glob($this->dir . '/*') as $file) {
			@unlink((string)$file);
		}

		@rmdir($this->dir);
	}//end tearDown()

	/**
	 * A file that is not JSON persists a failed report and creates nothing else.
	 *
	 * @return void
	 */
	public function testInvalidJsonPersistsAFailedReport(): void {
		$report = $this->importer()->importPackage(packagePath: $this->write('not json {'), origin: $this->origin());

		$this->assertSame([self::REPORT_SCHEMA], array_column($this->saved, 'schema'));
		$this->assertSame(
			[
				'sourceFormat' => 'scholiq-json',
				'sourceFilename' => 'export.json',
				'courseId' => null,
				'importedBy' => 'anna',
				'importedAt' => '2026-01-01T00:00:00+00:00',
				'lifecycle' => 'failed',
				'resourcesTotal' => 0,
				'resourcesImported' => 0,
				'resourcesDegraded' => 0,
				'resourcesDropped' => 0,
				'errorMessage' => 'File is not a valid scholiq-native course export (missing top-level "course" object).',
				'entries' => [],
				'tenant_id' => 't-1',
			],
			$this->saved[0]['object']
		);
		$this->assertSame('failed', $report['lifecycle']);
		$this->assertSame(self::REPORT_SCHEMA . '-1', $report['id']);
		$this->assertSame([], $this->fileCalls);
	}//end testInvalidJsonPersistsAFailedReport()

	/**
	 * Valid JSON without a top-level `course` object is rejected the same way.
	 *
	 * @return void
	 */
	public function testJsonWithoutACourseIsRejected(): void {
		$report = $this->importer()->importPackage(
			packagePath: $this->write((string)json_encode(['lessons' => [['name' => 'L']]])),
			origin: $this->origin()
		);

		$this->assertSame([self::REPORT_SCHEMA], array_column($this->saved, 'schema'));
		$this->assertSame('failed', $report['lifecycle']);
		$this->assertStringContainsString('missing top-level "course" object', (string)$report['errorMessage']);
	}//end testJsonWithoutACourseIsRejected()

	/**
	 * A bare course re-creates one Course from its metadata, reported under
	 * the fallback identifier, and the report succeeds.
	 *
	 * @return void
	 */
	public function testBareCourseSucceeds(): void {
		$report = $this->importer()->importPackage(
			packagePath: $this->write((string)json_encode(['course' => ['level' => 'mbo', 'license' => 'CC-BY-4.0']])),
			origin: $this->origin()
		);

		$this->assertSame(['course', self::REPORT_SCHEMA], array_column($this->saved, 'schema'));
		$course = $this->saved[0]['object'];
		$this->assertSame('Imported course', $course['name']);
		$this->assertNull($course['parentCourseId']);
		$this->assertSame('mbo', $course['level']);
		$this->assertSame('CC-BY-4.0', $course['license']);
		$this->assertSame('t-1', $course['tenant_id']);

		$this->assertSame('succeeded', $report['lifecycle']);
		$this->assertSame('course-1', $report['courseId']);
		$this->assertNull($report['errorMessage']);
		$this->assertSame(1, $report['resourcesTotal']);
		$this->assertSame(1, $report['resourcesImported']);
		$this->assertSame(
			[
				[
					'resourceIdentifier' => 'course',
					'resourceType' => 'course',
					'title' => '',
					'outcome' => 'imported',
					'targetType' => 'course',
					'targetId' => 'course-1',
					'reason' => null,
				],
			],
			$report['entries']
		);
	}//end testBareCourseSucceeds()

	/**
	 * A full export re-creates every kind of object under the new Course,
	 * writes base64 Material bytes into nc:files, and reports Assessments and
	 * LTI placements as degraded, so the report is partial.
	 *
	 * @return void
	 */
	public function testFullTreeReCreatesEveryObjectAndReportsPartial(): void {
		$tree = [
			'course' => ['id' => 'src-course', 'name' => 'Rekenen', 'language' => 'nl'],
			'childCourses' => [
				['uuid' => 'src-child', 'name' => 'Module A'],
				[],
			],
			'lessons' => [
				['id' => 'src-lesson', 'name' => 'Les 1', 'order' => 2, 'contentType' => 'html', 'contentRef' => 'ref-1'],
				[],
			],
			'materials' => [
				['id' => 'src-mat', 'title' => 'Werkblad 1.pdf', 'kind' => 'document', 'contentBase64' => base64_encode('PDF')],
				['title' => 'Broken', 'contentBase64' => '***'],
				['title' => 'Link', 'kind' => 'link', 'url' => 'https://example.org'],
			],
			'rubrics' => [
				['id' => 'src-rubric', 'name' => 'Rubric', 'criteria' => [['c' => 1]], 'maxPoints' => 20],
				[],
			],
			'assessments' => [
				['id' => 'src-exam', 'title' => 'Toets'],
			],
			'ltiPlacements' => [
				['id' => 'src-lti'],
			],
		];

		$report = $this->importer()->importPackage(packagePath: $this->write((string)json_encode($tree)), origin: $this->origin());

		$this->assertSame(
			[
				'course',
				'course',
				'course',
				'lesson',
				'lesson',
				'material',
				'material',
				'material',
				'rubric',
				'rubric',
				'exam',
				'lti-tool-placement',
				self::REPORT_SCHEMA,
			],
			array_column($this->saved, 'schema')
		);
		$objects = array_column($this->saved, 'object');

		$this->assertSame('Rekenen', $objects[0]['name']);
		$this->assertSame('nl', $objects[0]['language']);
		$this->assertSame(['Module A', 'course-1'], [$objects[1]['name'], $objects[1]['parentCourseId']]);
		$this->assertSame(['Imported module', 'course-1'], [$objects[2]['name'], $objects[2]['parentCourseId']]);
		// A child course does not inherit the source metadata.
		$this->assertSame('en', $objects[1]['language']);

		$this->assertSame(
			['courseId' => 'course-1', 'name' => 'Les 1', 'order' => 2, 'contentType' => 'html', 'contentRef' => 'ref-1', 'lifecycle' => 'draft', 'tenant_id' => 't-1'],
			$objects[3]
		);
		$this->assertSame(
			['courseId' => 'course-1', 'name' => '', 'order' => 0, 'contentType' => 'text', 'contentRef' => '', 'lifecycle' => 'draft', 'tenant_id' => 't-1'],
			$objects[4]
		);

		$this->assertSame(
			['title' => 'Werkblad 1.pdf', 'kind' => 'document', 'fileRef' => '/Learniq/t-1/course-imports/Werkblad-1.pdf', 'url' => null, 'courseId' => 'course-1', 'tenant_id' => 't-1'],
			$objects[5]
		);
		$this->assertSame(
			['title' => 'Broken', 'kind' => 'document', 'fileRef' => '', 'url' => null, 'courseId' => 'course-1', 'tenant_id' => 't-1'],
			$objects[6]
		);
		$this->assertSame(
			['title' => 'Link', 'kind' => 'link', 'fileRef' => '', 'url' => 'https://example.org', 'courseId' => 'course-1', 'tenant_id' => 't-1'],
			$objects[7]
		);

		$this->assertSame(
			['name' => 'Rubric', 'criteria' => [['c' => 1]], 'maxPoints' => 20, 'lifecycle' => 'draft', 'tenant_id' => 't-1'],
			$objects[8]
		);
		$this->assertSame(['name' => '', 'criteria' => [], 'maxPoints' => 100, 'lifecycle' => 'draft', 'tenant_id' => 't-1'], $objects[9]);
		$this->assertSame(['title' => 'Toets', 'courseId' => 'course-1', 'lifecycle' => 'draft', 'tenant_id' => 't-1'], $objects[10]);
		$this->assertSame('course-1', $objects[11]['courseId']);
		$this->assertSame('', $objects[11]['openconnectorDeploymentId']);

		// Only the valid base64 content reaches nc:files, in the importer's folder.
		$this->assertSame(
			[
				['getUserFolder' => 'anna'],
				['newFolder' => 'Learniq'],
				['newFolder' => 'Learniq/t-1'],
				['newFolder' => 'Learniq/t-1/course-imports'],
				['newFile' => ['Learniq/t-1/course-imports/Werkblad-1.pdf', 'PDF']],
			],
			$this->fileCalls
		);

		$this->assertSame(
			[
				['src-course', 'course', 'Rekenen', 'imported', 'course', 'course-1'],
				['src-child', 'course', 'Module A', 'imported', 'course', 'course-2'],
				['child-course', 'course', '', 'imported', 'course', 'course-3'],
				['src-lesson', 'lesson', 'Les 1', 'imported', 'lesson', 'lesson-1'],
				['lesson', 'lesson', '', 'imported', 'lesson', 'lesson-2'],
				['src-mat', 'material', 'Werkblad 1.pdf', 'imported', 'material', 'material-1'],
				['material', 'material', 'Broken', 'imported', 'material', 'material-2'],
				['material', 'material', 'Link', 'imported', 'material', 'material-3'],
				['src-rubric', 'rubric', 'Rubric', 'imported', 'rubric', 'rubric-1'],
				['rubric', 'rubric', '', 'imported', 'rubric', 'rubric-2'],
				['src-exam', 'assessment', 'Toets', 'degraded', 'assessment', 'exam-1'],
				['src-lti', 'lti-tool-placement', 'LTI tool placement', 'degraded', 'lti-tool-placement', 'lti-tool-placement-1'],
			],
			array_map(
				static fn (array $e): array => [$e['resourceIdentifier'], $e['resourceType'], $e['title'], $e['outcome'], $e['targetType'], $e['targetId']],
				$report['entries']
			)
		);
		$reasons = array_column($report['entries'], 'reason');
		$this->assertSame(array_fill(0, 10, null), array_slice($reasons, 0, 10));
		$this->assertSame(
			'Item references are source-tenant UUIDs and do not resolve here; re-import the ItemBank via QTI package import to relink.',
			$reasons[10]
		);
		$this->assertSame(
			'LTI placement re-created without a configured OpenConnector deployment; an admin must bind a deployment before this tool can be launched.',
			$reasons[11]
		);

		$this->assertSame('partial', $report['lifecycle']);
		$this->assertSame('course-1', $report['courseId']);
		$this->assertSame(12, $report['resourcesTotal']);
		$this->assertSame(10, $report['resourcesImported']);
		$this->assertSame(2, $report['resourcesDegraded']);
		$this->assertSame(0, $report['resourcesDropped']);
	}//end testFullTreeReCreatesEveryObjectAndReportsPartial()

	/**
	 * The importer under test, over a recording ObjectService and root folder.
	 *
	 * @return LearniqJsonCourseImporter
	 */
	private function importer(): LearniqJsonCourseImporter {
		$counters = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null) use (&$counters) {
				$schema = (string)$schema;
				$this->assertSame('learniq', $register);
				$this->saved[] = ['schema' => $schema, 'object' => $object];
				$counters[$schema] = ($counters[$schema] ?? 0) + 1;

				return OrEntityFactory::make($object, $schema, 'learniq', $schema . '-' . $counters[$schema]);
			}
		);

		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willThrowException(new NotFoundException('missing'));
		$folder->method('newFolder')->willReturnCallback(
			function (string $path): Folder {
				$this->fileCalls[] = ['newFolder' => $path];
				return $this->createMock(Folder::class);
			}
		);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willReturnCallback(
			function (string $path, $content = null): File {
				$this->fileCalls[] = ['newFile' => [$path, $content]];
				return $this->createMock(File::class);
			}
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $uid) use ($folder): Folder {
				$this->fileCalls[] = ['getUserFolder' => $uid];
				return $folder;
			}
		);

		return new LearniqJsonCourseImporter(
			new CoursePackageObjectWriter($objectService),
			new CoursePackageFileWriter($root, new CapturingLogger()),
			new CoursePackageImportReporter($objectService),
		);
	}//end importer()

	/**
	 * The import origin used throughout.
	 *
	 * @return ImportOrigin
	 */
	private function origin(): ImportOrigin {
		return new ImportOrigin('export.json', 'anna', '2026-01-01T00:00:00+00:00', 't-1');
	}//end origin()

	/**
	 * Write a package file into the temp directory.
	 *
	 * @param string $content File content.
	 *
	 * @return string Its path.
	 */
	private function write(string $content): string {
		$path = $this->dir . '/export.json';
		file_put_contents($path, $content);
		return $path;
	}//end write()
}//end class
