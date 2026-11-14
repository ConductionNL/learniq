<?php

/**
 * Unit tests for ScormCourseImporter.
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
 * @spec openspec/changes/content-scorm-package-upload/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CoursePackage;

use OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter;
use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCA\Learniq\Service\CoursePackage\ImportOrigin;
use OCA\Learniq\Service\CoursePackage\ImportReportOutcome;
use OCA\Learniq\Service\CoursePackage\ScormCourseImporter;
use OCA\Learniq\Service\CoursePackage\ScormManifestParser;
use OCA\Learniq\Service\FolderFileWriter;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ZipArchive;

/**
 * One course, one lesson per SCO in manifest order, the package unpacked into
 * the course folder, escaping entries dropped and SCORM 2004 degraded.
 *
 * @spec openspec/changes/content-scorm-package-upload/specs/course-management/spec.md#requirement-the-package-import-accepts-a-scorm-zip
 */
#[CoversClass(ScormCourseImporter::class)]
#[UsesClass(ScormManifestParser::class)]
#[UsesClass(FolderFileWriter::class)]
#[UsesClass(ImportOrigin::class)]
#[UsesClass(ImportReportOutcome::class)]
#[UsesClass(CoursePackageImportReporter::class)]
class ScormCourseImporterTest extends TestCase {

	/**
	 * Each lesson created.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $lessons = [];

	/**
	 * Each file written: path => content.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

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
	 * A zip holding these entries.
	 *
	 * @param array<string, string> $entries Name => content.
	 *
	 * @return string The zip's path.
	 */
	private function zip(array $entries): string {
		$path = tempnam(sys_get_temp_dir(), 'scorm') . '.zip';
		$zip  = new ZipArchive();
		$zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		foreach ($entries as $name => $content) {
			$zip->addFromString($name, $content);
		}

		$zip->close();
		$this->zips[] = $path;

		return $path;
	}//end zip()

	/**
	 * A fixture manifest.
	 *
	 * @param string $name The fixture.
	 *
	 * @return string
	 */
	private static function manifest(string $name): string {
		return (string)file_get_contents(__DIR__ . '/../../../fixtures/scorm/' . $name);
	}//end manifest()

