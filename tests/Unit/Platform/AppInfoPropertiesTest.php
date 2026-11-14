<?php

/**
 * Learniq platform properties: one licence everywhere, and no vendor host in code.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Platform
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-001
 * @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-002
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Keeps the platform properties true: the licence and the absence of a vendor host in code.
 */
class AppInfoPropertiesTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * info.xml, LICENSE and composer.json all name EUPL-1.2.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-002
	 */
	public function testEveryDeclarationNamesTheEupl(): void {
		$info = simplexml_load_file($this->root() . '/appinfo/info.xml');
		self::assertNotFalse($info);
		self::assertSame('EUPL-1.2', trim((string) $info->licence));

		$licence = (string) file_get_contents($this->root() . '/LICENSE');
		self::assertStringContainsString('EUROPEAN UNION PUBLIC LICENCE v. 1.2', $licence);

		$composer = json_decode((string) file_get_contents($this->root() . '/composer.json'), true);
		self::assertSame('EUPL-1.2', $composer['license'] ?? null);
	}//end testEveryDeclarationNamesTheEupl()

	/**
	 * No PHP file under lib/ holds a conduction.nl or conduction.cloud URL outside a docblock,
	 * so the app never calls home to its vendor.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-001
	 */
	public function testNoVendorHostOutsideADocblock(): void {
		self::assertSame([], $this->vendorHostsIn(directory: $this->root() . '/lib'));
	}//end testNoVendorHostOutsideADocblock()

	/**
	 * The scan sees a vendor host in a string, and leaves a docblock alone.
	 *
	 * @return void
	 */
	public function testTheScanFindsAHostInCode(): void {
		$dir = sys_get_temp_dir() . '/learniq-plat-' . bin2hex(random_bytes(4));
		mkdir($dir);
		file_put_contents($dir . '/Calls.php', "<?php\n/**\n * @link https://conduction.nl\n */\n\$url = 'https://api.conduction.cloud/ping';\n");

		try {
			$found = $this->vendorHostsIn(directory: $dir);
		} finally {
			unlink($dir . '/Calls.php');
			rmdir($dir);
		}

		self::assertCount(1, $found);
		self::assertStringContainsString('Calls.php:5', $found[0]);
	}//end testTheScanFindsAHostInCode()

	/**
	 * Every vendor host outside a docblock, as `file:line`.
	 *
	 * @param string $directory The directory to scan.
	 *
	 * @return array<int, string>
	 */
	private function vendorHostsIn(string $directory): array {
		$found = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
				if (is_array($token) === true && $token[0] !== T_DOC_COMMENT && preg_match('#conduction\.(nl|cloud)#i', $token[1]) === 1) {
					$found[] = $file->getPathname() . ':' . $token[2];
				}
			}
		}

		return $found;
	}//end vendorHostsIn()
}//end class
