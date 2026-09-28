<?php

/**
 * A null in a demo object sits on a property declared nullable.
 *
 * Gate 101 validates every demo object against its schema; a null on a string
 * property fails import in front of whoever asked for the demo. LvsResult hit
 * it in #1195, OsoImportDossier after.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Register
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-a-dossier-received-without-an-exchange-job-is-valid
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use PHPUnit\Framework\TestCase;

/**
 * Demo nulls against the register's schemas.
 */
class DemoNullsAreNullableTest extends TestCase {

	/**
	 * Every demo null lands on a nullable property.
	 *
	 * @return void
	 */
	public function testEveryDemoNullIsOnANullableProperty(): void {
		$settings = __DIR__ . '/../../../lib/Settings/';
		$register = json_decode((string)file_get_contents($settings . 'learniq_register.json'), true);
		$mock = json_decode((string)file_get_contents($settings . 'learniq_mock_register.json'), true);

		$schemas = [];
		foreach ($register['components']['schemas'] as $schema) {
			$schemas[(string)($schema['slug'] ?? '')] = $schema;
		}

		$offending = [];
		foreach ($mock['components']['objects'] as $object) {
			$properties = ($schemas[(string)($object['@self']['schema'] ?? '')]['properties'] ?? []);
			foreach ($object as $field => $value) {
				if ($value === null && isset($properties[$field]) === true && ($properties[$field]['nullable'] ?? false) !== true) {
					$offending[] = $object['@self']['schema'] . '.' . $field;
				}
			}
		}

		$this->assertSame([], array_values(array_unique($offending)), 'A demo object holds null on a property that is not nullable.');
	}//end testEveryDemoNullIsOnANullableProperty()

	/**
	 * A dossier received by hand has no exchange job.
	 *
	 * @return void
	 */
	public function testOsoImportDossierJobIsNullable(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);

		$this->assertTrue($register['components']['schemas']['OsoImportDossier']['properties']['dataExchangeJobId']['nullable'] ?? false);
	}//end testOsoImportDossierJobIsNullable()
}//end class
