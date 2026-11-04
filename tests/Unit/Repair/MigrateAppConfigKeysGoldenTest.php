<?php

/**
 * Learniq MigrateAppConfigKeys golden tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Repair
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

namespace OCA\Learniq\Tests\Unit\Repair;

use OCA\Learniq\Repair\MigrateAppConfigKeys;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins every config read and write, the output and the log of run() per
 * scenario against tests/Unit/Repair/golden/MigrateAppConfigKeys.json. With
 * RECORD_GOLDEN=1 the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Repair\MigrateAppConfigKeys
 */
class MigrateAppConfigKeysGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/MigrateAppConfigKeys.json';

	/**
	 * Every scenario equals the recorded golden.
	 *
	 * @return void
	 */
	public function testRunMatchesGolden(): void {
		$scenarios = [
			'mixed' => [['enabled', 'installed_version', 'types', 'a', 'b', 'c', 'fail', 'd'], ['a' => 'x', 'b' => '', 'c' => 'y', 'fail' => 'z', 'd' => 'w', 'enabled' => 'yes'], ['c' => 'kept']],
			'nothing stored' => [[], [], []],
			'keys unreadable' => ['throw', [], []],
			'all migrated' => [['p', 'q'], ['p' => '1', 'q' => '2'], []],
		];
		$actual = [];
		foreach ($scenarios as $name => [$keys, $old, $new]) {
			$calls = [];
			$config = $this->createMock(IAppConfig::class);
			$config->method('getKeys')->willReturnCallback(
				static function (string $app) use ($keys, &$calls): array {
					$calls[] = ['getKeys', $app];
					if ($keys === 'throw') {
						throw new RuntimeException('no keys');
					}

					return $keys;
				}
			);
			$config->method('getValueString')->willReturnCallback(
				static function (string $app, string $key, string $default = '') use ($old, $new, &$calls): string {
					$calls[] = ['get', $app, $key];
					$values = $new;
					if ($app === 'scholiq') {
						$values = $old;
					}

					return $values[$key] ?? $default;
				}
			);
			$config->method('setValueString')->willReturnCallback(
				static function (string $app, string $key, string $value) use (&$calls): bool {
					$calls[] = ['set', $app, $key, $value];
					if ($key === 'fail') {
						throw new RuntimeException('read only');
					}

					return true;
				}
			);
			$output = $this->createMock(IOutput::class);
			$output->method('info')->willReturnCallback(
				static function (string $message) use (&$calls): void {
					$calls[] = ['info', $message];
				}
			);
			$logger = new CapturingLogger();
			(new MigrateAppConfigKeys($config, $logger))->run($output);
			$actual[$name] = ['calls' => $calls, 'log' => $logger->records];
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode((string)json_encode($actual), true));
	}//end testRunMatchesGolden()
}//end class
