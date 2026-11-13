<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\LearningRecordBundleWriter;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins LearningRecordBundleWriter::write(): every call on the user folder in
 * order (folders made where missing, the bundle created or overwritten), the
 * returned path, and the warning on a failed write.
 *
 * @covers \OCA\Learniq\Service\LearningRecordBundleWriter
 * @uses \OCA\Learniq\Service\FolderFileWriter
 */
class LearningRecordBundleWriterGoldenTest extends TestCase {

	/**
	 * Every call on the user folder, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * The nodes that exist: path => true for a file, false for a folder.
	 *
	 * @var array<string, bool>
	 */
	private array $nodes = [];

	/**
	 * Warnings logged, as [message, context].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * The writer over a recording user folder.
	 *
	 * @return LearningRecordBundleWriter
	 */
	private function writer(): LearningRecordBundleWriter {
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturnCallback(
			function (string $path) {
				$this->calls[] = ['get' => $path];
				if (isset($this->nodes[$path]) === false) {
					throw new NotFoundException($path);
				}

				if ($this->nodes[$path] === false) {
					return $this->createMock(Folder::class);
				}

				$file = $this->createMock(File::class);
				$file->method('putContent')->willReturnCallback(
					function (string $content) use ($path): void {
						$this->calls[] = ['putContent' => [$path, strlen($content)]];
					}
				);
				return $file;
			}
		);
		$folder->method('newFolder')->willReturnCallback(
			function (string $path) {
				$this->calls[] = ['newFolder' => $path];
				$this->nodes[$path] = false;
				return $this->createMock(Folder::class);
			}
		);
		$folder->method('nodeExists')->willReturnCallback(
			function (string $path): bool {
				$this->calls[] = ['nodeExists' => $path];
				return isset($this->nodes[$path]);
			}
		);
		$folder->method('newFile')->willReturnCallback(
			function (string $path, $content = null) {
				$this->calls[] = ['newFile' => [$path, strlen((string)$content)]];
				if (str_contains($path, 'boom') === true) {
					throw new RuntimeException('disk full');
				}

				$this->nodes[$path] = true;
				return $this->createMock(File::class);
			}
		);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $uid) use ($folder): Folder {
				$this->calls[] = ['getUserFolder' => $uid];
				return $folder;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->warnings[] = [(string)$message, $context];
			}
		);

		return new LearningRecordBundleWriter(rootFolder: $root, logger: $logger);
	}//end writer()

	/**
	 * A first write makes the missing folders and creates the bundle.
	 *
	 * @return void
	 */
	public function testAFirstWriteMakesTheFoldersAndTheFile(): void {
		$this->nodes['Learniq'] = false;
		$path = $this->writer()->write(bundle: ['a' => 1], ownerUid: 'pupil-1', tenantId: 't-1', exportId: 'e-1');

		self::assertSame('/Learniq/t-1/learning-record-exports/e-1.json', $path);
		self::assertSame(
			[
				['getUserFolder' => 'pupil-1'],
				['get' => 'Learniq'],
				['get' => 'Learniq/t-1'],
				['newFolder' => 'Learniq/t-1'],
				['get' => 'Learniq/t-1/learning-record-exports'],
				['newFolder' => 'Learniq/t-1/learning-record-exports'],
				['nodeExists' => 'Learniq/t-1/learning-record-exports/e-1.json'],
				['newFile' => ['Learniq/t-1/learning-record-exports/e-1.json', 14]],
			],
			$this->calls
		);
		self::assertSame([], $this->warnings);
	}//end testAFirstWriteMakesTheFoldersAndTheFile()

	/**
	 * A second write overwrites the file; a folder in its place is left alone.
	 *
	 * @return void
	 */
	public function testAnExistingFileIsOverwrittenAndAFolderLeftAlone(): void {
		$this->nodes = ['Learniq' => false, 'Learniq/default' => false, 'Learniq/default/learning-record-exports' => false];
		$this->nodes['Learniq/default/learning-record-exports/e-1.json'] = true;
		$this->nodes['Learniq/default/learning-record-exports/e-2.json'] = false;
		$writer = $this->writer();

		self::assertSame('/Learniq/default/learning-record-exports/e-1.json', $writer->write(bundle: ['a' => 1], ownerUid: 'u', tenantId: '', exportId: 'e-1'));
		self::assertSame('/Learniq/default/learning-record-exports/e-2.json', $writer->write(bundle: ['a' => 1], ownerUid: 'u', tenantId: '', exportId: 'e-2'));
		self::assertSame(
			[
				['getUserFolder' => 'u'],
				['get' => 'Learniq'],
				['get' => 'Learniq/default'],
				['get' => 'Learniq/default/learning-record-exports'],
				['nodeExists' => 'Learniq/default/learning-record-exports/e-1.json'],
				['get' => 'Learniq/default/learning-record-exports/e-1.json'],
				['putContent' => ['Learniq/default/learning-record-exports/e-1.json', 14]],
				['getUserFolder' => 'u'],
				['get' => 'Learniq'],
				['get' => 'Learniq/default'],
				['get' => 'Learniq/default/learning-record-exports'],
				['nodeExists' => 'Learniq/default/learning-record-exports/e-2.json'],
				['get' => 'Learniq/default/learning-record-exports/e-2.json'],
			],
			$this->calls
		);
	}//end testAnExistingFileIsOverwrittenAndAFolderLeftAlone()

	/**
	 * A failed write is null with a warning.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsNullWithAWarning(): void {
		$this->nodes = ['Learniq' => false, 'Learniq/t-1' => false, 'Learniq/t-1/learning-record-exports' => false];

		self::assertNull($this->writer()->write(bundle: ['a' => 1], ownerUid: 'u', tenantId: 't-1', exportId: 'boom'));
		self::assertSame(
			[['[LearningRecordBundleWriter] Could not write signed bundle for export {id}: {msg}', ['id' => 'boom', 'msg' => 'disk full']]],
			$this->warnings
		);
	}//end testAFailedWriteIsNullWithAWarning()
}//end class