	/**
	 * The importer over recorded boundaries; the report is answered unsaved.
	 *
	 * @return ScormCourseImporter
	 */
	private function importer(): ScormCourseImporter {
		$objects = $this->createMock(CoursePackageObjectWriter::class);
		$objects->method('createCourse')->willReturn('course-1');
		$objects->method('createLesson')->willReturnCallback(
			function (array $lesson): string {
				$this->lessons[] = $lesson;
				return 'lesson-' . count($this->lessons);
			}
		);

		$reporter = $this->getMockBuilder(CoursePackageImportReporter::class)->disableOriginalConstructor()->onlyMethods(['persistReport'])->getMock();
		$reporter->method('persistReport')->willReturnCallback(
			static fn (ImportOrigin $origin, ImportReportOutcome $outcome): array => [
				'sourceFormat' => $outcome->sourceFormat,
				'courseId' => $outcome->courseId,
				'entries' => $outcome->entries,
				'errorMessage' => $outcome->errorMessage,
			]
		);

		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willThrowException(new NotFoundException());
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willReturnCallback(
			function (string $path, $content = null) {
				$this->written[$path] = (string)$content;
				return null;
			}
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('anna')->willReturn($folder);

		return new ScormCourseImporter(new ScormManifestParser(), $objects, $reporter, $root, new NullLogger());
	}//end importer()

	/**
	 * @return ImportOrigin
	 */
	private static function origin(): ImportOrigin {
		return new ImportOrigin(sourceFilename: 'brandveiligheid.zip', importedBy: 'anna', importedAt: '2026-11-14T10:00:00Z', tenantId: 'tenant-1');
	}//end origin()

	/**
	 * A single-SCO 1.2 zip: one scorm12 lesson pointing at the unpacked index.html, reported imported.
	 *
	 * @return void
	 */
	public function testASingleScoPackageBecomesOneScorm12Lesson(): void {
		$zip = $this->zip(['imsmanifest.xml' => self::manifest(name: 'single-sco-12.xml'), 'index.html' => '<html>SCO</html>']);

		$this->assertTrue($this->importer()->isScormPackage(packagePath: $zip));
		$report = $this->importer()->importPackage(packagePath: $zip, origin: self::origin());

		$this->assertSame('scorm', $report['sourceFormat']);
		$this->assertSame('course-1', $report['courseId']);
		$this->assertSame(
			[['courseId' => 'course-1', 'title' => 'Fire safety basics', 'order' => 1, 'contentType' => 'scorm12', 'contentRef' => '/Learniq/tenant-1/course-1/scorm/index.html']],
			$this->lessons
		);
		$this->assertSame('<html>SCO</html>', $this->written['Learniq/tenant-1/course-1/scorm/index.html']);
		$this->assertSame([['item-1', 'sco', 'imported', 'lesson-1']], array_map(static fn (array $e): array => [$e['resourceIdentifier'], $e['resourceType'], $e['outcome'], $e['targetId']], $report['entries']));
	}//end testASingleScoPackageBecomesOneScorm12Lesson()

	/**
	 * Three SCOs become three lessons in manifest order.
	 *
	 * @return void
	 */
	public function testThreeScosBecomeThreeLessonsInOrder(): void {
		$zip = $this->zip(['imsmanifest.xml' => self::manifest(name: 'three-scos-12.xml')]);

		$this->importer()->importPackage(packagePath: $zip, origin: self::origin());

		$this->assertSame([1, 2, 3], array_column($this->lessons, 'order'));
		$this->assertSame(['What is personal data', 'Lawful grounds', 'Data breaches'], array_column($this->lessons, 'title'));
	}//end testThreeScosBecomeThreeLessonsInOrder()

	/**
	 * A SCORM 2004 SCO is a scorm2004 lesson, reported degraded with the reason.
	 *
	 * @return void
	 */
	public function testAScorm2004ScoIsDegradedWithTheReason(): void {
		$zip    = $this->zip(['imsmanifest.xml' => self::manifest(name: 'single-sco-2004.xml')]);
		$report = $this->importer()->importPackage(packagePath: $zip, origin: self::origin());

		$this->assertSame('scorm2004', $this->lessons[0]['contentType']);
		$this->assertSame('degraded', $report['entries'][0]['outcome']);
		$this->assertSame(ScormCourseImporter::NOT_PLAYED, $report['entries'][0]['reason']);
	}//end testAScorm2004ScoIsDegradedWithTheReason()

	/**
	 * An entry with `..` or an absolute path is not written and is reported dropped.
	 *
	 * @return void
	 */
	public function testAnEscapingEntryIsDroppedAndNotWritten(): void {
		$zip    = $this->zip(['imsmanifest.xml' => self::manifest(name: 'single-sco-12.xml'), 'index.html' => 'ok', '../../config.php' => '<?php evil();', 'assets/../../x.js' => 'x']);
		$report = $this->importer()->importPackage(packagePath: $zip, origin: self::origin());

		$dropped = array_values(array_filter($report['entries'], static fn (array $e): bool => $e['outcome'] === 'dropped'));
		$this->assertSame(['../../config.php', 'assets/../../x.js'], array_column($dropped, 'resourceIdentifier'));
		foreach (array_keys($this->written) as $path) {
			$this->assertStringStartsWith('Learniq/tenant-1/course-1/scorm/', $path);
			$this->assertStringNotContainsString('..', $path);
		}

		$this->assertFalse(ScormCourseImporter::staysInside(name: '/etc/passwd'));
		$this->assertFalse(ScormCourseImporter::staysInside(name: 'C:\\x.dll'));
		$this->assertTrue(ScormCourseImporter::staysInside(name: 'assets/sco.js'));
	}//end testAnEscapingEntryIsDroppedAndNotWritten()

	/**
	 * A zip without a SCORM manifest is not a SCORM package.
	 *
	 * @return void
	 */
	public function testAZipWithoutAScormManifestIsNotScorm(): void {
		$this->assertFalse($this->importer()->isScormPackage(packagePath: $this->zip(['readme.txt' => 'hi'])));
		$this->assertFalse($this->importer()->isScormPackage(packagePath: $this->zip(['imsmanifest.xml' => '<manifest xmlns="http://www.imsglobal.org/xsd/imsccv1p3/imscp_v1p1"/>'])));
	}//end testAZipWithoutAScormManifestIsNotScorm()
}//end class
