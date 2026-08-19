<?php

/**
 * Guards scholiq's XML parsing against Nextcloud's XXE hardening.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Contract
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
 * @spec exclude Test-infrastructure guard; asserts a Nextcloud runtime constraint, not a Scholiq requirement.
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Contract;

use DOMDocument;
use OCA\Learniq\Service\CommonCartridgeParser;
use OCA\Learniq\Service\MoodleBackupParser;
use PHPUnit\Framework\TestCase;

/**
 * Nextcloud's `OC::init()` runs
 *
 *     libxml_set_external_entity_loader(static fn () => null);
 *
 * (server `lib/base.php`) to block XXE. libxml routes the PRIMARY document of
 * `DOMDocument::load($path)` through that same loader, so inside a real
 * Nextcloud `load()` ALWAYS returns false — while `loadXML()` on a string is
 * unaffected.
 *
 * Every scholiq XML parser used `load($path)`, so Common Cartridge, Moodle
 * backup, QTI and course-package import could never have worked on a real
 * instance. The unit suite could not see it because it ran without Nextcloud
 * bootstrapped; in CI, where the app IS loaded into a server tree, those tests
 * were among the failures — but the PHPUnit job had never run, so nothing ever
 * reported it.
 *
 * These tests install the same loader and assert the parsers still work, so a
 * regression to `load($path)` is one named red test rather than a silent
 * production outage.
 *
 * @coversNothing
 */
final class NextcloudXmlLoaderContractTest extends TestCase {

	/**
	 * Temp directory holding this test's fixtures.
	 *
	 * @var string
	 */
	private string $dir = '';

	/**
	 * Create a fixture directory and install Nextcloud's entity loader.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->dir = sys_get_temp_dir() . '/scholiq_xmlguard_' . bin2hex(random_bytes(6));
		mkdir($this->dir, 0700, true);

		libxml_set_external_entity_loader(static function () {
			return null;
		});

	}//end setUp()

	/**
	 * Restore libxml's default loader and clean up.
	 *
	 * The loader is process-global, so leaving it installed would leak into
	 * every later test in the run.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		libxml_set_external_entity_loader(null);

		foreach ((glob($this->dir . '/*') ?: []) as $file) {
			unlink($file);
		}

		if (is_dir($this->dir) === true) {
			rmdir($this->dir);
		}

		parent::tearDown();

	}//end tearDown()

	/**
	 * The premise: this is the behaviour the parsers have to survive.
	 *
	 * If this ever starts failing, Nextcloud or libxml changed and the
	 * `loadXML()` workaround in the parsers can be revisited.
	 *
	 * @return void
	 */
	public function testDomDocumentLoadFromPathIsBrokenByNextcloudsEntityLoader(): void {
		$path = $this->dir . '/probe.xml';
		file_put_contents($path, '<?xml version="1.0"?><manifest><a/></manifest>');

		$viaPath = new DOMDocument();
		libxml_use_internal_errors(true);
		$loadedViaPath = $viaPath->load($path);
		libxml_clear_errors();

		$viaString = new DOMDocument();
		libxml_use_internal_errors(true);
		$loadedViaString = $viaString->loadXML((string)file_get_contents($path));
		libxml_clear_errors();

		$this->assertFalse(
			$loadedViaPath,
			'DOMDocument::load($path) is expected to FAIL under Nextcloud\'s external entity loader. '
			. 'If it now succeeds, this constraint is gone and the parsers can go back to load().'
		);

		$this->assertTrue(
			$loadedViaString,
			'DOMDocument::loadXML($string) must keep working — it is what every scholiq parser relies on.'
		);

	}//end testDomDocumentLoadFromPathIsBrokenByNextcloudsEntityLoader()

	/**
	 * CommonCartridgeParser parses a manifest with the loader installed.
	 *
	 * @return void
	 */
	public function testCommonCartridgeParserWorksUnderNextcloudsEntityLoader(): void {
		file_put_contents(
			$this->dir . '/imsmanifest.xml',
			'<?xml version="1.0"?>'
			. '<manifest xmlns="http://www.imsglobal.org/xsd/imscp_v1p1">'
			. '<organizations><organization><item identifier="i1" identifierref="r1">'
			. '<title>Lesson One</title></item></organization></organizations>'
			. '<resources><resource identifier="r1" type="webcontent" href="a.html"/></resources>'
			. '</manifest>'
		);

		$parsed = (new CommonCartridgeParser())->parseManifest(dir: $this->dir);

		$this->assertCount(1, $parsed['organizationNodes']);
		$this->assertSame('Lesson One', $parsed['organizationNodes'][0]['title']);
		$this->assertCount(1, $parsed['resources']);

	}//end testCommonCartridgeParserWorksUnderNextcloudsEntityLoader()

	/**
	 * MoodleBackupParser parses a manifest with the loader installed.
	 *
	 * @return void
	 */
	public function testMoodleBackupParserWorksUnderNextcloudsEntityLoader(): void {
		file_put_contents(
			$this->dir . '/moodle_backup.xml',
			'<?xml version="1.0"?><moodle_backup><information><contents><activities>'
			. '<activity><moduleid>1</moduleid><modulename>quiz</modulename>'
			. '<title>Quiz One</title><directory>activities/quiz_1</directory></activity>'
			. '</activities></contents></information></moodle_backup>'
		);

		$parsed = (new MoodleBackupParser())->parseManifest(dir: $this->dir);

		$this->assertNotEmpty($parsed['activities']);

	}//end testMoodleBackupParserWorksUnderNextcloudsEntityLoader()

}//end class
