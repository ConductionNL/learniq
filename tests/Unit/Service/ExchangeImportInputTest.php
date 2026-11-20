<?php

/**
 * Tests for ExchangeImportInput: the rows of an import job's file.
 *
 * @category Test
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-the-gate-hands-the-rows-of-an-import-jobs-file-to-integriq
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\ExchangeImportInput;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The reader over a mocked user folder.
 */
class ExchangeImportInputTest extends TestCase {

	/**
	 * Files by id, in the requester's folder.
	 *
	 * @var array<int, File>
	 */
	private array $files = [];

	/**
	 * The users whose folder was opened.
	 *
	 * @var array<int, string>
	 */
	private array $openedFolders = [];

	/**
	 * The reader under test.
	 *
	 * @var ExchangeImportInput
	 */
	private ExchangeImportInput $input;

	/**
	 * Build the reader.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$folder = $this->createMock(Folder::class);
		$folder->method('getFirstNodeById')->willReturnCallback(fn (int $id): ?File => ($this->files[$id] ?? null));
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $uid) use ($folder): Folder {
				$this->openedFolders[] = $uid;
				return $folder;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->input = new ExchangeImportInput($root, $l10n);
	}//end setUp()

	/**
	 * Put a file in the requester's folder.
	 *
	 * @param int    $id      The file id.
	 * @param string $name    The file name.
	 * @param string $content The content.
	 * @param int    $size    The size to report, -1 for the content's length.
	 *
	 * @return File The mocked file.
	 */
	private function file(int $id, string $name, string $content, int $size=-1): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getSize')->willReturn($size === -1 ? strlen($content) : $size);
		$file->method('getContent')->willReturn($content);
		$this->files[$id] = $file;
		return $file;
	}//end file()

	/**
	 * A CSV with a semicolon header becomes one record per row.
	 *
	 * @return void
	 */
	public function testACsvBecomesOneRecordPerRow(): void {
		$this->file(42, 'cito.csv', "learnerId;instrument;moment\npupil-1;rekenen;M5\n\npupil-2;spelling;E5\n");

		$result = $this->input->read('lvs-results', ['fileId' => 42], 'teacher');

		$this->assertNull($result['refusal']);
		$this->assertSame(['teacher'], $this->openedFolders);
		$this->assertSame(
			[
				['recordId' => '42:1', 'sourceKind' => 'lvs-result', 'data' => ['learnerId' => 'pupil-1', 'instrument' => 'rekenen', 'moment' => 'M5']],
				['recordId' => '42:2', 'sourceKind' => 'lvs-result', 'data' => ['learnerId' => 'pupil-2', 'instrument' => 'spelling', 'moment' => 'E5']],
			],
			$result['records']
		);
	}//end testACsvBecomesOneRecordPerRow()

	/**
	 * A JSON file with a records list, and an XML dossier as one record.
	 *
	 * @return void
	 */
	public function testJsonAndXmlAreRead(): void {
		$this->file(7, 'parnassys.json', '{"records": [{"ncUserId": "pupil-1"}, {"ncUserId": "pupil-2"}]}');
		$this->file(8, 'dossier.xml', '<dossier><sourceSchoolBrin>12AB</sourceSchoolBrin><learnerEckId>eck-9</learnerEckId></dossier>');

		$json = $this->input->read('migration-import', ['fileId' => '7'], 'admin');
		$xml = $this->input->read('oso', ['fileId' => 8], 'admin');

		$this->assertSame(['7:1', '7:2'], array_column($json['records'], 'recordId'));
		$this->assertSame('learner-profile', $json['records'][0]['sourceKind']);
		$this->assertSame([['recordId' => '8:1', 'sourceKind' => 'oso-dossier', 'data' => ['sourceSchoolBrin' => '12AB', 'learnerEckId' => 'eck-9']]], $xml['records']);
	}//end testJsonAndXmlAreRead()

	/**
	 * A target whose input learniq does not hand over gets nothing and no refusal.
	 *
	 * @return void
	 */
	public function testAnotherTargetGetsNoRecords(): void {
		$this->assertSame(['records' => [], 'refusal' => null, 'reason' => ''], $this->input->read('timetable-import', ['fileId' => 1], 'teacher'));
		$this->assertSame([], $this->openedFolders);
	}//end testAnotherTargetGetsNoRecords()

	/**
	 * A job without a file id, or without a person who asked for it.
	 *
	 * @return void
	 */
	public function testAJobWithoutAFileOrARequester(): void {
		$this->file(3, 'a.csv', "a\n1\n");

		$this->assertSame('import-input-missing', $this->input->read('lvs-results', [], 'teacher')['refusal']);
		$this->assertSame('import-input-missing', $this->input->read('lvs-results', ['fileId' => 'abc'], 'teacher')['refusal']);
		$this->assertSame('import-input-unreadable', $this->input->read('lvs-results', ['fileId' => 3], '')['refusal']);
		$this->assertSame('import-input-unreadable', $this->input->read('lvs-results', ['fileId' => 3], 'system')['refusal']);
	}//end testAJobWithoutAFileOrARequester()

	/**
	 * A file the requester cannot open is refused.
	 *
	 * @return void
	 */
	public function testAFileOutsideTheRequestersFilesIsRefused(): void {
		$result = $this->input->read('oso', ['fileId' => 99], 'teacher');

		$this->assertSame('import-input-unreadable', $result['refusal']);
		$this->assertSame([], $result['records']);
		$this->assertSame('The file of this import cannot be opened by the person who asked for it.', $result['reason']);
	}//end testAFileOutsideTheRequestersFilesIsRefused()

	/**
	 * A file over the bound is refused before its content is read.
	 *
	 * @return void
	 */
	public function testAFileOverTheBoundIsNotRead(): void {
		$file = $this->file(5, 'huge.csv', 'never read', (ExchangeImportInput::MAX_BYTES + 1));
		$file->expects($this->never())->method('getContent');

		$this->assertSame('import-input-too-large', $this->input->read('lvs-results', ['fileId' => 5], 'teacher')['refusal']);
	}//end testAFileOverTheBoundIsNotRead()

	/**
	 * Too many rows, or content that is not CSV, JSON or XML.
	 *
	 * @return void
	 */
	public function testTooManyRowsOrUnreadableContent(): void {
		$this->file(10, 'many.csv', "a\n" . str_repeat("1\n", (ExchangeImportInput::MAX_RECORDS + 1)));
		$this->file(11, 'broken.json', '{"records": [');
		$this->file(12, 'broken.xml', '<dossier><open>');
		$this->file(13, 'empty.csv', '');

		$this->assertSame('import-input-too-large', $this->input->read('lvs-results', ['fileId' => 10], 'teacher')['refusal']);
		$this->assertSame('import-input-unparseable', $this->input->read('migration-import', ['fileId' => 11], 'teacher')['refusal']);
		$this->assertSame('import-input-unparseable', $this->input->read('oso', ['fileId' => 12], 'teacher')['refusal']);
		$this->assertSame('import-input-unparseable', $this->input->read('lvs-results', ['fileId' => 13], 'teacher')['refusal']);
	}//end testTooManyRowsOrUnreadableContent()
	/**
	 * Only a positive whole number, or a string of digits, names the file.
	 *
	 * @return void
	 */
	public function testOnlyAPositiveFileIdNamesTheFile(): void {
		$this->file(4, 'a.csv', "a\n1\n");

		foreach ([0, -4, '0', '-4', 4.0, '4.0', ' 4', null, true] as $fileId) {
			$this->assertSame('import-input-missing', $this->input->read('lvs-results', ['fileId' => $fileId], 'teacher')['refusal'], var_export($fileId, true));
		}

		$this->assertSame(['4:1'], array_column($this->input->read('lvs-results', ['fileId' => '4'], 'teacher')['records'], 'recordId'));
		$this->assertSame(['teacher'], $this->openedFolders);
	}//end testOnlyAPositiveFileIdNamesTheFile()

	/**
	 * A folder, a requester whose files cannot be opened, and content that
	 * cannot be read are each unreadable.
	 *
	 * @return void
	 */
	public function testWhatCannotBeOpenedIsUnreadable(): void {
		$folderNode = $this->createMock(Folder::class);
		$broken = $this->createMock(File::class);
		$broken->method('getSize')->willReturn(10);
		$broken->method('getName')->willReturn('a.csv');
		$broken->method('getContent')->willThrowException(new \RuntimeException('locked'));
		$folder = $this->createMock(Folder::class);
		$folder->method('getFirstNodeById')->willReturnCallback(fn (int $id): ?object => [1 => $folderNode, 2 => $broken][$id] ?? null);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $uid) use ($folder): Folder {
				if ($uid === 'gone') {
					throw new \RuntimeException('no such user');
				}

				return $folder;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$input = new ExchangeImportInput($root, $l10n);

		$this->assertSame('import-input-unreadable', $input->read('oso', ['fileId' => 1], 'teacher')['refusal']);
		$this->assertSame('import-input-unreadable', $input->read('oso', ['fileId' => 2], 'teacher')['refusal']);
		$this->assertSame('import-input-unreadable', $input->read('oso', ['fileId' => 2], 'gone')['refusal']);
	}//end testWhatCannotBeOpenedIsUnreadable()

	/**
	 * JSON: one object, a `records` list, or a list; items that are not
	 * objects are dropped, and nothing left is unparseable.
	 *
	 * @return void
	 */
	public function testEveryShapeOfJson(): void {
		$cases = [
			'one object' => ['{"ncUserId": "p-1"}', [['ncUserId' => 'p-1']]],
			'records not a list' => ['{"records": "none", "ncUserId": "p-2"}', [['records' => 'none', 'ncUserId' => 'p-2']]],
			'list with scalars' => ['[{"a": 1}, 2, "x", {"b": 2}]', [['a' => 1], ['b' => 2]]],
			'records object' => ['{"records": {"a": 1}}', [['a' => 1]]],
			'only scalars' => ['[1, 2]', null],
			'a scalar' => ['"text"', null],
			'empty list' => ['[]', null],
		];
		$id = 100;
		foreach ($cases as $label => [$content, $rows]) {
			$id++;
			$this->file($id, 'x.JSON', $content);
			$result = $this->input->read('migration-import', ['fileId' => $id], 'teacher');
			if ($rows === null) {
				$this->assertSame('import-input-unparseable', $result['refusal'], $label);
				continue;
			}

			$this->assertNull($result['refusal'], $label);
			$this->assertSame($rows, array_column($result['records'], 'data'), $label);
		}
	}//end testEveryShapeOfJson()

	/**
	 * CSV: a byte-order mark and blank lines are ignored, the delimiter is
	 * the header's most used one, a blank header column is dropped, a short
	 * row fills with '', and a header without rows is unparseable.
	 *
	 * @return void
	 */
	public function testEveryShapeOfCsv(): void {
		$this->file(201, 'a.txt', "\xEF\xBB\xBFname, ,group\r\n\r\n Ann , x ,3a\r\nBob\r\n");
		$this->file(202, 'b.csv', "a\tb\n1\t2\t3\n");
		$this->file(203, 'c.csv', "a;b\n   \n");

		$this->assertSame([['name' => 'Ann', 'group' => '3a'], ['name' => 'Bob', 'group' => '']], array_column($this->input->read('lvs-results', ['fileId' => 201], 'teacher')['records'], 'data'));
		$this->assertSame([['a' => '1', 'b' => '2']], array_column($this->input->read('lvs-results', ['fileId' => 202], 'teacher')['records'], 'data'));
		$this->assertSame('import-input-unparseable', $this->input->read('lvs-results', ['fileId' => 203], 'teacher')['refusal']);
	}//end testEveryShapeOfCsv()
}//end class
