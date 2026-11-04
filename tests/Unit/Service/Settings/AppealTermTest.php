<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Settings;

use OCA\Learniq\Service\Settings\AppealTerm;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The exam board's appeal term, an app setting of 42 days by default.
 *
 * @spec openspec/specs/exam-board/spec.md#scenario-the-school-sets-its-own-appeal-term
 */
class AppealTermTest extends TestCase {

	/**
	 * Stored values and the term each stands for.
	 *
	 * @return array<string,array{0:string,1:int}>
	 */
	public static function storedValues(): array {
		return [
			'unset' => ['', 42],
			'set' => ['28', 28],
			'padded' => [' 56 ', 56],
			'zero' => ['0', 42],
			'too long' => ['366', 42],
			'longest' => ['365', 365],
			'not a number' => ['six weeks', 42],
			'negative' => ['-5', 42],
			'fraction' => ['28.5', 42],
		];
	}//end storedValues()

	/**
	 * A stored value reads as its term, anything else as the default.
	 *
	 * @param string $stored   The stored value.
	 * @param int    $expected The term.
	 *
	 * @return void
	 */
	#[DataProvider('storedValues')]
	public function testAStoredValueReadsAsItsTermOrTheDefault(string $stored, int $expected): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('learniq', 'examBoardAppealDays', '')->willReturn($stored);

		self::assertSame($expected, (new AppealTerm($config))->days());
	}//end testAStoredValueReadsAsItsTermOrTheDefault()
}//end class
