<?php

/**
 * The assurance floor, plain rows and text values of a portal assessment service.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Portal\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal\Support;

use OCA\Learniq\Service\Portal\Support\AssessmentRowValues;
use OCA\Learniq\Support\ObjectRow;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * AssessmentRowValues.
 *
 * @covers \OCA\Learniq\Service\Portal\Support\AssessmentRowValues
 * @uses   \OCA\Learniq\Support\ObjectRow
 */
class AssessmentRowValuesTest extends TestCase {

	/**
	 * A host over the given app config, exposing the trait's private steps.
	 *
	 * @param IAppConfig $appConfig The app config.
	 *
	 * @return object
	 */
	private static function host(IAppConfig $appConfig): object {
		return new class ($appConfig) {
			use AssessmentRowValues;

			public const APP_ID = 'learniq';
			public const MIN_ASSURANCE_KEY = 'some_min_assurance';
			private const ASSURANCE_ORDER = ['none' => 0, 'basic' => 1, 'substantial' => 2, 'high' => 3];

			private readonly ObjectRow $objectRow;

			public function __construct(private readonly IAppConfig $appConfig) {
				$this->objectRow = new ObjectRow();
			}

			public function assuranceFloor(): string {
				return $this->floor();
			}

			public function rowOf(mixed $object): array {
				return $this->toRow(object: $object);
			}

			public function textOf(mixed $value): string {
				return $this->text(value: $value);
			}

			public function nullWhenEmpty(string $value): ?string {
				return $this->orNull(value: $value);
			}
		};
	}//end host()

	/**
	 * The floor is read from the using class's key, defaults to basic and
	 * falls back to basic for an unknown level.
	 *
	 * @return void
	 */
	public function testTheFloorIsTheDeclaredKnownLevelElseBasic(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->exactly(2))->method('getValueString')
			->with('learniq', 'some_min_assurance', 'basic')
			->willReturnOnConsecutiveCalls('substantial', 'very-high');
		$host = self::host($appConfig);

		$this->assertSame('substantial', $host->assuranceFloor());
		$this->assertSame('basic', $host->assuranceFloor());
	}//end testTheFloorIsTheDeclaredKnownLevelElseBasic()

	/**
	 * A row is the plain array of an array or a serialisable result.
	 *
	 * @return void
	 */
	public function testARowIsThePlainArray(): void {
		$host = self::host($this->createMock(IAppConfig::class));
		$entity = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 'u-1', 'name' => 'x'];
			}
		};

		$this->assertSame(['id' => 'u-1'], $host->rowOf(['id' => 'u-1']));
		$this->assertSame(['id' => 'u-1', 'name' => 'x'], $host->rowOf($entity));
	}//end testARowIsThePlainArray()

	/**
	 * Text keeps a string as it is and turns anything else into '', and
	 * an empty text becomes null.
	 *
	 * @return void
	 */
	public function testTextAndNullWhenEmpty(): void {
		$host = self::host($this->createMock(IAppConfig::class));

		$this->assertSame(' Jan ', $host->textOf(' Jan '));
		$this->assertSame('', $host->textOf(12));
		$this->assertSame('', $host->textOf(null));
		$this->assertNull($host->nullWhenEmpty(''));
		$this->assertSame(' ', $host->nullWhenEmpty(' '));
		$this->assertSame('a', $host->nullWhenEmpty('a'));
	}//end testTextAndNullWhenEmpty()
}//end class
