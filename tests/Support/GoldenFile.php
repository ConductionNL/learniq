<?php

/**
 * Compares named cases with a recorded JSON file, one assertion per case.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Support
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

namespace OCA\Learniq\Tests\Support;

/**
 * With RECORD_GOLDEN=1 the file is written instead of compared.
 */
trait GoldenFile {

	/**
	 * Every case answers as recorded, and no case was added or lost.
	 *
	 * @param string              $file   The golden JSON file.
	 * @param array<string,mixed> $actual The answers keyed by case name.
	 *
	 * @return void
	 */
	private function assertMatchesGolden(string $file, array $actual): void {
		$actual = json_decode((string)json_encode($actual, (JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)), true);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents($file, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
		}

		$golden = json_decode((string)file_get_contents($file), true);
		self::assertSame(expected: array_keys($golden), actual: array_keys($actual));
		foreach ($golden as $name => $expected) {
			self::assertSame(expected: $expected, actual: $actual[$name], message: (string)$name);
		}
	}//end assertMatchesGolden()
}//end trait
