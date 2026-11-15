<?php

/**
 * Learniq LearnerProfile age-calculation register test.
 *
 * OpenRegister refuses a schema whose property carries a key outside its
 * property vocabulary. `ageYears` and the two self-service-rights flags
 * declared `expression` and `materialise` directly on the property, which is
 * not a vocabulary key, so a clean install rejected the whole LearnerProfile
 * schema ("Unknown property key(s) 'expression, materialise' at '/ageYears'")
 * and the register imported without it. A property-level calculation goes
 * under `calculation`, the same declaration `x-openregister-calculations`
 * holds, and it reads a property by its own name: `@self.` only reaches the
 * object's system fields (id, uuid, register, schema, owner, created,
 * updated), and birthDate is not one of them.
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
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-learnerprofile-declares-age-derived-self-service-rights-flags
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Pins the shape OpenRegister accepts for the LearnerProfile age calculations.
 */
class LearnerProfileAgeCalculationRegisterTest extends TestCase {

	/**
	 * The system fields a calculation may read through `@self.`.
	 */
	private const SELF_FIELDS = ['id', 'uuid', 'register', 'schema', 'owner', 'created', 'updated'];

	/**
	 * The shipped schemas.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemas(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);

		return $register['components']['schemas'];
	}//end schemas()

	/**
	 * No property in the register carries a bare `expression` or
	 * `materialise`: OpenRegister refuses both as unknown property keys.
	 *
	 * @return void
	 */
	public function testNoPropertyDeclaresACalculationOutsideTheCalculationKey(): void {
		$offenders = [];
		foreach ($this->schemas() as $schemaName => $schema) {
			foreach (($schema['properties'] ?? []) as $propertyName => $property) {
				if (is_array($property) === false) {
					continue;
				}

				foreach (['expression', 'materialise'] as $key) {
					if (array_key_exists($key, $property) === true) {
						$offenders[] = $schemaName . '.' . $propertyName . '.' . $key;
					}
				}
			}
		}

		self::assertSame([], $offenders);
	}//end testNoPropertyDeclaresACalculationOutsideTheCalculationKey()

	/**
	 * The three age-derived properties are materialised calculations of the
	 * right type, reading birthDate by its property name.
	 *
	 * @return void
	 */
	public function testTheAgeFieldsAreMaterialisedCalculationsOverBirthDate(): void {
		$properties = $this->schemas()['LearnerProfile']['properties'];

		self::assertArrayHasKey('birthDate', $properties);

		$expected = [
			'ageYears' => 'integer',
			'hasPartialSelfServiceRights' => 'boolean',
			'hasFullSelfServiceRights' => 'boolean',
		];
		foreach ($expected as $name => $type) {
			$calculation = $properties[$name]['calculation'];

			self::assertSame($type, $properties[$name]['type'], $name);
			self::assertSame($type, $calculation['type'], $name);
			self::assertTrue($calculation['materialise'], $name);

			$encoded = (string)json_encode($calculation['expression']);
			self::assertStringContainsString('"dateDiff"', $encoded, $name);
			self::assertStringContainsString('{"prop":"birthDate"}', $encoded, $name);
			self::assertStringContainsString('"unit":"years"', $encoded, $name);
		}

		self::assertSame(12, $properties['hasPartialSelfServiceRights']['calculation']['expression']['gte'][1]);
		self::assertSame(16, $properties['hasFullSelfServiceRights']['calculation']['expression']['gte'][1]);
	}//end testTheAgeFieldsAreMaterialisedCalculationsOverBirthDate()

	/**
	 * A property-level calculation reads `@self.` only for a system field.
	 *
	 * @return void
	 */
	public function testSelfReferencesNameOnlySystemFields(): void {
		$offenders = [];
		foreach ($this->schemas() as $schemaName => $schema) {
			foreach (($schema['properties'] ?? []) as $propertyName => $property) {
				if (is_array($property) === false || isset($property['calculation']) === false) {
					continue;
				}

				preg_match_all('/"prop":"@self\.([^"]+)"/', (string)json_encode($property['calculation']), $matches);
				foreach ($matches[1] as $field) {
					if (in_array($field, self::SELF_FIELDS, true) === false) {
						$offenders[] = $schemaName . '.' . $propertyName . ' reads @self.' . $field;
					}
				}
			}
		}

		self::assertSame([], $offenders);
	}//end testSelfReferencesNameOnlySystemFields()
}//end class
