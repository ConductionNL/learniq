<?php

/**
 * Tests for what a failed Common Cartridge webcontent import leaves behind.
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
 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\CoursePackage\CommonCartridgeResourceRouter;
use OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter;
use OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter;
use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCA\Learniq\Service\CoursePackage\CoursePackageOutput;
use OCA\Learniq\Service\CoursePackage\PackageContext;
use OCA\Learniq\Service\CoursePackage\PackageXmlValueReader;
use OCA\Learniq\Service\CoursePackage\ResourceSlot;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A webcontent resource whose Lesson cannot be saved leaves no Material behind.
 *
 * @covers \OCA\Learniq\Service\CoursePackage\CommonCartridgeResourceRouter
 * @uses   \OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter
 * @uses   \OCA\Learniq\Service\CoursePackage\CoursePackageOutput
 * @uses   \OCA\Learniq\Service\CoursePackage\PackageContext
 * @uses   \OCA\Learniq\Service\CoursePackage\ResourceSlot
 */
class CommonCartridgeResourceRouterTest extends TestCase {

	/**
	 * Route one webcontent resource whose Lesson save throws.
	 *
	 * @param CoursePackageObjectWriter $writer The object writer double.
	 * @param LoggerInterface           $logger The logger double.
	 * @param string|null               $saved  The Material UUID the save answers.
	 *
	 * @return array<string, mixed> The report entry.
	 */
	private function routeWithFailingLesson(CoursePackageObjectWriter $writer, LoggerInterface $logger, ?string $saved = 'mat-1'): array {
		$writer->method('createMaterial')->willReturn($saved);
		$writer->method('createLesson')->willThrowException(new RuntimeException('lesson store down'));
		$files = $this->createMock(CoursePackageFileWriter::class);
		$files->method('resolveFileRef')->willReturn('/import/page.html');

		$router = new CommonCartridgeResourceRouter(
			output: new CoursePackageOutput(
				objectWriter: $writer,
				fileWriter: $files,
				reporter: new CoursePackageImportReporter(objectService: $this->createMock(ObjectService::class))
			),
			xmlReader: $this->createMock(PackageXmlValueReader::class),
			logger: $logger
		);

		return $router->routeResource(
			slot: new ResourceSlot(
				resource: ['identifier' => 'R1', 'type' => 'webcontent', 'title' => 'Page', 'href' => 'page.html', 'classification' => 'webcontent'],
				courseId: 'course-1',
				lessonOrder: 1,
				lessonTitle: 'Page'
			),
			package: new PackageContext(dir: '/tmp/pkg', importedBy: 'teacher1', tenantId: 't1')
		);
	}//end routeWithFailingLesson()

	/**
	 * The Material is removed and the resource is reported dropped with the Lesson's failure.
	 *
	 * @return void
	 */
	public function testTheMaterialOfAFailedLessonIsRemoved(): void {
		$writer = $this->createMock(CoursePackageObjectWriter::class);
		$writer->expects($this->once())->method('delete')->with('material', 'mat-1');

		$entry = $this->routeWithFailingLesson(writer: $writer, logger: $this->createMock(LoggerInterface::class));

		$this->assertSame('dropped', $entry['outcome']);
		$this->assertSame('Import failed: lesson store down', $entry['reason']);
	}//end testTheMaterialOfAFailedLessonIsRemoved()

	/**
	 * A Material save that answered no UUID has nothing to remove.
	 *
	 * @return void
	 */
	public function testNoRemovalWithoutAMaterialId(): void {
		$writer = $this->createMock(CoursePackageObjectWriter::class);
		$writer->expects($this->never())->method('delete');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$entry = $this->routeWithFailingLesson(writer: $writer, logger: $logger, saved: null);

		$this->assertSame('Import failed: lesson store down', $entry['reason']);
	}//end testNoRemovalWithoutAMaterialId()

	/**
	 * A failed removal is logged, and the report still carries the Lesson's failure.
	 *
	 * @return void
	 */
	public function testAFailedRemovalIsLogged(): void {
		$writer = $this->createMock(CoursePackageObjectWriter::class);
		$writer->method('delete')->willThrowException(new RuntimeException('delete refused'));
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$logged): void {
				$logged[] = [$message, $context['msg'] ?? null];
			}
		);

		$entry = $this->routeWithFailingLesson(writer: $writer, logger: $logger);

		$this->assertSame('Import failed: lesson store down', $entry['reason']);
		$this->assertSame(
			[
				['[CommonCartridgeResourceRouter] Material {id} of a dropped resource could not be removed: {msg}', 'delete refused'],
				['[CommonCartridgeResourceRouter] Resource {id} failed to import: {msg}', 'lesson store down'],
			],
			$logged
		);
	}//end testAFailedRemovalIsLogged()
}//end class
