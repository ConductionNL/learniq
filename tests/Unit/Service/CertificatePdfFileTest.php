<?php

/**
 * Unit tests for CertificatePdfFile.
 *
 * @category  Tests
 * @package   OCA\Learniq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec openspec/changes/credentials-certificate-pdf/specs/certification/spec.md#requirement-only-the-holder-and-the-certificates-managers-download-the-pdf
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\CertificatePdfFile;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A file id that names a PDF reads as its bytes; anything else reads as null.
 */
#[CoversClass(CertificatePdfFile::class)]
class CertificatePdfFileTest extends TestCase {

	/**
	 * The ids asked of the file tree.
	 *
	 * @var array<int, int>
	 */
	private array $asked = [];

	/**
	 * The reader over a file tree holding a PDF (4711), a text file (4712), a folder (4713) and an unreadable PDF (4714).
	 *
	 * @return CertificatePdfFile
	 */
	private function reader(): CertificatePdfFile {
		$pdf = $this->createMock(File::class);
		$pdf->method('getMimetype')->willReturn('application/pdf');
		$pdf->method('getContent')->willReturn('%PDF-1.7');
		$text = $this->createMock(File::class);
		$text->method('getMimetype')->willReturn('text/plain');
		$text->expects($this->never())->method('getContent');
		$locked = $this->createMock(File::class);
		$locked->method('getMimetype')->willReturn('application/pdf');
		$locked->method('getContent')->willThrowException(new NotPermittedException('locked'));
		$nodes = [4711 => $pdf, 4712 => $text, 4713 => $this->createMock(Folder::class), 4714 => $locked];

		$root = $this->createMock(IRootFolder::class);
		$root->method('getFirstNodeById')->willReturnCallback(
			function (int $id) use ($nodes) {
				$this->asked[] = $id;
				return $nodes[$id] ?? null;
			}
		);

		return new CertificatePdfFile(rootFolder: $root, logger: new NullLogger());
	}//end reader()

	/**
	 * The PDF's bytes, by its file id.
	 *
	 * @return void
	 */
	public function testAFileIdOfAPdfReadsAsItsBytes(): void {
		self::assertSame('%PDF-1.7', $this->reader()->read(documentRef: ' 4711 '));
		self::assertSame([4711], $this->asked);
	}//end testAFileIdOfAPdfReadsAsItsBytes()

	/**
	 * Another media type, a folder, a missing id and an unreadable file read as null.
	 *
	 * @return void
	 */
	public function testAnythingButAReadablePdfReadsAsNull(): void {
		$reader = $this->reader();

		self::assertNull($reader->read(documentRef: '4712'));
		self::assertNull($reader->read(documentRef: '4713'));
		self::assertNull($reader->read(documentRef: '404'));
		self::assertNull($reader->read(documentRef: '4714'));
		self::assertSame([4712, 4713, 404, 4714], $this->asked);
	}//end testAnythingButAReadablePdfReadsAsNull()

	/**
	 * A reference that is not a file id is never looked up.
	 *
	 * @return void
	 */
	public function testAReferenceThatIsNoFileIdIsNotLookedUp(): void {
		$reader = $this->reader();

		foreach (['', 'doc-42', '0', '-3', '12abc', '../4711'] as $ref) {
			self::assertNull($reader->read(documentRef: $ref), $ref);
		}

		self::assertSame([], $this->asked);
	}//end testAReferenceThatIsNoFileIdIsNotLookedUp()
}//end class
