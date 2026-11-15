<?php

/**
 * Learniq QtiImportService item import golden tests.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\QtiImportService;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Pins importFromDirectory() over a directory of QTI item files without a manifest (every
 * saved Item, the returned uuids and the log) against
 * tests/Unit/Service/golden/QtiItemImport.json. With RECORD_GOLDEN=1 the file
 * is written instead of compared.
 *
 * @covers \OCA\Learniq\Service\QtiImportService
 * @uses   \OCA\Learniq\Service\QtiManifestScanner
 * @uses   \OCA\Learniq\Service\QtiPackageExtractor
 * @uses \OCA\Learniq\Service\QtiItemTarget
 */
class QtiItemImportGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/QtiItemImport.json';

	/**
	 * The item files, by name.
	 *
	 * @return array<string,string>
	 */
	private static function items(): array {
		$q3 = 'xmlns="http://www.imsglobal.org/xsd/imsqtiasi_v3p0"';
		return [
			'a-choice-single.xml' => "<assessmentItem {$q3} title=\"Hoofdstad\"><responseDeclaration identifier=\"RESPONSE\"><correctResponse><value> B </value></correctResponse></responseDeclaration>"
				. '<outcomeDeclaration identifier="SCORE"><defaultValue><value>2.5</value></defaultValue></outcomeDeclaration>'
				. '<itemBody><choiceInteraction><simpleChoice identifier="A">x</simpleChoice><simpleChoice identifier="B">y</simpleChoice></choiceInteraction></itemBody></assessmentItem>',
			'b-choice-multi.xml' => '<assessmentItem><responseDeclaration><correctResponse><value>A</value><value>C</value></correctResponse></responseDeclaration>'
				. '<outcomeDeclaration identifier="MAXSCORE"><defaultValue><value>x</value></defaultValue></outcomeDeclaration><outcomeDeclaration identifier="SCORE"><defaultValue><value>4</value></defaultValue></outcomeDeclaration>'
				. '<itemBody><choiceInteraction/></itemBody></assessmentItem>',
			'c-choice-no-key.xml' => '<assessmentItem title=""><outcomeDeclaration identifier="OTHER"><defaultValue><value>9</value></defaultValue></outcomeDeclaration><choiceInteraction/></assessmentItem>',
			'd-essay.xml' => '<assessmentItem title="Essay"><outcomeDeclaration identifier="MAXSCORE"><defaultValue><value>10</value></defaultValue></outcomeDeclaration><extendedTextInteraction/></assessmentItem>',
			'e-essay-default.xml' => '<assessmentItem><itemBody><p>Schrijf</p></itemBody></assessmentItem>',
			'f-text-entry.xml' => '<assessmentItem title="Invul"><outcomeDeclaration identifier="SCORE"><defaultValue><value>3</value></defaultValue></outcomeDeclaration><textEntryInteraction/></assessmentItem>',
			'g-match.xml' => '<assessmentItem><matchInteraction/><orderInteraction/></assessmentItem>',
			'h-broken.xml' => '<assessmentItem><unclosed></assessmentItem>',
			'i-no-root.xml' => '<other><choiceInteraction/></other>',
			'j-no-uuid.xml' => '<assessmentItem title="nouuid"><hotspotInteraction/></assessmentItem>',
		];
	}//end items()

	/**
	 * The saved Items, the returned uuids and the log equal the recorded golden.
	 *
	 * @return void
	 */
	public function testImportFromDirectoryMatchesGolden(): void {
		$dir = sys_get_temp_dir() . '/learniq_qti_golden_' . bin2hex(random_bytes(6));
		mkdir($dir . '/items', 0700, true);
		foreach (self::items() as $name => $xml) {
			file_put_contents($dir . '/items/' . $name, $xml);
		}

		$saved = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			static function (array $object, ?array $extend = [], $register = null, $schema = null) use (&$saved): ObjectEntity {
				$saved[] = [$register, $schema, $object];
				$uuid = 'item-' . count($saved);
				if ($object['title'] === 'nouuid') {
					$uuid = null;
				}

				$entity = OrEntityFactory::make($object, (string)$schema, (string)$register, 'x');
				$entity->setUuid($uuid);
				return $entity;
			}
		);
		$logger = new CapturingLogger();

		try {
			$uuids = (new QtiImportService($objects, $logger))->importFromDirectory($dir, 'bank-1', 'tenant-1');
		} finally {
			foreach (array_keys(self::items()) as $name) {
				unlink($dir . '/items/' . $name);
			}

			rmdir($dir . '/items');
			rmdir($dir);
		}

		$actual = ['uuids' => $uuids, 'saved' => $saved, 'log' => $logger->records];
		$actual = json_decode(str_replace($dir, '<dir>', (string)json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)), true);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), $actual);
	}//end testImportFromDirectoryMatchesGolden()
}//end class
