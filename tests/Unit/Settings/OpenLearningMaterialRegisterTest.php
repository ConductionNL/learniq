<?php

/**
 * Open learning material register tests.
 *
 * Material carries its source and attribution, a linked Material needs no
 * file, and the schema change bumps both the Material schema and the register.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

class OpenLearningMaterialRegisterTest extends TestCase {

	/**
	 * A register file, decoded.
	 *
	 * @param string $file The file under lib/Settings.
	 *
	 * @return array<string, mixed> The register.
	 */
	private function register(string $file): array {
		return json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/' . $file), true, 512, JSON_THROW_ON_ERROR);
	}//end register()

	/**
	 * The Material schema.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function material(): array {
		return $this->register(file: 'learniq_register.json')['components']['schemas']['Material'];
	}//end material()

	public function testMaterialCarriesSourceAndAttribution(): void {
		$properties = $this->material()['properties'];

		$this->assertSame(expected: ['own', 'edurep', 'edusources'], actual: $properties['source']['enum']);
		$this->assertSame(expected: 'own', actual: $properties['source']['default']);
		$this->assertSame(expected: 'uri', actual: $properties['sourceUrl']['format']);
		$this->assertSame(expected: 'string', actual: $properties['authors']['items']['type']);
		$this->assertSame(expected: ['OpenAccess', 'RestrictedAccess', 'ClosedAccess'], actual: $properties['accessRights']['enum']);
		$this->assertFalse($properties['nonCommercial']['default']);
		$this->assertArrayHasKey(key: 'sourceId', array: $properties);
		$this->assertArrayHasKey(key: 'sourceCollection', array: $properties);
	}//end testMaterialCarriesSourceAndAttribution()

	public function testALinkedMaterialNeedsNoFile(): void {
		$this->assertSame(expected: ['title', 'kind', 'tenant_id'], actual: $this->material()['required']);
	}//end testALinkedMaterialNeedsNoFile()

	public function testTheSchemaChangeBumpsSchemaAndRegister(): void {
		// Material 0.2.1 gained withheldReason; both versions only move on.
		$this->assertTrue(
			condition: version_compare($this->material()['version'], '0.2.1', '>='),
			message: 'the Material version went back below 0.2.1'
		);
		// The register version moves on with every later schema change; this
		// change needed it at 0.50.0 at least.
		$this->assertTrue(
			condition: version_compare($this->register(file: 'learniq_register.json')['info']['version'], '0.50.0', '>='),
			message: 'the register version went back below the one this change bumped it to'
		);
	}//end testTheSchemaChangeBumpsSchemaAndRegister()

	/**
	 * Learners read a Material only while it is not withheld; staff always.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#scenario-non-commercial-material-is-flagged-in-a-paid-course
	 */
	public function testWithheldMaterialIsNotReadableByLearners(): void {
		$material = $this->material();
		$this->assertSame(expected: ['', 'non-commercial'], actual: $material['properties']['withheldReason']['enum']);
		$this->assertNotContains(needle: 'authenticated', haystack: $material['authorization']['read']);
		$this->assertContains(needle: 'instructors', haystack: $material['authorization']['read']);
		$this->assertContains(
			needle: ['group' => 'authenticated', 'match' => ['withheldReason' => ['$exists' => false]]],
			haystack: $material['authorization']['read']
		);
		$this->assertContains(
			needle: ['group' => 'authenticated', 'match' => ['withheldReason' => ['$eq' => '']]],
			haystack: $material['authorization']['read']
		);
	}//end testWithheldMaterialIsNotReadableByLearners()

	public function testTheDemoDataHoldsALinkedMaterial(): void {
		$linked = array_values(
			array_filter(
				$this->register(file: 'learniq_mock_register.json')['components']['objects'],
				static fn (array $object): bool => ($object['@self']['schema'] ?? '') === 'material' && ($object['source'] ?? 'own') === 'edurep'
			)
		);

		$this->assertCount(expectedCount: 1, haystack: $linked);
		$this->assertArrayNotHasKey(key: 'fileRef', array: $linked[0]);
		$this->assertNotEmpty($linked[0]['sourceUrl']);
		$this->assertNotEmpty($linked[0]['authors']);
	}//end testTheDemoDataHoldsALinkedMaterial()
}//end class
