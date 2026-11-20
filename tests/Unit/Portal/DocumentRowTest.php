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
			expected: [
				'id' => '71',
				'title' => 'Rapport 2',
				'mimeType' => 'application/pdf',
				'size' => 86016,
				'isNew' => true,
				'file' => $file,
				'group' => 'Groep 7',
				'meta' => 'Groep 6, PDF, 84 KB',
				'status' => ''
			],
			actual: $row
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

		self::assertSame(expected: 'Wacht op leerbedrijf', actual: $row['status']);
		self::assertSame(expected: '2026-2027', actual: $row['meta']);
		self::assertFalse(condition: $row['isNew']);
		self::assertSame(expected: '', actual: $row['group']);

		$bare = (new DocumentRow())->complete(entry: ['isNew' => 'yes']);
		self::assertSame(expected: ['isNew' => false, 'title' => '', 'group' => '', 'meta' => '', 'status' => ''], actual: $bare);
	}//end testAStatusIsCarriedAndMissingFieldsReadAsNothing()

	/**
	 * File kinds a reader knows by name; anything else says nothing.
	 *
	 * @return void
	 */
	public function testKindsInWords(): void {
		self::assertSame(expected: 'PDF', actual: (new DocumentRow())->kind(mimeType: 'Application/PDF; charset=binary'));
		self::assertSame(expected: 'ZIP', actual: (new DocumentRow())->kind(mimeType: 'application/zip'));
		self::assertSame(
			expected: 'Word',
			actual: (new DocumentRow())->kind(mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
		);
		self::assertSame(expected: 'Excel', actual: (new DocumentRow())->kind(mimeType: 'application/vnd.ms-excel'));
		self::assertSame(expected: 'Image', actual: (new DocumentRow())->kind(mimeType: 'image/png'));
		self::assertSame(expected: '', actual: (new DocumentRow())->kind(mimeType: 'application/octet-stream'));
		self::assertSame(expected: '', actual: (new DocumentRow())->kind(mimeType: ''));
	}//end testKindsInWords()

	/**
	 * Sizes in bytes, KB and MB, at the boundaries; zero or negative is unknown.
	 *
	 * @return void
	 */
	public function testSizesInWords(): void {
		self::assertSame(expected: '', actual: (new DocumentRow())->size(bytes: 0));
		self::assertSame(expected: '', actual: (new DocumentRow())->size(bytes: -5));
		self::assertSame(expected: '1023 bytes', actual: (new DocumentRow())->size(bytes: 1023));
		self::assertSame(expected: '1 KB', actual: (new DocumentRow())->size(bytes: 1024));
		self::assertSame(expected: '1 KB', actual: (new DocumentRow())->size(bytes: 1100));
		self::assertSame(expected: '1024 KB', actual: (new DocumentRow())->size(bytes: 1048575));
		self::assertSame(expected: '1.0 MB', actual: (new DocumentRow())->size(bytes: 1048576));
		self::assertSame(expected: '2.5 MB', actual: (new DocumentRow())->size(bytes: 2621440));
	}//end testSizesInWords()
}//end class
