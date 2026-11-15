<?php

/**
 * What leaves the server on the guardian's "Rapporten en documenten": only
 * files on a child's published report cards and on a school's published
 * documents for guardians, whatever the store answers.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/guardian-reports-and-documents/specs/portal-contribution/spec.md#requirement-a-guardian-reads-her-childrens-published-reports-and-the-schools-papers-on-one-page
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\GuardianDocuments;
use OCA\Learniq\Portal\PortalFileEntries;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for GuardianDocuments and PortalFileEntries.
 */
class GuardianDocumentsTest extends TestCase {

	/**
	 * The filters of the last read.
	 *
	 * @var array<string, mixed>
	 */
	private array $asked = [];

	/**
	 * A reader over these rows, whose files are listed per row id.
	 *
	 * @param array<int, array<string, mixed>>|RuntimeException $rows  What the store answers, or what it throws.
	 * @param array<string, array<int, mixed>>                  $files The attached files per row id.
	 *
	 * @return GuardianDocuments
	 */
	private function reader(array|RuntimeException $rows, array $files): GuardianDocuments {
		$store = $this->createMock(ObjectService::class);
		$store->method('findAll')->willReturnCallback(
			function (array $config) use ($rows): array {
				$this->asked = $config['filters'];
				if ($rows instanceof RuntimeException) {
					throw $rows;
				}

				return $rows;
			}
		);
		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFiles')->willReturnCallback(
			static function (string $id) use ($files): array {
				if (isset($files[$id]) === false) {
					throw new RuntimeException('no folder');
				}

				return $files[$id];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);

		return new GuardianDocuments($store, new PortalFileEntries($fileService, $logger), $logger);
	}//end reader()

	/**
	 * An attached file.
	 *
	 * @param int    $id    The file id.
	 * @param string $name  The file name.
	 * @param int    $saved When it was saved.
	 *
	 * @return File
	 */
	private function file(int $id, string $name, int $saved): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getMimetype')->willReturn('application/pdf');
		$file->method('getSize')->willReturn(86016);
		$file->method('getMTime')->willReturn($saved);
		return $file;
	}//end file()

	/**
	 * Vera's published report reaches her mother with its period as title;
	 * a card the store hands back in review, or of another child, does not.
	 *
	 * @return void
	 */
	public function testAChildsPublishedReportIsOfferedAndNothingElse(): void {
		$now    = time();
		$reader = $this->reader(
			[
				['id' => 'rc-1', 'learnerRef' => 'vera', 'lifecycle' => 'published-to-parents', 'periodName' => 'Rapport 1, februari 2026'],
				['id' => 'rc-2', 'learnerRef' => 'vera', 'lifecycle' => 'rapportvergadering-review', 'periodName' => 'Rapport 2'],
				['id' => 'rc-3', 'learnerRef' => 'sami', 'lifecycle' => 'published-to-parents', 'periodName' => 'Rapport 1'],
			],
			[
				'rc-1' => [$this->file(id: 71, name: 'Rapport Vera.pdf', saved: $now), $this->createMock(Folder::class)],
				'rc-2' => [$this->file(id: 72, name: 'x.pdf', saved: $now)],
				'rc-3' => [$this->file(id: 73, name: 'y.pdf', saved: $now)],
			]
		);

		$entries = $reader->childReports('vera');

		self::assertSame(['learnerRef' => 'vera', 'lifecycle' => 'published-to-parents'], array_diff_key($this->asked, ['register' => 1, 'schema' => 1]));
		self::assertCount(1, $entries);
		self::assertSame('71', $entries[0]['id']);
		self::assertSame('Rapport 1, februari 2026', $entries[0]['title']);
		self::assertSame(['register' => 'learniq', 'schema' => 'report-card', 'id' => 'rc-1', 'fileId' => '71'], $entries[0]['file']);
		self::assertSame('application/pdf', $entries[0]['mimeType']);
		self::assertSame(86016, $entries[0]['size']);
		self::assertTrue($entries[0]['isNew']);
	}//end testAChildsPublishedReportIsOfferedAndNothingElse()

	/**
	 * The school's guide for parents is offered, named by its file when the
	 * record has no title and no longer new after two weeks; a draft and a
	 * paper for staff stay inside.
	 *
	 * @return void
	 */
	public function testASchoolsPapersForGuardiansAreOfferedAndNothingElse(): void {
		$old    = time() - (15 * 86400);
		$reader = $this->reader(
			[
				['id' => 'sd-1', 'schoolId' => 'wilg', 'lifecycle' => 'published', 'audience' => 'guardians', 'title' => ''],
				['id' => 'sd-2', 'schoolId' => 'wilg', 'lifecycle' => 'draft', 'audience' => 'guardians', 'title' => 'Concept'],
				['id' => 'sd-3', 'schoolId' => 'wilg', 'lifecycle' => 'published', 'audience' => 'staff', 'title' => 'Intern'],
			],
			[
				'sd-1' => [$this->file(id: 81, name: 'Schoolgids 2026-2027.pdf', saved: $old)],
				'sd-2' => [$this->file(id: 82, name: 'a.pdf', saved: $old)],
				'sd-3' => [$this->file(id: 83, name: 'b.pdf', saved: $old)],
			]
		);

		$entries = $reader->schoolPapers('wilg');

		self::assertSame('guardians', $this->asked['audience']);
		self::assertSame(['Schoolgids 2026-2027'], array_column($entries, 'title'));
		self::assertFalse($entries[0]['isNew']);
		self::assertSame('school-document', $entries[0]['file']['schema']);
	}//end testASchoolsPapersForGuardiansAreOfferedAndNothingElse()

	/**
	 * No id, a store that fails or a record without a folder gives nothing.
	 *
	 * @return void
	 */
	public function testNothingIsOfferedWhenTheReadCannotAnswer(): void {
		$row = ['id' => 'rc-9', 'learnerRef' => 'vera', 'lifecycle' => 'published-to-parents'];

		self::assertSame([], $this->reader([$row], ['rc-9' => [$this->file(id: 1, name: 'a.pdf', saved: 0)]])->childReports(''));
		self::assertSame([], $this->asked, 'an empty id never reads');
		self::assertSame([], $this->reader(new RuntimeException('down'), [])->childReports('vera'));
		self::assertSame([], $this->reader([$row], [])->childReports('vera'));
	}//end testNothingIsOfferedWhenTheReadCannotAnswer()
}//end class
