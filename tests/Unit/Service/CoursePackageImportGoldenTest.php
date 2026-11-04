<?php

/**
 * Learniq course package import characterization test.
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
 * @spec openspec/specs/course-management/spec.md#requirement-every-course-package-import-produces-a-coursepackageimportreport-naming-every-resources-outcome
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\CommonCartridgeParser;
use OCA\Learniq\Service\CoursePackage\CommonCartridgeCourseImporter;
use OCA\Learniq\Service\CoursePackage\CommonCartridgeResourceRouter;
use OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter;
use OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter;
use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCA\Learniq\Service\CoursePackage\LearniqJsonCourseImporter;
use OCA\Learniq\Service\CoursePackage\MoodleActivityRouter;
use OCA\Learniq\Service\CoursePackage\MoodleCourseImporter;
use OCA\Learniq\Service\CoursePackage\PackageXmlValueReader;
use OCA\Learniq\Service\CoursePackageImportService;
use OCA\Learniq\Service\MbzExtractor;
use OCA\Learniq\Service\MoodleBackupParser;
use OCA\Learniq\Service\MoodleQuizQuestionMapper;
use OCA\Learniq\Service\QtiImportService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Pins every saveObject call CoursePackageImportService::import() makes, and
 * the report it returns, per package, against
 * tests/Unit/Service/golden/CoursePackageImport.json. With RECORD_GOLDEN=1
 * the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Service\CoursePackageImportService
 * @covers \OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter
 * @covers \OCA\Learniq\Service\CoursePackage\LearniqJsonCourseImporter
 * @covers \OCA\Learniq\Service\CoursePackage\ImportOrigin
 * @covers \OCA\Learniq\Service\CoursePackage\ImportReportOutcome
 * @covers \OCA\Learniq\Service\CoursePackage\ArchivePackage
 * @uses   \OCA\Learniq\Service\CommonCartridgeParser
 * @uses   \OCA\Learniq\Service\CoursePackage\CommonCartridgeItemTree
 * @uses   \OCA\Learniq\Service\CoursePackage\CommonCartridgeCourseImporter
 * @uses   \OCA\Learniq\Service\CoursePackage\CommonCartridgeResourceRouter
 * @uses   \OCA\Learniq\Service\CoursePackage\CourseMetadataFilter
 * @uses   \OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter
 * @uses   \OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter
 * @uses   \OCA\Learniq\Service\CoursePackage\MoodleActivityRouter
 * @uses   \OCA\Learniq\Service\CoursePackage\OrganizationWalk
 * @uses   \OCA\Learniq\Service\CoursePackage\PackageContext
 * @uses   \OCA\Learniq\Service\CoursePackage\ResourceSlot
 * @uses   \OCA\Learniq\Service\CoursePackage\MoodleCourseImporter
 * @uses   \OCA\Learniq\Service\CoursePackage\PackageXmlValueReader
 * @uses   \OCA\Learniq\Service\MbzExtractor
 * @uses   \OCA\Learniq\Service\MoodleBackupParser
 * @uses   \OCA\Learniq\Service\MoodleQuizQuestionMapper
 * @uses   \OCA\Learniq\Service\QtiImportService
 * @uses   \OCA\Learniq\Service\QtiManifestScanner
 * @uses   \OCA\Learniq\Service\QtiPackageExtractor
 */
class CoursePackageImportGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/CoursePackageImport.json';
	private const FIXTURES = __DIR__ . '/../../fixtures/course-packages/';

	/**
	 * Each scenario: the package's file content (or a fixture name) and its upload filename.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function scenarios(): array {
		$tree = [
			'course' => ['name' => 'Physics 101', 'tenant_id' => 'old'],
			'lessons' => [['name' => 'Intro', 'order' => 1]],
			'materials' => [['title' => 'Notes', 'kind' => 'document', 'fileName' => 'notes.txt', 'contentBase64' => base64_encode('notes')]],
		];
		return [
			'common cartridge' => ['@minimal-cc.imscc', 'minimal-cc.imscc'],
			'moodle backup' => ['@minimal-moodle.mbz', 'minimal-moodle.mbz'],
			'unrecognised archive' => ['@corrupt.bin', 'corrupt.bin'],
			'zip without manifest' => ['@no-manifest.imscc', 'no-manifest.imscc'],
			'unsafe moodle backup' => ['@malicious-path-traversal.tar.gz', 'backup.mbz'],
			'json without course' => ['{"lessons": []}', 'export.json'],
			'json export' => [json_encode($tree), 'export.json'],
			'json export of every part' => [json_encode(self::fullTree()), 'export.json'],
		];
	}//end scenarios()

	/**
	 * An export with every part the importer knows, with and without ids and with and without optional fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function fullTree(): array {
		return [
			'course' => ['id' => 'source-course', 'name' => 'Physics 101'],
			'childCourses' => [['id' => 'source-child', 'name' => 'Module 1'], ['uuid' => 'source-child-2'], []],
			'lessons' => [['id' => 'source-lesson', 'name' => 'Intro', 'order' => 2, 'contentType' => 'video', 'contentRef' => 'ref'], []],
			'materials' => [['id' => 'source-material', 'title' => 'Link', 'kind' => 'url', 'url' => 'https://example.org'], ['title' => 'Notes', 'contentBase64' => base64_encode('notes')], []],
			'rubrics' => [['id' => 'source-rubric', 'name' => 'Rubric', 'criteria' => [['name' => 'c']], 'maxPoints' => 10], []],
			'assessments' => [['id' => 'source-assessment', 'title' => 'Exam'], []],
			'ltiPlacements' => [['id' => 'source-placement'], []],
		];
	}//end fullTree()

	/**
	 * Every scenario's calls and report equal the recorded golden.
	 *
	 * @return void
	 */
	public function testEveryScenarioMakesTheRecordedCalls(): void {
		$actual = [];
		foreach (self::scenarios() as $name => [$content, $filename]) {
			$actual[$name] = $this->callsFor($content, $filename);
		}

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n";
		// The timestamp, the extraction directory and the generated codes differ per run.
		$json = (string)preg_replace(
			['/"importedAt": "\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00"/', '/learniq_coursepkg_[0-9a-f]{16}/', '/"IMPORT-[0-9a-f]{8}"/'],
			['"importedAt": "(now)"', 'learniq_coursepkg_(tmp)', '"IMPORT-(random)"'],
			$json
		);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		$this->assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testEveryScenarioMakesTheRecordedCalls()

	/**
	 * Import one package and return the saves it made and its report.
	 *
	 * @param string $content  The package content, or `@<fixture>`.
	 * @param string $filename The upload filename.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function callsFor(string $content, string $filename): array {
		$path = self::FIXTURES . substr($content, 1);
		if (str_starts_with($content, '@') === false) {
			$path = tempnam(sys_get_temp_dir(), 'lq-golden-');
			file_put_contents($path, $content);
		}

		$calls   = [];
		$service = $this->createMock(ObjectService::class);
		$params   = (new ReflectionMethod(ObjectService::class, 'saveObject'))->getParameters();
		$service->method('saveObject')->willReturnCallback(
			static function (...$args) use ($params, &$calls) {
				$named = [];
				foreach ($args as $i => $value) {
					if ($params[$i]->isDefaultValueAvailable() === true && $params[$i]->getDefaultValue() === $value) {
						continue;
					}

					$named[$params[$i]->getName()] = $value;
				}

				$calls[] = ['saveObject' => $named];
				return OrEntityFactory::make($named['object'], (string)$named['schema'], (string)$named['register'], 'saved-' . count($calls));
			}
		);

		$report = $this->importService($service, $calls)->import($path, $filename, 'teacher1', 't1');
		unset($report['@self']);
		$calls[] = ['report' => $report];
		if (str_starts_with($content, '@') === false) {
			unlink($path);
		}

		return $calls;
	}//end callsFor()

	/**
	 * The import service with real parsers and importers over the given ObjectService.
	 *
	 * @param ObjectService              $objectService The recording ObjectService mock.
	 * @param list<array<string, mixed>> $calls         The call log; nc:files user folder lookups are added to it.
	 *
	 * @return CoursePackageImportService
	 */
	private function importService(ObjectService $objectService, array &$calls): CoursePackageImportService {
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willThrowException(new NotFoundException('not found'));
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFolder')->willReturn($this->createMock(Folder::class));
		$folder->method('newFile')->willReturn($this->createMock(File::class));
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturnCallback(
			static function (string $userId) use ($folder, &$calls) {
				$calls[] = ['getUserFolder' => $userId];
				return $folder;
			}
		);

		$logger       = new NullLogger();
		$objectWriter = new CoursePackageObjectWriter($objectService);
		$fileWriter   = new CoursePackageFileWriter($rootFolder, $logger);
		$reporter     = new CoursePackageImportReporter($objectService);
		$xmlReader    = new PackageXmlValueReader();
		$ccImporter   = new CommonCartridgeCourseImporter(
			new QtiImportService($objectService, $logger),
			new CommonCartridgeParser(),
			new CommonCartridgeResourceRouter($objectWriter, $fileWriter, $reporter, $xmlReader, $logger),
			$objectWriter,
			$reporter,
		);
		$moodle       = new MoodleCourseImporter(
			new MbzExtractor(),
			new MoodleBackupParser(),
			new MoodleActivityRouter(new MoodleQuizQuestionMapper(), $objectWriter, $fileWriter, $reporter, $xmlReader, $logger),
			$objectWriter,
			$reporter,
		);
		return new CoursePackageImportService(
			$ccImporter,
			$moodle,
			new LearniqJsonCourseImporter($objectWriter, $fileWriter, $reporter),
			$fileWriter,
			$reporter,
			$logger,
		);
	}//end importService()
}//end class
