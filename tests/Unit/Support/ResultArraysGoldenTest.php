<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Support;

use JsonSerializable;
use OCA\Learniq\Lifecycle\AccessibilityStatementPublishGuard;
use OCA\Learniq\Service\Accessibility\ConformanceEvidence;
use OCA\Learniq\Service\ExternalTrainingImport;
use OCA\Learniq\Service\ExternalTrainingLearnerMatch;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;

/**
 * Pins the static rows() of AccessibilityStatementPublishGuard,
 * ExternalTrainingImport and ExternalTrainingLearnerMatch (arrays kept,
 * serialisable objects as their data, anything else dropped, keys renumbered)
 * and the static idOf() of ConformanceEvidence and ExternalTrainingLearnerMatch
 * (id, else uuid, else @self.id when neither is set; '' unless a string).
 */
class ResultArraysGoldenTest extends TestCase {

	/**
	 * The classes with rows().
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function rowReaders(): array {
		return [
			'accessibility statement guard' => [AccessibilityStatementPublishGuard::class],
			'external training import' => [ExternalTrainingImport::class],
			'external training learner match' => [ExternalTrainingLearnerMatch::class],
		];
	}//end rowReaders()

	/**
	 * The classes with idOf().
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function idReaders(): array {
		return [
			'conformance evidence' => [ConformanceEvidence::class],
			'external training learner match' => [ExternalTrainingLearnerMatch::class],
		];
	}//end idReaders()

	/**
	 * Arrays are kept, serialisable objects become their data, the rest goes.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 *
	 * @dataProvider rowReaders
	 */
	public function testTheResultsBecomeArrays(string $class): void {
		$serialisable = new class implements JsonSerializable {
			/**
			 * The data.
			 *
			 * @return string
			 */
			public function jsonSerialize(): mixed {
				return 'scalar';
			}//end jsonSerialize()
		};
		$entity = new class implements JsonSerializable {
			/**
			 * The data.
			 *
			 * @return array<string, string>
			 */
			public function jsonSerialize(): mixed {
				return ['id' => 'e-1'];
			}//end jsonSerialize()
		};

		$rows = (new ReflectionMethod($class, 'rows'))->invoke(null, [5 => ['id' => 'a-1'], 'x' => 'string', 7 => $entity, 8 => new stdClass(), 9 => $serialisable, 10 => null]);

		self::assertSame([['id' => 'a-1'], ['id' => 'e-1'], ['scalar']], $rows);
	}//end testTheResultsBecomeArrays()

	/**
	 * The id is id, else uuid, else @self.id; '' unless a string.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 *
	 * @dataProvider idReaders
	 */
	public function testTheIdIsReadInOrder(string $class): void {
		$idOf = static fn (array $row): string => (new ReflectionMethod($class, 'idOf'))->invoke(null, $row);

		self::assertSame('i-1', $idOf(['id' => 'i-1', 'uuid' => 'u-1', '@self' => ['id' => 's-1']]));
		self::assertSame('u-1', $idOf(['uuid' => 'u-1', '@self' => ['id' => 's-1']]));
		self::assertSame('s-1', $idOf(['@self' => ['id' => 's-1']]));
		self::assertSame('', $idOf(['id' => 42, '@self' => ['id' => 's-1']]));
		self::assertSame('', $idOf(['@self' => 'not an array']));
		self::assertSame('', $idOf(['@self' => ['id' => 7]]));
		self::assertSame('', $idOf([]));
	}//end testTheIdIsReadInOrder()
}//end class
