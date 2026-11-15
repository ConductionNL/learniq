<?php

/**
 * Unit tests for ScormManifestParser.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Service\CoursePackage
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/content-scorm-package-upload/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CoursePackage;

use InvalidArgumentException;
use OCA\Learniq\Service\CoursePackage\ScormManifestParser;
use PHPUnit\Framework\TestCase;

/**
 * The version, the SCOs in organization order and the file list of a SCORM manifest.
 *
 * @covers \OCA\Learniq\Service\CoursePackage\ScormManifestParser
 */
class ScormManifestParserTest extends TestCase {

	/**
	 * A fixture manifest.
	 *
	 * @param string $name The fixture file.
	 *
	 * @return string
	 */
	private function fixture(string $name): string {
		return (string)file_get_contents(__DIR__.'/../../../fixtures/scorm/'.$name);
	}//end fixture()

	/**
	 * A single-SCO SCORM 1.2 package.
	 *
	 * @return void
	 */
	public function testASingleSco12Package(): void {
		$parsed = (new ScormManifestParser())->parse(xml: $this->fixture(name: 'single-sco-12.xml'));

		$this->assertSame(
			[
				'version' => '1.2',
				'title' => 'Fire safety',
				'scos' => [['identifier' => 'item-1', 'title' => 'Fire safety basics', 'href' => 'index.html']],
				'files' => ['index.html', 'scripts/api.js'],
			],
			$parsed
		);
	}//end testASingleSco12Package()

	/**
	 * Three SCOs come in learner order from the default organization, nested
	 * items included; an asset is not a SCO; xml:base and parameters apply.
	 *
	 * @return void
	 */
	public function testThreeScosInOrganizationOrder(): void {
		$parsed = (new ScormManifestParser())->parse(xml: $this->fixture(name: 'three-scos-12.xml'));

		$this->assertSame('1.2', $parsed['version']);
		$this->assertSame('Data protection', $parsed['title']);
		$this->assertSame(
			[
				['identifier' => 'lesson-1', 'title' => 'What is personal data', 'href' => 'content/a/index.html'],
				['identifier' => 'lesson-2', 'title' => 'Lawful grounds', 'href' => 'content/b/start.html?page=2'],
				['identifier' => 'lesson-3', 'title' => 'Data breaches', 'href' => 'content/c/index.html'],
			],
			$parsed['scos']
		);
		$this->assertSame(
			['content/a/index.html', 'content/shared/style.css', 'content/b/start.html', 'content/glossary.pdf', 'content/c/index.html'],
			$parsed['files']
		);
	}//end testThreeScosInOrganizationOrder()

	/**
	 * A SCORM 2004 package is read as 2004.
	 *
	 * @return void
	 */
	public function testASingleSco2004Package(): void {
		$parsed = (new ScormManifestParser())->parse(xml: $this->fixture(name: 'single-sco-2004.xml'));

		$this->assertSame('2004', $parsed['version']);
		$this->assertSame([['identifier' => 'i1', 'title' => 'Handling complaints', 'href' => 'launch.html']], $parsed['scos']);
	}//end testASingleSco2004Package()

	/**
	 * The 2004 namespace alone marks a 2004 package.
	 *
	 * @return void
	 */
	public function testThe2004NamespaceAloneMarks2004(): void {
		$xml = str_replace('<schemaversion>2004 4th Edition</schemaversion>', '', $this->fixture(name: 'single-sco-2004.xml'));

		$this->assertSame('2004', (new ScormManifestParser())->parse(xml: $xml)['version']);
	}//end testThe2004NamespaceAloneMarks2004()

	/**
	 * SCORM packages are told apart from other IMS manifests.
	 *
	 * @return void
	 */
	public function testIsScormTellsAScormManifestApart(): void {
		$parser = new ScormManifestParser();
		$cartridge = '<manifest xmlns="http://www.imsglobal.org/xsd/imsccv1p3/imscp_v1p1"><resources>'
			.'<resource identifier="r" type="webcontent" href="a.html"/></resources></manifest>';

		$this->assertTrue($parser->isScorm(xml: $this->fixture(name: 'single-sco-12.xml')));
		$this->assertTrue($parser->isScorm(xml: $this->fixture(name: 'single-sco-2004.xml')));
		$this->assertFalse($parser->isScorm(xml: $cartridge));
		$this->assertFalse($parser->isScorm(xml: 'not xml'));
	}//end testIsScormTellsAScormManifestApart()

	/**
	 * Manifests the parser refuses.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function refused(): array {
		return [
			'not xml' => ['not xml'],
			'not a manifest' => ['<package/>'],
			'doctype' => ['<?xml version="1.0"?><!DOCTYPE manifest [<!ENTITY x "y">]><manifest/>'],
			'no sco' => ['<manifest xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_rootv1p2"><organizations default="o"><organization identifier="o">'
				.'<item identifier="i" identifierref="r"><title>T</title></item></organization></organizations><resources>'
				.'<resource identifier="r" adlcp:scormtype="asset" href="a.pdf"/></resources></manifest>'],
		];
	}//end refused()

	/**
	 * @param string $xml The manifest text.
	 *
	 * @dataProvider refused
	 *
	 * @return void
	 */
	public function testARefusedManifestThrows(string $xml): void {
		$this->expectException(InvalidArgumentException::class);
		(new ScormManifestParser())->parse(xml: $xml);
	}//end testARefusedManifestThrows()
}//end class
