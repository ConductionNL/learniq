<?php

/**
 * Learniq QtiExportService unit tests.
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
 * @spec openspec/changes/course-package-import-export/specs/assessment/spec.md#scenario-exporting-an-itembank-produces-a-valid-qti-30-package
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Service\QtiExportService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * Tests for QtiExportService.
 */
class QtiExportServiceTest extends TestCase {

	/**
	 * The exported package byte-matches the stored `qtiBody` for every item,
	 * including one whose `interactionType` was imported with the pre-existing
	 * degraded parsing (raw `qtiBody` preserved, `correctResponse` unresolved)
	 * — export fidelity is unaffected by that import-side limitation.
	 *
	 * @return void
	 */
	public function testExportProducesAValidPackageWithVerbatimQtiBodies(): void {
		$fullyParsedBody = '<?xml version="1.0"?><assessmentItem identifier="i1"><itemBody>Q1</itemBody></assessmentItem>';
		$degradedBody = '<?xml version="1.0"?><assessmentItem identifier="i2"><itemBody>Q2 (hotspot, raw only)</itemBody></assessmentItem>';

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) use ($fullyParsedBody, $degradedBody) {
				if ($schema === 'item-bank') {
					return OrEntityFactory::make(
						['id' => 'bank-1', 'name' => 'Physics 101', 'itemIds' => ['item-1', 'item-2']],
						'item-bank'
					);
				}

				$row = match ($id) {
					'item-1' => ['id' => 'item-1', 'qtiBody' => $fullyParsedBody, 'interactionType' => 'choice'],
					'item-2' => ['id' => 'item-2', 'qtiBody' => $degradedBody, 'interactionType' => 'hotspot'],
					default => null,
				};

				if ($row === null) {
					return null;
				}

				return OrEntityFactory::make($row, 'item');
			}
		);

		$zipBytes = (new QtiExportService($objectService))->export('bank-1');

		$tmpFile = tempnam(sys_get_temp_dir(), 'learniq_qti_export_test_');
		file_put_contents($tmpFile, $zipBytes);

		$zip = new ZipArchive();
		self::assertTrue($zip->open($tmpFile) === true);

		$manifest = $zip->getFromName('imsmanifest.xml');
		self::assertIsString($manifest);
		self::assertStringContainsString('Physics 101', $manifest);

		self::assertSame($fullyParsedBody, $zip->getFromName('item-1.xml'));
		self::assertSame($degradedBody, $zip->getFromName('item-2.xml'), 'The degraded-parsing item still exports its raw qtiBody verbatim.');

		$zip->close();
		unlink($tmpFile);
	}//end testExportProducesAValidPackageWithVerbatimQtiBodies()

	/**
	 * Export a one-bank package over the given item bodies and return the
	 * ZIP's entries.
	 *
	 * @param array<string, string> $bodies Item id => stored qtiBody.
	 *
	 * @return array<string, string> Entry name => content.
	 */
	private function exportEntries(array $bodies): array {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) use ($bodies) {
				if ($schema === 'item-bank') {
					return OrEntityFactory::make(['id' => 'bank-1', 'name' => 'Statistiek', 'itemIds' => array_keys($bodies)], 'item-bank');
				}

				if (isset($bodies[$id]) === false) {
					return null;
				}

				return OrEntityFactory::make(['id' => $id, 'qtiBody' => $bodies[$id], 'interactionType' => 'choice'], 'item');
			}
		);

		$tmpFile = tempnam(sys_get_temp_dir(), 'learniq_qti_export_test_');
		file_put_contents($tmpFile, (new QtiExportService($objectService))->export('bank-1'));
		$zip = new ZipArchive();
		self::assertTrue($zip->open($tmpFile) === true);
		$entries = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			$entries[$name] = (string)$zip->getFromName($name);
		}

		$zip->close();
		unlink($tmpFile);

		return $entries;
	}//end exportEntries()

	/**
	 * The package says QTI 2.1, which is what its items are. Red before the
	 * fix: the manifest declared QTI 3.0 for QTI 2.1 markup.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grading-defects-from-example-sets/specs/assessment/spec.md#scenario-exporting-an-itembank-produces-a-qti-21-package
	 */
	public function testThePackageDeclaresQti21(): void {
		$entries = $this->exportEntries(['item-1' => '<?xml version="1.0"?><assessmentItem xmlns="http://www.imsglobal.org/xsd/imsqti_v2p1" identifier="i1"/>']);

		$manifest = $entries['imsmanifest.xml'];
		self::assertStringContainsString('xmlns:imsqti="http://www.imsglobal.org/xsd/imsqti_v2p1"', $manifest);
		self::assertStringContainsString('type="imsqti_item_xmlv2p1"', $manifest);
		self::assertStringContainsString('<schema>QTIv2.1 Package</schema>', $manifest);
		self::assertStringNotContainsString('v3p0', $manifest);
	}//end testThePackageDeclaresQti21()

	/**
	 * An item stored under the old hybrid label (QTI 2.1 elements, QTI 3.0
	 * namespace) is exported with the QTI 2.1 namespace and nothing else
	 * changed; an item already labelled 2.1 and a real QTI 3.0 item (whose
	 * root is qti-assessment-item) are exported exactly as stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grading-defects-from-example-sets/specs/assessment/spec.md#scenario-an-item-stored-under-the-old-label-is-exported-with-the-qti-21-namespace
	 */
	public function testAnItemStoredUnderTheOldLabelIsExportedWithTheQti21Namespace(): void {
		$old = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<assessmentItem xmlns="http://www.imsglobal.org/xsd/imsqtiasi_v3p0"' . "\n"
			. '    identifier="item-1" title="Gemiddelde" adaptive="false" timeDependent="false">'
			. '<itemBody><choiceInteraction responseIdentifier="RESPONSE" maxChoices="1">'
			. '<simpleChoice identifier="A">3</simpleChoice></choiceInteraction></itemBody></assessmentItem>';
		$labelled = '<?xml version="1.0"?><assessmentItem xmlns="http://www.imsglobal.org/xsd/imsqti_v2p1" identifier="item-2"/>';
		$real3 = '<?xml version="1.0"?><qti-assessment-item xmlns="http://www.imsglobal.org/xsd/imsqtiasi_v3p0" identifier="item-3"/>';

		$entries = $this->exportEntries(['item-1' => $old, 'item-2' => $labelled, 'item-3' => $real3]);

		self::assertSame(
			str_replace('http://www.imsglobal.org/xsd/imsqtiasi_v3p0', 'http://www.imsglobal.org/xsd/imsqti_v2p1', $old),
			$entries['item-1.xml']
		);
		self::assertSame($labelled, $entries['item-2.xml']);
		self::assertSame($real3, $entries['item-3.xml']);
	}//end testAnItemStoredUnderTheOldLabelIsExportedWithTheQti21Namespace()

	/**
	 * Exporting an unknown ItemBank throws so the controller can return a clean 404/422.
	 *
	 * @return void
	 */
	public function testExportThrowsForUnknownItemBank(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn(null);

		$this->expectException(RuntimeException::class);
		(new QtiExportService($objectService))->export('missing-bank');
	}//end testExportThrowsForUnknownItemBank()
}//end class
