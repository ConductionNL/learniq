<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Settings;

use OCA\Learniq\Service\Settings\SchoolPhone;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The school's phone number the public certificate page shows.
 *
 * @spec openspec/changes/certificate-verification-and-key-rotation/specs/certification/spec.md#requirement-the-verification-link-opens-a-page-that-says-in-words-what-was-checked
 */
class SchoolPhoneTest extends TestCase {

	/**
	 * Stored values and the number each reads as.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function storedValues(): array {
		return [
			'unset' => ['', ''],
			'a Dutch number' => ['020 123 45 67', '020 123 45 67'],
			'trimmed' => ["  +31 (0)20-123.45.67 \n", '+31 (0)20-123.45.67'],
			'a URL is no number' => ['https://example.org', ''],
			'markup is no number' => ['<b>020</b>', ''],
			'no digit is no number' => ['(-)', ''],
			'a plus inside is no number' => ['020+123', ''],
			'too long' => [str_repeat('1', SchoolPhone::MAX_LENGTH + 1), ''],
			'the longest kept' => [str_repeat('1', SchoolPhone::MAX_LENGTH), str_repeat('1', SchoolPhone::MAX_LENGTH)],
		];
	}//end storedValues()

	/**
	 * A stored value reads as the number, or as no number.
	 *
	 * @param string $stored   The app config value.
	 * @param string $expected The number the page gets.
	 *
	 * @return void
	 */
	#[DataProvider('storedValues')]
	public function testAStoredValueReadsAsItsNumberOrNone(string $stored, string $expected): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())
			->method('getValueString')
			->with('learniq', 'schoolPhone', '')
			->willReturn($stored);

		$this->assertSame($expected, (new SchoolPhone($appConfig))->phone());
	}//end testAStoredValueReadsAsItsNumberOrNone()
}//end class
