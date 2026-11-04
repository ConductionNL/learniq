<?php

/**
 * Learniq Common Cartridge manifest import characterization test.
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
 * @spec openspec/specs/course-management/spec.md#scenario-a-common-cartridge-package-materialises-its-course-structure
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\CommonCartridgeParser;
use OCA\Learniq\Service\CoursePackage\CommonCartridgeCourseImporter;
use OCA\Learniq\Service\CoursePackage\CommonCartridgeResourceRouter;
use OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter;
use OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter;
use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCA\Learniq\Service\CoursePackage\PackageContext;
use OCA\Learniq\Service\CoursePackage\PackageXmlValueReader;
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
use RuntimeException;

/**
 * Pins every call CommonCartridgeCourseImporter::importManifest() makes, its
 * returned Course id and its report entries, per manifest, against
 * tests/Unit/Service/golden/CommonCartridgeImport.json. With RECORD_GOLDEN=1
 * the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Service\CoursePackage\CommonCartridgeCourseImporter
 * @covers \OCA\Learniq\Service\CoursePackage\CommonCartridgeResourceRouter
 * @covers \OCA\Learniq\Service\CoursePackage\OrganizationWalk
 * @covers \OCA\Learniq\Service\CoursePackage\PackageContext
 * @covers \OCA\Learniq\Service\CoursePackage\ResourceSlot
 * @uses   \OCA\Learniq\Service\CoursePackage\CourseMetadataFilter
 * @uses   \OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter
 * @uses   \OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter
 * @uses   \OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter
 * @uses   \OCA\Learniq\Service\CoursePackage\PackageXmlValueReader
 * @uses   \OCA\Learniq\Service\QtiImportService
 * @uses   \OCA\Learniq\Service\QtiManifestScanner
 */
class CommonCartridgeImportGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/CommonCartridgeImport.json';

	/**
	 * Each scenario: the parsed manifest.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		$res  = static fn (string $id, string $class, ?string $href = null, string $type = 'webcontent') => [
			'identifier' => $id, 'type' => $type, 'title' => 'Title ' . $id, 'href' => $href, 'classification' => $class,
		];
		$node = static fn (string $id, ?string $parent, ?string $resource, bool $folder, int $order = 0) => [
			'identifier' => $id, 'title' => 'Node ' . $id, 'parentIdentifier' => $parent,
			'resourceIdentifier' => $resource, 'isFolder' => $folder, 'order' => $order,
		];
		return [
			'folders, leaf items and unreferenced resources' => [
				'organizationNodes' => [
					$node('F1', null, null, true),
					$node('F2', 'F1', null, true, 1),
					$node('I1', 'F2', 'R1', false, 2),
					$node('I2', 'F1', 'R2', false, 3),
					$node('I3', 'nowhere', 'R3', false, 4),
				],
				'resources' => [
					$res('R1', 'webcontent', 'page.html'),
					$res('R2', 'weblink', 'link.xml', 'imswl_xmlv1p1'),
					$res('R3', 'basiclti', null, 'imsbasiclti_xmlv1p0'),
					$res('R4', 'discussion', null, 'imsdt_xmlv1p1'),
					$res('R5', 'imsqti_item', 'q.xml', 'imsqti_xmlv1p2'),
					$res('R6', 'imsqti_test', 't.xml', 'imsqti_xmlv1p2/imscc_xmlv1p1/assessment'),
					$res('R7', 'other', null, 'associatedcontent/x'),
					$res('R8', 'webcontent', 'scorm.html', 'adlcp:SCORM'),
					$res('R9', 'webcontent', 'missing.html'),
				],
			],
			'leaf item without a folder makes an implicit course' => [
				'organizationNodes' => [$node('I1', null, 'R1', false, 5)],
				'resources' => [$res('R1', 'webcontent', 'page.html')],
			],
			'item referencing an unknown resource becomes a course' => [
				'organizationNodes' => [$node('I1', null, 'nope', false)],
				'resources' => [],
			],
			'weblinks: no href, unreadable descriptor, under no course' => [
				'organizationNodes' => [],
				'resources' => [
					$res('W1', 'weblink', null, 'imswl_xmlv1p1'),
					$res('W2', 'weblink', 'bad.xml', 'imswl_xmlv1p1'),
					$res('W3', 'webcontent', null),
				],
			],
			'a failing resource becomes a dropped entry' => [
				'organizationNodes' => [$node('F1', null, null, true), $node('IX', 'F1', 'BOOM', false, 1), $node('I2', 'F1', 'R2', false, 2)],
				'resources' => [$res('BOOM', 'webcontent', 'page.html'), $res('R2', 'basiclti', null, 'imsbasiclti_xmlv1p0')],
			],
		];
	}//end scenarios()

	/**
	 * Every scenario's calls, Course id and entries equal the recorded golden.
	 *
	 * @return void
	 */
	public function testEveryScenarioMakesTheRecordedCalls(): void {
		$dir = sys_get_temp_dir() . '/lq-cc-golden-' . bin2hex(random_bytes(4));
		mkdir($dir);
		file_put_contents($dir . '/page.html', '<p>page</p>');
		file_put_contents($dir . '/scorm.html', '<p>scorm</p>');
		file_put_contents($dir . '/link.xml', '<webLink><title>x</title><url href="https://example.org/target"/></webLink>');
		file_put_contents($dir . '/bad.xml', 'not xml <');

		$actual = [];
		foreach (self::scenarios() as $name => $manifest) {
			$actual[$name] = $this->callsFor($dir, $manifest);
		}

		array_map('unlink', glob($dir . '/*'));
		rmdir($dir);

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n";
		// The extraction directory and the generated course codes differ per run.
		$json = (string)preg_replace('/"IMPORT-[0-9a-f]{8}"/', '"IMPORT-(random)"', str_replace($dir, '(dir)', $json));
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		$this->assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testEveryScenarioMakesTheRecordedCalls()

	/**
	 * Import one manifest and return the calls it made, its Course id and its entries.
	 *
	 * @param string               $dir      The extracted package directory.
	 * @param array<string, mixed> $manifest The parsed manifest.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function callsFor(string $dir, array $manifest): array {
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
				if (($named['object']['name'] ?? '') === 'Node IX') {
					throw new RuntimeException('lesson store down');
				}

				return OrEntityFactory::make($named['object'], (string)$named['schema'], (string)$named['register'], 'saved-' . count($calls));
			}
		);

		$deleteParams = (new ReflectionMethod(ObjectService::class, 'deleteObject'))->getParameters();
		$service->method('deleteObject')->willReturnCallback(
			static function (...$args) use ($deleteParams, &$calls): bool {
				$named = [];
				foreach ($args as $i => $value) {
					if ($deleteParams[$i]->isDefaultValueAvailable() === false || $deleteParams[$i]->getDefaultValue() !== $value) {
						$named[$deleteParams[$i]->getName()] = $value;
					}
				}

				$calls[] = ['deleteObject' => $named];
				return true;
			}
		);

		$entries  = [];
		$courseId = $this->importer($service, $calls)->importManifest($manifest, new PackageContext($dir, 'teacher1', 't1'), $entries);
		$calls[]  = ['courseId' => $courseId];
		$calls[]  = ['entries' => $entries];
		return $calls;
	}//end callsFor()

	/**
	 * The Common Cartridge importer with real routing over the given ObjectService.
	 *
	 * @param ObjectService              $objectService The recording ObjectService mock.
	 * @param list<array<string, mixed>> $calls         The call log; nc:files calls are added to it.
	 *
	 * @return CommonCartridgeCourseImporter
	 */
	private function importer(ObjectService $objectService, array &$calls): CommonCartridgeCourseImporter {
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willThrowException(new NotFoundException('not found'));
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFolder')->willReturnCallback(
			function (string $path) use (&$calls) {
				$calls[] = ['newFolder' => $path];
				return $this->createMock(Folder::class);
			}
		);
		$folder->method('newFile')->willReturnCallback(
			function (string $path, $content = null) use (&$calls) {
				$calls[] = ['newFile' => [$path, $content]];
				return $this->createMock(File::class);
			}
		);
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
		return new CommonCartridgeCourseImporter(
			new QtiImportService($objectService, $logger),
			new CommonCartridgeParser(),
			new CommonCartridgeResourceRouter($objectWriter, $fileWriter, $reporter, new PackageXmlValueReader(), $logger),
			$objectWriter,
			$reporter,
		);
	}//end importer()
}//end class
