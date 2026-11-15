<?php

/**
 * The learner merge transition as the page action will fire it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/learner-merge-page-action/specs/learner-account-merge/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * `learner-profile`'s `merge` transition carries the label and the required
 * `mergedInto` input the page action asks for, and a real merge payload
 * validates against the profile's properties.
 */
class LearnerMergeTransitionRegisterTest extends TestCase {

	/**
	 * The LearnerProfile schema as the app ships it.
	 *
	 * @return array<string, mixed>
	 */
	private function profile(): array {
		$config = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);

		return $config['components']['schemas']['LearnerProfile'];
	}//end profile()

	/**
	 * Whether a payload validates against the profile's properties.
	 *
	 * @param array<string, mixed> $payload The profile.
	 *
	 * @return bool
	 */
	private function isValid(array $payload): bool {
		$properties = $this->profile()['properties'];
		// `$ref` names an OpenRegister schema by slug, not a JSON pointer; the
		// format and type are what the validator checks here.
		unset($properties['mergedInto']['$ref']);
		$schema = ['type' => 'object', 'properties' => ['lifecycle' => $properties['lifecycle'], 'mergedInto' => $properties['mergedInto']]];

		return (new Validator())->validate(json_decode((string)json_encode($payload)), (string)json_encode($schema))->isValid();
	}//end isValid()

	/**
	 * The merge transition names its label and requires the surviving profile.
	 *
	 * @return void
	 */
	public function testTheMergeTransitionAsksForTheSurvivor(): void {
		$merge = $this->profile()['x-openregister-lifecycle']['transitions']['merge'];

		$this->assertSame('Merge into another account', $merge['label']);
		$this->assertSame([['field' => 'mergedInto', 'required' => true]], $merge['inputs']);
		$this->assertSame(['active', 'merged'], [$merge['from'], $merge['to']]);
		$this->assertSame('OCA\Learniq\Lifecycle\LearnerMergeGuard', $merge['requires'], 'the guard still decides');
	}//end testTheMergeTransitionAsksForTheSurvivor()

	/**
	 * The input points at another learner profile, so a picker can resolve it.
	 *
	 * @return void
	 */
	public function testMergedIntoIsAReferenceToALearnerProfile(): void {
		$mergedInto = $this->profile()['properties']['mergedInto'];

		$this->assertSame('LearnerProfile', $mergedInto['$ref']);
		$this->assertSame('uuid', $mergedInto['format']);
	}//end testMergedIntoIsAReferenceToALearnerProfile()

	/**
	 * A real merge payload validates; a survivor that is not a uuid does not.
	 *
	 * @return void
	 */
	public function testARealMergePayloadValidates(): void {
		$this->assertTrue($this->isValid(['lifecycle' => 'merged', 'mergedInto' => '0d6f3b8e-6a7c-4a7f-9a55-2f3c1d9e8b41']));
		$this->assertFalse($this->isValid(['lifecycle' => 'merged', 'mergedInto' => 'Jan de Vries']));
	}//end testARealMergePayloadValidates()

	/**
	 * The schema version moved, so the register update is imported.
	 *
	 * @return void
	 */
	public function testTheSchemaVersionIsBumped(): void {
		$this->assertTrue(version_compare($this->profile()['version'], '0.3.8', '>'));
	}//end testTheSchemaVersionIsBumped()
}//end class
