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
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Imports a learniq-native JSON course tree: one object per course, child
 * course, lesson, material, rubric, assessment and LTI placement, each
 * reported with its own outcome; a file without a top-level course is a
 * failed report and writes nothing else.
 *
 * @covers \OCA\Learniq\Service\CoursePackage\LearniqJsonCourseImporter
 * @uses   \OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter
 * @uses   \OCA\Learniq\Service\CoursePackage\CourseMetadataFilter
 * @uses   \OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter
 * @uses   \OCA\Learniq\Service\FolderFileWriter
 * @uses   \OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter
 * @uses   \OCA\Learniq\Service\CoursePackage\ImportOrigin
 * @uses   \OCA\Learniq\Service\CoursePackage\ImportReportOutcome
 *
 * @spec openspec/specs/course-management/spec.md#scenario-exporting-a-course-produces-a-lossless-scholiq-native-json-tree
 */
class LearniqJsonCourseImporterTest extends TestCase {

	/**
	 * Objects saved, per schema, in order.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $saved = [];

	/**
	 * Files written into the importer's Nextcloud folder: path => content.
	 *
	 * @var array<string, string>
	 */
	private array $files = [];

	/**
	 * Temporary package files to remove.
	 *
	 * @var list<string>
	 */
	private array $temp = [];

