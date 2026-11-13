<?php

/**
 * ImportFileRows unit tests: rows out of an uploaded CSV, JSON or XML file.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\ImportFileRows;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Parsing an import file into rows by its extension.
 */
#[CoversClass(ImportFileRows::class)]
class ImportFileRowsTest extends TestCase {

	/**
	 * A CSV with a BOM, CRLF lines and blank lines becomes trimmed rows keyed by header.
	 *
	 * @return void
	 */
	public function testACsvBecomesRowsKeyedByTheHeader(): void {
		$csv = "\xEF\xBB\xBFname, email ,\r\n Ann ,ann@example.org,ignored\r\n\r\n\"Bo, Jr\",bo@example.org\n";

		self::assertSame(
			[['name' => 'Ann', 'email' => 'ann@example.org'], ['name' => 'Bo, Jr', 'email' => 'bo@example.org']],
			(new ImportFileRows())->rowsOf(name: 'learners.csv', content: $csv)
		);
	}//end testACsvBecomesRowsKeyedByTheHeader()

	/**
	 * The delimiter is the one the header uses most: semicolon or tab.
	 *
	 * @return void
	 */
	public function testTheDelimiterIsReadFromTheHeader(): void {
		$rows = new ImportFileRows();

		self::assertSame([['a' => '1,5', 'b' => '2']], $rows->rowsOf(name: 'x.txt', content: "a;b\n1,5;2"));
		self::assertSame([['a' => '1', 'b' => '2;3']], $rows->rowsOf(name: 'x.tsv', content: "a\tb\n1\t2;3"));
	}//end testTheDelimiterIsReadFromTheHeader()

	/**
	 * A CSV without data rows, or an empty file, has no rows.
	 *
	 * @return void
	 */
	public function testAnEmptyFileOrAHeaderOnlyCsvHasNoRows(): void {
		$rows = new ImportFileRows();

		self::assertNull($rows->rowsOf(name: 'x.csv', content: "name,email\n\n"));
		self::assertNull($rows->rowsOf(name: 'x.csv', content: " \n\t"));
		self::assertNull($rows->rowsOf(name: 'x.json', content: ''));
	}//end testAnEmptyFileOrAHeaderOnlyCsvHasNoRows()

	/**
	 * JSON may be a list, an object with `records`, or a single object; non-objects are dropped.
	 *
	 * @return void
	 */
	public function testJsonListsRecordsAndSingleObjects(): void {
		$rows = new ImportFileRows();

		self::assertSame([['a' => 1], ['a' => 2]], $rows->rowsOf(name: 'x.JSON', content: '[{"a":1},"skip",{"a":2}]'));
		self::assertSame([['a' => 1]], $rows->rowsOf(name: 'x.json', content: '{"records":[{"a":1}],"meta":{}}'));
		self::assertSame([['a' => 1]], $rows->rowsOf(name: 'x.json', content: '{"a":1}'));
	}//end testJsonListsRecordsAndSingleObjects()

	/**
	 * Invalid JSON, a scalar or a list without objects has no rows.
	 *
	 * @return void
	 */
	public function testJsonWithoutObjectsHasNoRows(): void {
		$rows = new ImportFileRows();

		self::assertNull($rows->rowsOf(name: 'x.json', content: '{broken'));
		self::assertNull($rows->rowsOf(name: 'x.json', content: '42'));
		self::assertNull($rows->rowsOf(name: 'x.json', content: '[1,"a"]'));
		self::assertNull($rows->rowsOf(name: 'x.json', content: '{"records":[]}'));
	}//end testJsonWithoutObjectsHasNoRows()

	/**
	 * An XML document is one row of its child elements; broken or empty XML has none.
	 *
	 * @return void
	 */
	public function testXmlIsOneRow(): void {
		$rows = new ImportFileRows();

		self::assertSame(
			[['name' => 'Ann', 'email' => 'ann@example.org']],
			$rows->rowsOf(name: 'x.xml', content: '<learner><name>Ann</name><email>ann@example.org</email></learner>')
		);
		self::assertNull($rows->rowsOf(name: 'x.xml', content: '<learner><name>Ann</learner>'));
		self::assertNull($rows->rowsOf(name: 'x.xml', content: '<learner/>'));
	}//end testXmlIsOneRow()
}//end class
