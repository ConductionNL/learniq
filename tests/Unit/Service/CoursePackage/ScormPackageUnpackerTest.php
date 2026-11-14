<?php

/**
 * Unit tests for ScormPackageUnpacker.
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
use OCA\Learniq\Service\CoursePackage\ScormPackageUnpacker;
use OCA\Learniq\Service\FolderFileWriter;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * Entries inside the package root are written under the course folder;
 * escaping ones are dropped, folder entries skipped, write failures logged.
 *
 * @spec openspec/changes/content-scorm-package-upload/specs/course-management/spec.md#requirement-the-import-report-is-honest-about-what-does-not-play
 */
#[CoversClass(ScormPackageUnpacker::class)]
#[UsesClass(FolderFileWriter::class)]
#[UsesClass(CoursePackageImportReporter::class)]
class ScormPackageUnpackerTest extends TestCase {

	/**
	 * Each file written: path => content.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * The zip made for a test.
	 *
	 * @var string|null
	 */
	private ?string $zipPath = null;

	/**
	 * Remove the zip.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ($this->zipPath !== null) {
			@unlink($this->zipPath);
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
		$this->zipPath = tempnam(sys_get_temp_dir(), 'scormunpack') . '.zip';
		$zip           = new ZipArchive();
		$zip->open($this->zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		foreach ($entries as $name => $content) {
			if (str_ends_with($name, '/') === true) {
				$zip->addEmptyDir(rtrim($name, '/'));
				continue;
			}

			$zip->addFromString($name, $content);
		}

		$zip->close();

		return $this->zipPath;
	}//end zip()

	/**
	 * The unpacker over a recording Files folder.
	 *
	 * @param LoggerInterface $logger  The logger.
	 * @param bool            $failing Whether every write throws.
	 *
	 * @return ScormPackageUnpacker
	 */
	private function unpacker(LoggerInterface $logger, bool $failing=false): ScormPackageUnpacker {
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willThrowException(new NotFoundException());
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willReturnCallback(
			function (string $path, $content = null) use ($failing) {
				if ($failing === true) {
					throw new RuntimeException('disk full');
				}

				$this->written[$path] = (string)$content;
				return null;
			}
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('anna')->willReturn($folder);

		$reporter = $this->getMockBuilder(CoursePackageImportReporter::class)->disableOriginalConstructor()->onlyMethods(['persistReport'])->getMock();

		return new ScormPackageUnpacker($root, $reporter, $logger);
	}//end unpacker()

	/**
	 * Inside entries are written under the folder, `./` stripped; escaping ones come back dropped.
	 *
	 * @return void
	 */
	public function testInsideEntriesAreWrittenAndEscapingOnesDropped(): void {
		$zip     = $this->zip(['./index.html' => '<html/>', 'assets/sco.js' => 'js', '../evil.php' => 'x', 'assets/' => '']);
		$dropped = $this->unpacker(logger: $this->createMock(LoggerInterface::class))->unpack(packagePath: $zip, folder: 'Learniq/t/c/scorm', importedBy: 'anna');

		$this->assertSame(['Learniq/t/c/scorm/index.html' => '<html/>', 'Learniq/t/c/scorm/assets/sco.js' => 'js'], $this->written);
		$this->assertCount(1, $dropped);
		$this->assertSame('../evil.php', $dropped[0]['resourceIdentifier']);
		$this->assertSame('file', $dropped[0]['resourceType']);
		$this->assertSame('dropped', $dropped[0]['outcome']);
	}//end testInsideEntriesAreWrittenAndEscapingOnesDropped()

	/**
	 * A file that cannot be written is logged and the unpack carries on.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsLoggedNotThrown(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('Could not write'), $this->callback(static fn (array $c): bool => $c['path'] === 'f/index.html'));

		$dropped = $this->unpacker(logger: $logger, failing: true)->unpack(packagePath: $this->zip(['index.html' => 'x']), folder: 'f', importedBy: 'anna');

		$this->assertSame([], $dropped);
	}//end testAFailedWriteIsLoggedNotThrown()

	/**
	 * A file that is not a zip unpacks nothing.
	 *
	 * @return void
	 */
	public function testANonZipUnpacksNothing(): void {
		$this->zipPath = tempnam(sys_get_temp_dir(), 'scormunpack');
		file_put_contents($this->zipPath, 'not a zip');

		$this->assertSame([], $this->unpacker(logger: $this->createMock(LoggerInterface::class))->unpack(packagePath: $this->zipPath, folder: 'f', importedBy: 'anna'));
		$this->assertSame([], $this->written);
	}//end testANonZipUnpacksNothing()

	/**
	 * Absolute, drive-letter, NUL and `..` names do not stay inside; relative ones do.
	 *
	 * @return void
	 */
	public function testStaysInside(): void {
		$this->assertFalse(ScormPackageUnpacker::staysInside(name: ''));
		$this->assertFalse(ScormPackageUnpacker::staysInside(name: '/etc/passwd'));
		$this->assertFalse(ScormPackageUnpacker::staysInside(name: 'C:\\x.dll'));
		$this->assertFalse(ScormPackageUnpacker::staysInside(name: "a\0b"));
		$this->assertFalse(ScormPackageUnpacker::staysInside(name: 'a\\..\\b'));
		$this->assertTrue(ScormPackageUnpacker::staysInside(name: 'assets/sco.js'));
		$this->assertTrue(ScormPackageUnpacker::staysInside(name: 'a..b/c'));
	}//end testStaysInside()
}//end class
