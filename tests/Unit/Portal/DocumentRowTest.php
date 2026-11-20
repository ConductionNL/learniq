<?php

/**
 * The shared documents row: every learniq documents provider hands portaliq
 * a group, a title, a meta line in words, whether it is new and a status.
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
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\DocumentRow;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DocumentRow.
 *
 * @covers \OCA\Learniq\Portal\DocumentRow
 */
class DocumentRowTest extends TestCase {

	/**
	 * Vera's report: grouped by her group, the context first in the meta
	 * line, then the kind and size; the entry's own fields are kept.
	 *
	 * @return void
	 */
	public function testAReportReadsAsGroupTitleAndMetaInWords(): void {
		$file = ['register' => 'learniq', 'schema' => 'report-card', 'id' => 'rc-1', 'fileId' => '71'];
		$row  = (new DocumentRow())->complete(
			entry: ['id' => '71', 'title' => ' Rapport 2 ', 'mimeType' => 'application/pdf', 'size' => 86016, 'isNew' => true, 'file' => $file],
			group: 'Groep 7',
			context: ['Groep 6', ' ']
		);

		self::assertSame(
			['id' => '71', 'title' => 'Rapport 2', 'mimeType' => 'application/pdf', 'size' => 86016, 'isNew' => true, 'file' => $file, 'group' => 'Groep 7', 'meta' => 'Groep 6, PDF, 84 KB', 'status' => ''],
			$row
		);
	}//end testAReportReadsAsGroupTitleAndMetaInWords()

	/**
	 * Milan's agreement carries its signing state as the status; an entry
	 * without isNew is not new, and one without type or size has a meta line
	 * of its context only.
	 *
	 * @return void
	 */
	public function testAStatusIsCarriedAndMissingFieldsReadAsNothing(): void {
		$row = (new DocumentRow())->complete(entry: ['title' => 'Praktijkovereenkomst'], status: ' Wacht op leerbedrijf ', context: ['2026-2027']);

		self::assertSame('Wacht op leerbedrijf', $row['status']);
		self::assertSame('2026-2027', $row['meta']);
		self::assertFalse($row['isNew']);
		self::assertSame('', $row['group']);

		$bare = (new DocumentRow())->complete(entry: ['isNew' => 'yes']);
		self::assertSame(['isNew' => false, 'title' => '', 'group' => '', 'meta' => '', 'status' => ''], $bare);
	}//end testAStatusIsCarriedAndMissingFieldsReadAsNothing()

	/**
	 * File kinds a reader knows by name; anything else says nothing.
	 *
	 * @return void
	 */
	public function testKindsInWords(): void {
		self::assertSame('PDF', (new DocumentRow())->kind(mimeType: 'Application/PDF; charset=binary'));
		self::assertSame('ZIP', (new DocumentRow())->kind(mimeType: 'application/zip'));
		self::assertSame('Word', (new DocumentRow())->kind(mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
		self::assertSame('Excel', (new DocumentRow())->kind(mimeType: 'application/vnd.ms-excel'));
		self::assertSame('Image', (new DocumentRow())->kind(mimeType: 'image/png'));
		self::assertSame('', (new DocumentRow())->kind(mimeType: 'application/octet-stream'));
		self::assertSame('', (new DocumentRow())->kind(mimeType: ''));
	}//end testKindsInWords()

	/**
	 * Sizes in bytes, KB and MB, at the boundaries; zero or negative is unknown.
	 *
	 * @return void
	 */
	public function testSizesInWords(): void {
		self::assertSame('', (new DocumentRow())->size(bytes: 0));
		self::assertSame('', (new DocumentRow())->size(bytes: -5));
		self::assertSame('1023 bytes', (new DocumentRow())->size(bytes: 1023));
		self::assertSame('1 KB', (new DocumentRow())->size(bytes: 1024));
		self::assertSame('1 KB', (new DocumentRow())->size(bytes: 1100));
		self::assertSame('1024 KB', (new DocumentRow())->size(bytes: 1048575));
		self::assertSame('1.0 MB', (new DocumentRow())->size(bytes: 1048576));
		self::assertSame('2.5 MB', (new DocumentRow())->size(bytes: 2621440));
	}//end testSizesInWords()
}//end class
