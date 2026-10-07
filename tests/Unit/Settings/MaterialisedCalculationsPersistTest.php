<?php

/**
 * Every materialised calculation in the shipped register has a property to land in.
 *
 * OpenRegister's CalculationOnSaveListener patches a `materialise: true`
 * calculation into the object payload before it is stored, and MagicMapper
 * then copies only the schema's declared properties into the table: a value
 * with no backing property is dropped ("Discarding 1 property the schema
 * \"Submission\" does not declare: isLate", on every write), and the render
 * path skips materialised calculations because it expects them stored. So an
 * undeclared materialised calculation is never readable at all. OpenRegister's
 * own data_subject_request register declares each one as a property for this
 * reason.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
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
 * @spec exclude register storage contract with OpenRegister, no learniq requirement owns it
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The shipped register's materialised calculations.
 */
class MaterialisedCalculationsPersistTest extends TestCase {

	/**
	 * The shipped register's schemas.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function schemas(): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true
		);

		return (array)($register['components']['schemas'] ?? []);
	}//end schemas()

	/**
	 * Every materialised calculation, as schema slug, name and declaration.
	 *
	 * @return array<int, array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, mixed>}>
	 */
	private static function materialised(): array {
		$found = [];
		foreach (self::schemas() as $schema) {
			foreach ((array)($schema['x-openregister-calculations'] ?? []) as $name => $calculation) {
				if (($calculation['materialise'] ?? false) === true) {
					$found[] = [(string)$schema['slug'], (string)$name, (array)$calculation, (array)($schema['properties'] ?? [])];
				}
			}
		}

		return $found;
	}//end materialised()

	/**
	 * Each materialised calculation has a property of its own type, so the
	 * value OpenRegister computes is stored instead of discarded.
	 *
	 * @return void
	 */
	public function testEveryMaterialisedCalculationHasABackingProperty(): void {
		$all = self::materialised();
		self::assertGreaterThan(40, count($all), 'the register still declares its materialised calculations');

		$missing = [];
		foreach ($all as [$slug, $name, $calculation, $properties]) {
			$property = ($properties[$name] ?? null);
			if (is_array($property) === false) {
				$missing[] = $slug . '.' . $name;
				continue;
			}

			self::assertSame($calculation['type'], $property['type'], $slug . '.' . $name . ' has the calculation\'s type');
			self::assertSame(($calculation['format'] ?? null), ($property['format'] ?? null), $slug . '.' . $name . ' has the calculation\'s format');
			self::assertTrue(($property['nullable'] ?? false), $slug . '.' . $name . ' accepts null (a calculation can yield null, and a client echoes it back)');
			self::assertTrue(($property['readOnly'] ?? false), $slug . '.' . $name . ' is left out of edit forms');
		}

		self::assertSame([], $missing, 'materialised calculations OpenRegister would discard on every write');
	}//end testEveryMaterialisedCalculationHasABackingProperty()

	/**
	 * The three the live log named: a Submission and an Assignment carrying
	 * their computed values pass the shipped schema fragment.
	 *
	 * @return void
	 */
	public function testTheLoggedValuesPassTheirSchemas(): void {
		$schemas = [];
		foreach (self::schemas() as $schema) {
			$schemas[(string)$schema['slug']] = $schema;
		}

		$validator = new Validator();
		$cases = [
			'submission' => ['isLate' => true],
			'assignment' => ['isOverdue' => false, 'submissionCount' => 3],
		];
		foreach ($cases as $slug => $values) {
			$fragment = [
				'type' => 'object',
				'properties' => array_intersect_key((array)$schemas[$slug]['properties'], $values),
			];
			$declared = array_keys($fragment['properties']);
			sort($declared);
			$sent = array_keys($values);
			sort($sent);
			self::assertSame($sent, $declared, $slug . ' declares ' . implode(', ', $sent));

			$result = $validator->validate(
				json_decode((string)json_encode($values)),
				json_decode((string)json_encode($fragment))
			);
			self::assertTrue($result->isValid(), $slug . ' accepts ' . json_encode($values));
		}
	}//end testTheLoggedValuesPassTheirSchemas()
}//end class
