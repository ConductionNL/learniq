<?php

/**
 * LvsResult names its pupil by LearnerProfile reference.
 *
 * The register declares `learnerRef` on LvsResult the way the portal-identity
 * change declared it on GradeEntry, and every LVS result in a shipped example
 * set carries one that resolves to a learner profile of the same pupil in
 * the same set. A generated row is validated against the real fragment.
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
 * @spec openspec/changes/lvs-result-learner-ref/specs/data-exchange/spec.md#requirement-every-lvsresult-names-its-pupil-by-learnerprofile-reference
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use PHPUnit\Framework\TestCase;

/**
 * Checks LvsResult.learnerRef in the register and in the example sets.
 */
class LvsResultLearnerRefRegisterTest extends TestCase {
	use RegisterSchemaPayloads;

	/**
	 * Every shipped example set's objects, keyed by schema slug.
	 *
	 * @return array<string, array<string, array<int, array<string, mixed>>>> Set file => schema => objects.
	 */
	private static function exampleSets(): array {
		$files = glob(dirname(__DIR__, 3) . '/lib/Settings/profiles/*.json');
		$sets = [];
		foreach (($files === false ? [] : $files) as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			$sets[basename($file)] = ($data['x-openregister']['seedData']['objects'] ?? []);
		}

		return $sets;
	}//end exampleSets()

	/**
	 * LvsResult declares learnerRef as an optional LearnerProfile uuid.
	 *
	 * @return void
	 */
	public function testLvsResultDeclaresLearnerRef(): void {
		$schema = self::shippedSchema(slug: 'lvs-result');
		$property = ($schema['properties']['learnerRef'] ?? null);

		self::assertIsArray($property, 'LvsResult has no learnerRef');
		self::assertSame('string', $property['type']);
		self::assertSame('uuid', $property['format']);
		self::assertSame('LearnerProfile', $property['$ref']);
		self::assertTrue($property['nullable']);
		self::assertSame('Learner Ref', $property['title']);
		self::assertNotSame('', (string)($property['description'] ?? ''));
		self::assertNotContains('learnerRef', $schema['required']);
	}//end testLvsResultDeclaresLearnerRef()

	/**
	 * Adding the field changes neither who may read or write a result nor the
	 * learnerId it is read by.
	 *
	 * @return void
	 */
	public function testAuthorizationStillScopesOnLearnerId(): void {
		$schema = self::shippedSchema(slug: 'lvs-result');

		self::assertSame(['coordinators', 'compliance-officers'], $schema['authorization']['create']);
		self::assertSame(['learnerId' => '$userId'], $schema['authorization']['read'][2]['match']);
		self::assertContains('learnerId', $schema['required']);
	}//end testAuthorizationStillScopesOnLearnerId()

	/**
	 * Every LVS result in an example set names a learner profile of the same
	 * pupil in the same set, and at least one set ships LVS results.
	 *
	 * @return void
	 */
	public function testEveryExampleLvsResultResolvesToItsPupilsProfile(): void {
		$checked = 0;
		$problems = [];
		foreach (self::exampleSets() as $set => $objects) {
			$profiles = array_column(($objects['learner-profile'] ?? []), null, 'uuid');
			foreach (($objects['lvs-result'] ?? []) as $row) {
				$checked++;
				$profile = ($profiles[(string)($row['learnerRef'] ?? '')] ?? null);
				if ($profile === null) {
					$problems[] = $set . ' ' . $row['slug'] . ': learnerRef does not name a learner profile in the set';
					continue;
				}

				if (($profile['ncUserId'] ?? null) !== $row['learnerId']) {
					$problems[] = $set . ' ' . $row['slug'] . ': learnerRef names another pupil than learnerId';
				}
			}
		}

		self::assertGreaterThan(0, $checked, 'no example set ships an LVS result');
		self::assertSame([], array_slice($problems, 0, 10), count($problems) . ' LVS result(s) not linked to their pupil');
	}//end testEveryExampleLvsResultResolvesToItsPupilsProfile()

	/**
	 * A generated Cito and a doorstroomtoets row fit the real schema fragment.
	 *
	 * @return void
	 */
	public function testAGeneratedRowFitsTheRealSchema(): void {
		$rows = (self::exampleSets()['po.json']['lvs-result'] ?? []);
		$byProvider = array_column($rows, null, 'provider');
		self::assertArrayHasKey('cito', $byProvider);
		self::assertArrayHasKey('iep', $byProvider);

		foreach ($byProvider as $provider => $row) {
			unset($row['@self'], $row['uuid'], $row['slug']);
			// dataExchangeJobId is required and nullable; the payload helper
			// lets only optional fields be null, so a result entered without
			// an exchange job reads as invalid there. Not this field's subject.
			$row['dataExchangeJobId'] = ($row['dataExchangeJobId'] ?? '00000000-0000-4000-8000-0000000000e1');
			self::assertArrayHasKey('learnerRef', $row);
			self::assertNull(self::schemaError(slug: 'lvs-result', payload: $row), $provider . ' row does not fit LvsResult');
		}

		$forged = $byProvider['cito'];
		unset($forged['@self'], $forged['uuid'], $forged['slug']);
		$forged['dataExchangeJobId'] = '00000000-0000-4000-8000-0000000000e1';
		$forged['learnerRef'] = 'not-a-uuid';
		self::assertNotNull(self::schemaError(slug: 'lvs-result', payload: $forged), 'a learnerRef that is no uuid must not fit');
	}//end testAGeneratedRowFitsTheRealSchema()
}//end class