	/**
	 * Remove the package files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->temp as $path) {
			@unlink($path);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * The importer over a recording object store and user folder.
	 *
	 * @return LearniqJsonCourseImporter
	 */
	private function importer(): LearniqJsonCourseImporter {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null) {
				$this->saved[(string)$schema][] = $object;
				return OrEntityFactory::make($object, (string)$schema, (string)$register, $schema . '-' . count($this->saved[(string)$schema]));
			}
		);

		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willThrowException(new NotFoundException('not found'));
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFolder')->willReturn($this->createMock(Folder::class));
		$folder->method('newFile')->willReturnCallback(
			function (string $path, string $content): File {
				$this->files[$path] = $content;
				return $this->createMock(File::class);
			}
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('docent')->willReturn($folder);

		return new LearniqJsonCourseImporter(
			new CoursePackageObjectWriter($objects),
			new CoursePackageFileWriter($root, new NullLogger()),
			new CoursePackageImportReporter($objects)
		);
	}//end importer()

	/**
	 * A package file holding the given text.
	 *
	 * @param string $content The file content.
	 *
	 * @return string The path.
	 */
	private function package(string $content): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'learniq-json-');
		file_put_contents($path, $content);
		$this->temp[] = $path;
		return $path;
	}//end package()

	/**
	 * Where the import came from.
	 *
	 * @return ImportOrigin
	 */
	private function origin(): ImportOrigin {
		return new ImportOrigin(sourceFilename: 'rekenen.json', importedBy: 'docent', importedAt: '2026-11-13T08:00:00+00:00', tenantId: 'school-1');
	}//end origin()

	/**
	 * Every part of the tree is written under the new course and reported.
	 *
	 * @return void
	 */
	public function testEveryPartOfTheTreeIsWrittenAndReported(): void {
		$tree = [
			'course' => ['id' => 'src-course', 'name' => 'Rekenen 3F'],
			'childCourses' => [['uuid' => 'src-mod', 'name' => 'Breuken'], []],
			'lessons' => [['id' => 'src-les', 'name' => 'Teller en noemer', 'order' => 2, 'contentType' => 'video', 'contentRef' => 'yt:abc'], []],
			'materials' => [
				['id' => 'src-mat', 'title' => 'Werkblad 1.pdf', 'kind' => 'worksheet', 'contentBase64' => base64_encode('%PDF-1.4')],
				['title' => 'Uitlegvideo', 'url' => 'https://video.example/uitleg'],
			],
			'rubrics' => [['id' => 'src-rub', 'name' => 'Toetsrubric', 'criteria' => [['name' => 'Juist']], 'maxPoints' => 10], []],
			'assessments' => [['id' => 'src-exam', 'title' => 'Eindtoets']],
			'ltiPlacements' => [['id' => 'src-lti']],
		];

		$this->importer()->importPackage($this->package((string)json_encode($tree)), $this->origin());

		self::assertSame('Rekenen 3F', $this->saved['course'][0]['name']);
		self::assertNull($this->saved['course'][0]['parentCourseId']);
		self::assertSame(['Breuken', 'course-1', 'school-1'], [$this->saved['course'][1]['name'], $this->saved['course'][1]['parentCourseId'], $this->saved['course'][1]['tenant_id']]);
		self::assertSame('Imported module', $this->saved['course'][2]['name']);

		self::assertSame(
			['courseId' => 'course-1', 'name' => 'Teller en noemer', 'order' => 2, 'contentType' => 'video', 'contentRef' => 'yt:abc', 'lifecycle' => 'draft', 'tenant_id' => 'school-1'],
			$this->saved['lesson'][0]
		);
		self::assertSame(['', 0, 'text', ''], [$this->saved['lesson'][1]['name'], $this->saved['lesson'][1]['order'], $this->saved['lesson'][1]['contentType'], $this->saved['lesson'][1]['contentRef']]);

		self::assertSame(['/Learniq/school-1/course-imports/Werkblad-1.pdf' => '%PDF-1.4'], array_combine(array_map(static fn (string $p): string => '/' . $p, array_keys($this->files)), array_values($this->files)));
		self::assertSame(
			['title' => 'Werkblad 1.pdf', 'kind' => 'worksheet', 'fileRef' => '/Learniq/school-1/course-imports/Werkblad-1.pdf', 'url' => null, 'courseId' => 'course-1', 'tenant_id' => 'school-1'],
			$this->saved['material'][0]
		);
		self::assertSame(['document', '', 'https://video.example/uitleg'], [$this->saved['material'][1]['kind'], $this->saved['material'][1]['fileRef'], $this->saved['material'][1]['url']]);

		self::assertSame(
			['name' => 'Toetsrubric', 'criteria' => [['name' => 'Juist']], 'maxPoints' => 10, 'lifecycle' => 'draft', 'tenant_id' => 'school-1'],
			$this->saved['rubric'][0]
		);
		self::assertSame(['name' => '', 'criteria' => [], 'maxPoints' => 100, 'lifecycle' => 'draft', 'tenant_id' => 'school-1'], $this->saved['rubric'][1]);
		self::assertSame(['title' => 'Eindtoets', 'courseId' => 'course-1', 'lifecycle' => 'draft', 'tenant_id' => 'school-1'], $this->saved['exam'][0]);
		self::assertSame('course-1', $this->saved['lti-tool-placement'][0]['courseId']);
		self::assertSame('', $this->saved['lti-tool-placement'][0]['openconnectorDeploymentId']);

		$report = $this->saved['course-package-import-report'][0];
		self::assertSame('scholiq-json', $report['sourceFormat']);
		self::assertSame('course-1', $report['courseId']);
		self::assertSame('partial', $report['lifecycle']);
		self::assertSame([11, 9, 2, 0], [$report['resourcesTotal'], $report['resourcesImported'], $report['resourcesDegraded'], $report['resourcesDropped']]);
		self::assertSame(
			[
				['src-course', 'course', 'imported', 'course-1'],
				['src-mod', 'course', 'imported', 'course-2'],
				['child-course', 'course', 'imported', 'course-3'],
				['src-les', 'lesson', 'imported', 'lesson-1'],
				['lesson', 'lesson', 'imported', 'lesson-2'],
				['src-mat', 'material', 'imported', 'material-1'],
				['material', 'material', 'imported', 'material-2'],
				['src-rub', 'rubric', 'imported', 'rubric-1'],
				['rubric', 'rubric', 'imported', 'rubric-2'],
				['src-exam', 'assessment', 'degraded', 'exam-1'],
				['src-lti', 'lti-tool-placement', 'degraded', 'lti-tool-placement-1'],
			],
			array_map(static fn (array $e): array => [$e['resourceIdentifier'], $e['resourceType'], $e['outcome'], $e['targetId']], $report['entries'])
		);
		self::assertStringContainsString('re-import the ItemBank via QTI package import', (string)$report['entries'][9]['reason']);
		self::assertStringContainsString('an admin must bind a deployment', (string)$report['entries'][10]['reason']);
		self::assertSame('LTI tool placement', $report['entries'][10]['title']);
		self::assertSame('school-1', $report['tenant_id']);
	}//end testEveryPartOfTheTreeIsWrittenAndReported()

	/**
	 * A course alone imports cleanly; an undecodable material is kept without a file.
	 *
	 * @return void
	 */
	public function testACourseWithAnUndecodableMaterialSucceedsWithoutAFile(): void {
		$tree = ['course' => ['name' => 'Taal'], 'materials' => [['title' => 'Kapot', 'contentBase64' => '***']]];

		$this->importer()->importPackage($this->package((string)json_encode($tree)), $this->origin());

		self::assertSame([], $this->files);
		self::assertSame('', $this->saved['material'][0]['fileRef']);
		$report = $this->saved['course-package-import-report'][0];
		self::assertSame('succeeded', $report['lifecycle']);
		self::assertSame('course', $report['entries'][0]['resourceIdentifier']);
		self::assertSame(2, $report['resourcesTotal']);
	}//end testACourseWithAnUndecodableMaterialSucceedsWithoutAFile()

	/**
	 * A file without a top-level course, or not JSON at all, is a failed report and nothing else.
	 *
	 * @return void
	 */
	public function testAFileWithoutACourseIsAFailedReport(): void {
		$importer = $this->importer();
		$importer->importPackage($this->package('{"lessons": []}'), $this->origin());
		$importer->importPackage($this->package('not json'), $this->origin());

		self::assertSame(['course-package-import-report'], array_keys($this->saved));
		foreach ($this->saved['course-package-import-report'] as $report) {
			self::assertSame('failed', $report['lifecycle']);
			self::assertNull($report['courseId']);
			self::assertSame([], $report['entries']);
			self::assertSame('File is not a valid scholiq-native course export (missing top-level "course" object).', $report['errorMessage']);
		}

		self::assertCount(2, $this->saved['course-package-import-report']);
	}//end testAFileWithoutACourseIsAFailedReport()
}//end class
