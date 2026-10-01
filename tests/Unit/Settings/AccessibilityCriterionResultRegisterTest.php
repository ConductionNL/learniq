<?php

/**
 * AccessibilityCriterionResult in the shipped register.
 *
 * governance-wcag-evidence-report task 1.1: the schema exists in the register
 * the app imports, the payload the conformance dialog saves passes it (the
 * real schema fragment, validated with Opis), and the demo register ships
 * rows that pass it too.
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
 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-per-criterion-conformance-record
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The shipped AccessibilityCriterionResult schema.
 */
class AccessibilityCriterionResultRegisterTest extends TestCase {

	/**
	 * The shipped register file.
	 *
	 * @param string $file The file under lib/Settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function register(string $file = 'learniq_register.json'): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/' . $file), true);
	}//end register()

	/**
	 * The schema, by slug.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema(): array {
		$schemas = array_column(self::register()['components']['schemas'], null, 'slug');
		self::assertArrayHasKey('accessibility-criterion-result', $schemas);

		return $schemas['accessibility-criterion-result'];
	}//end schema()

	/**
	 * The schema as plain JSON Schema: OpenRegister keys stripped, optional
	 * scalars nullable (OpenRegister stores an unset optional as null).
	 *
	 * @return string
	 */
	private static function validatable(): string {
		$clean = self::withoutOrKeys(schema: self::schema());
		foreach ($clean['properties'] as $name => $property) {
			if (in_array($name, $clean['required'], true) === false && isset($property['enum']) === false && is_string($property['type'] ?? null) === true) {
				$clean['properties'][$name]['type'] = [$property['type'], 'null'];
			}
		}

		return (string)json_encode($clean);
	}//end validatable()

	/**
	 * The schema without OpenRegister's own keys.
	 *
	 * @param array<string, mixed> $schema A register schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function withoutOrKeys(array $schema): array {
		$clean = [];
		foreach ($schema as $key => $value) {
			if ($key === '$ref' || str_starts_with((string)$key, 'x-') === true || in_array($key, ['slug', 'icon', 'version'], true) === true) {
				continue;
			}

			$clean[$key] = $value;
			if (is_array($value) === true && $key !== 'required' && $key !== 'enum') {
				$clean[$key] = self::withoutOrKeys(schema: $value);
			}
		}

		return $clean;
	}//end withoutOrKeys()

	/**
	 * The register lists the schema, and the statement still publishes through the guard.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-per-criterion-conformance-record
	 */
	public function testTheRegisterShipsTheSchema(): void {
		$register = self::register();
		$learniq = $register['components']['registers']['learniq'];
		self::assertContains('accessibility-criterion-result', $learniq['schemas']);

		$schema = self::schema();
		self::assertSame(['accessibilityStatementId', 'wcagCriterion', 'result', 'tenant_id'], $schema['required']);
		self::assertSame(['pass', 'fail', 'not-applicable', 'not-tested'], $schema['properties']['result']['enum']);
		self::assertSame('AccessibilityLimitation', $schema['properties']['limitationId']['$ref']);

		$statement = array_column($register['components']['schemas'], null, 'slug')['accessibility-statement'];
		self::assertSame(
			'OCA\\Learniq\\Lifecycle\\AccessibilityStatementPublishGuard',
			$statement['x-openregister-lifecycle']['transitions']['publish']['requires']
		);
	}//end testTheRegisterShipsTheSchema()

	/**
	 * What the dialog saves (resultPayload in src/utils/conformance.js) passes the real schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#scenario-an-owner-records-results
	 */
	public function testTheSavedPayloadsPassTheRealSchema(): void {
		$validator = new Validator();
		$schema = self::validatable();

		$pass = [
			'accessibilityStatementId' => '0b7c5a3e-0000-4000-8000-000000000001',
			'wcagCriterion' => '1.4.3',
			'level' => 'AA',
			'result' => 'pass',
			'tenant_id' => '0b7c5a3e-0000-4000-8000-0000000000aa',
			'method' => 'axe and manual check',
			'evidenceReference' => 'https://example.org/report',
			'testedOn' => '2026-09-01',
		];
		$fail = array_merge($pass, ['wcagCriterion' => '1.4.10', 'result' => 'fail', 'limitationId' => '0b7c5a3e-0000-4000-8000-000000000002']);
		$untested = ['accessibilityStatementId' => $pass['accessibilityStatementId'], 'wcagCriterion' => '4.1.3', 'level' => 'AA', 'result' => 'not-tested', 'tenant_id' => $pass['tenant_id']];
		$stored = array_merge($untested, ['method' => null, 'evidenceReference' => null, 'testedOn' => null, 'testedBy' => null, 'limitationId' => null]);

		foreach (['pass' => $pass, 'fail' => $fail, 'untested' => $untested, 'stored' => $stored] as $label => $payload) {
			$result = $validator->validate(json_decode((string)json_encode($payload)), $schema);
			self::assertTrue($result->isValid(), $label . ': ' . json_encode($result->error()?->message()));
		}

		$wrongNumber = array_merge($pass, ['wcagCriterion' => '1.4.3 Contrast']);
		self::assertFalse($validator->validate(json_decode((string)json_encode($wrongNumber)), $schema)->isValid(), 'control: the criterion is a number');

		$noResult = $pass;
		unset($noResult['result']);
		self::assertFalse($validator->validate(json_decode((string)json_encode($noResult)), $schema)->isValid(), 'control: a record needs a result');
	}//end testTheSavedPayloadsPassTheRealSchema()

	/**
	 * The demo register ships at least three results that pass the same schema.
	 *
	 * @return void
	 */
	public function testTheDemoRegisterShipsValidResults(): void {
		$validator = new Validator();
		$schema = self::validatable();
		$rows = [];
		foreach ((self::register(file: 'learniq_mock_register.json')['components']['objects'] ?? []) as $row) {
			if (($row['@self']['schema'] ?? null) === 'accessibility-criterion-result') {
				$rows[] = $row;
			}
		}

		self::assertGreaterThanOrEqual(3, count($rows));
		foreach ($rows as $row) {
			unset($row['@self']);
			$result = $validator->validate(json_decode((string)json_encode($row)), $schema);
			self::assertTrue($result->isValid(), json_encode($result->error()?->message()));
		}
	}//end testTheDemoRegisterShipsValidResults()
}//end class
