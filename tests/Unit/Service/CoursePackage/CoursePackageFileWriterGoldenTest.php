<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CoursePackage;

use OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\Tests\Support\GoldenFile;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins resolveFileRef(), writeBase64ToFiles(), writeBytesToFiles() and
 * removeDirectory() against golden/CoursePackageFileWriter.json: the returned
 * reference, every call on the user folder in order, and what was logged.
 *
 * @covers \OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter
 * @uses   \OCA\Learniq\Service\FolderFileWriter
 */
class CoursePackageFileWriterGoldenTest extends TestCase {
	use GoldenFile;

	private const GOLDEN = __DIR__ . '/golden/CoursePackageFileWriter.json';

	/**
	 * Calls on the folders, in order.
	 *
	 * @var list<mixed>
	 */
	private array $calls = [];

	/**
	 * Paths that exist per user, with whether each is a file.
	 *
	 * @var array<string,array<string,bool>>
	 */
	private array $nodes = [];

	/**
	 * Every write answers and touches the folder as recorded.
	 *
	 * @return void
	 */
	public function testWritesMatchGolden(): void {
		$dir = sys_get_temp_dir() . '/learniq-fw-golden-' . getmypid();
		@mkdir($dir . '/activity/sub', 0777, true);
		file_put_contents($dir . '/sheet.pdf', 'PDF bytes');
		file_put_contents($dir . '/activity/sub/inner.txt', 'inner');
		file_put_contents($dir . '/.hidden', 'h');

		$logger = new CapturingLogger();
		$writer = new CoursePackageFileWriter($this->root(), $logger);
		$cases = [
			'href null' => fn () => $writer->resolveFileRef(dir: $dir, href: null, importedBy: 'anna', tenantId: 't-1'),
			'href a directory' => fn () => $writer->resolveFileRef(dir: $dir, href: 'activity', importedBy: 'anna', tenantId: 't-1'),
			'href missing' => fn () => $writer->resolveFileRef(dir: $dir, href: 'gone.pdf', importedBy: 'anna', tenantId: 't-1'),
			'href a file, new folders' => fn () => $writer->resolveFileRef(dir: $dir, href: 'sheet.pdf', importedBy: 'anna', tenantId: 't-1'),
			'href a file again, existing' => fn () => $writer->resolveFileRef(dir: $dir, href: 'sheet.pdf', importedBy: 'anna', tenantId: 't-1'),
			'href nested, no tenant' => fn () => $writer->resolveFileRef(dir: $dir, href: 'activity/sub/inner.txt', importedBy: 'anna', tenantId: ''),
			'base64 invalid' => fn () => $writer->writeBase64ToFiles(base64Content: '***', title: 'x', importedBy: 'anna', tenantId: 't-1'),
			'base64 title cleaned' => fn () => $writer->writeBase64ToFiles(base64Content: base64_encode('abc'), title: 'Les 1: Breuken & delen!.png', importedBy: 'bert', tenantId: 't-2'),
			'base64 title empty' => fn () => $writer->writeBase64ToFiles(base64Content: base64_encode('abc'), title: '', importedBy: 'bert', tenantId: 't-2'),
			'base64 title all dashes' => fn () => $writer->writeBase64ToFiles(base64Content: '', title: '###', importedBy: 'bert', tenantId: 't-2'),
			'bytes over a folder node' => fn () => $writer->writeBytesToFiles(content: 'x', filename: 'folder-node', importedBy: 'bert', tenantId: 't-2'),
			'bytes for an unknown user' => fn () => $writer->writeBytesToFiles(content: 'x', filename: 'a.txt', importedBy: 'ghost', tenantId: 't-2'),
			'bytes when the file cannot be made' => fn () => $writer->writeBytesToFiles(content: 'boom', filename: 'b.txt', importedBy: 'bert', tenantId: 't-2'),
			'bytes when a folder cannot be made' => fn () => $writer->writeBytesToFiles(content: 'x', filename: 'a.txt', importedBy: 'locked', tenantId: 't-2'),
		];

		$actual = [];
		foreach ($cases as $name => $case) {
			$this->calls = [];
			$logger->records = [];
			$actual[$name] = ['ref' => $case(), 'calls' => $this->calls, 'log' => $logger->records];
		}

		$writer->removeDirectory(dir: $dir . '/nothing-here');
		$writer->removeDirectory(dir: $dir . '/sheet.pdf');
		$actual['remove a file path keeps it'] = file_exists($dir . '/sheet.pdf');
		$writer->removeDirectory(dir: $dir);
		$actual['remove the tree'] = [file_exists($dir . '/activity/sub/inner.txt'), file_exists($dir . '/.hidden'), file_exists($dir . '/sheet.pdf'), is_dir($dir . '/activity/sub'), is_dir($dir)];
		@rmdir($dir . '/activity/sub');
		@rmdir($dir . '/activity');
		@rmdir($dir);

		$this->assertMatchesGolden(file: self::GOLDEN, actual: $actual);
	}//end testWritesMatchGolden()

	/**
	 * A root folder whose user folders keep their nodes in $this->nodes.
	 *
	 * @return IRootFolder
	 */
	private function root(): IRootFolder {
		$this->nodes = ['bert' => ['Learniq/t-2/course-imports/folder-node' => false]];
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $uid): Folder {
				$this->calls[] = ['getUserFolder' => $uid];
				if ($uid === 'ghost') {
					throw new NotFoundException('no such user');
				}

				return $this->userFolder(uid: $uid);
			}
		);

		return $root;
	}//end root()

	/**
	 * One user's folder.
	 *
	 * @param string $uid The user.
	 *
	 * @return Folder
	 */
	private function userFolder(string $uid): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturnCallback(
			function (string $path) use ($uid) {
				$this->calls[] = ['get' => $path];
				if (isset($this->nodes[$uid][$path]) === false) {
					throw new NotFoundException($path);
				}

				if ($this->nodes[$uid][$path] === false) {
					return $this->createMock(Folder::class);
				}

				$file = $this->createMock(File::class);
				$file->method('putContent')->willReturnCallback(
					function (string $content) use ($path): void {
						$this->calls[] = ['putContent' => [$path, $content]];
					}
				);
				return $file;
			}
		);
		$folder->method('newFolder')->willReturnCallback(
			function (string $path) use ($uid) {
				$this->calls[] = ['newFolder' => $path];
				if ($uid === 'locked') {
					throw new NotPermittedException('read only');
				}

				$this->nodes[$uid][$path] = false;
				return $this->createMock(Folder::class);
			}
		);
		$folder->method('nodeExists')->willReturnCallback(
			function (string $path) use ($uid): bool {
				$this->calls[] = ['nodeExists' => $path];
				return isset($this->nodes[$uid][$path]);
			}
		);
		$folder->method('newFile')->willReturnCallback(
			function (string $path, $content = null) use ($uid) {
				$this->calls[] = ['newFile' => [$path, $content]];
				if ($content === 'boom') {
					throw new RuntimeException('disk full');
				}

				$this->nodes[$uid][$path] = true;
				return $this->createMock(File::class);
			}
		);

		return $folder;
	}//end userFolder()
}//end class
