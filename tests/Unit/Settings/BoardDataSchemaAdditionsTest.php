<?php

/**
 * Tests for the board data the schemas lacked (board-data-the-schemas-lacked).
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Settings
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

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use PHPUnit\Framework\TestCase;

/**
 * The new properties are additive, and the seeds that fill them fit the
 * real schema fragments.
 *
 * @spec openspec/changes/board-data-the-schemas-lacked/specs/example-sets/spec.md#requirement-the-board-data-has-a-place-in-the-schemas-and-in-the-seeds
 */
class BoardDataSchemaAdditionsTest extends TestCase {
	use RegisterSchemaPayloads;

	/**
	 * The seed objects of one set and schema.
	 *
	 * @param string $set    The set.
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function seeds(string $set, string $schema): array {
		$data = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/profiles/' . $set . '.json'), true);

		return ($data['x-openregister']['seedData']['objects'][$schema] ?? []);
	}//end seeds()

	/**
	 * The seed payload without the seed's own metadata keys.
	 *
	 * @param array<string, mixed> $row The seed row.
	 *
	 * @return array<string, mixed>
	 */
	private static function payload(array $row): array {
		unset($row['@self'], $row['uuid'], $row['slug']);
		return $row;
	}//end payload()

	/**
	 * Every new property is optional: nothing that existed becomes required,
	 * and no type of an existing property changes.
	 *
	 * @return void
	 */
	public function testTheNewPropertiesAreOptional(): void {
		$cohort = self::shippedSchema(slug: 'cohort');
		self::assertSame('integer', $cohort['properties']['capacity']['type']);
		self::assertNotContains('capacity', $cohort['required']);

		$placement = self::shippedSchema(slug: 'bpv-placement');
		foreach (['workdaysLabel', 'workplaceAddress', 'qualificationName', 'crebo'] as $field) {
			self::assertSame('string', $placement['properties'][$field]['type'], $field);
			self::assertNotContains($field, $placement['required'], $field);
		}

		$progress = self::shippedSchema(slug: 'werkproces-progress');
		self::assertSame(['bpvPlacementId', 'learnerRef', 'werkprocesCode', 'werkprocesLabel', 'tenant_id'], $progress['required']);
		self::assertSame(['goed', 'voldoende', 'onvoldoende', null], $progress['properties']['selfAssessment']['enum']);
	}//end testTheNewPropertiesAreOptional()

	/**
	 * The academy's course dates carry a capacity, and every cohort seed still
	 * fits the cohort schema.
	 *
	 * @return void
	 */
	public function testTheAcademyCohortsCarryAFittingCapacity(): void {
		$cohorts = array_column(self::seeds(set: 'training', schema: 'cohort'), null, 'uuid');
		self::assertSame(4, $cohorts['ee06000d-0000-4000-8000-000000000044']['capacity'], 'F-gassen on 8 October');
		self::assertSame(6, $cohorts['ee06000d-0000-4000-8000-000000000045']['capacity'], 'Waterzijdig inregelen on 15 October');
		foreach ($cohorts as $row) {
			self::assertNull(self::schemaError(slug: 'cohort', payload: self::payload(row: $row)), (string)$row['name']);
		}
	}//end testTheAcademyCohortsCarryAFittingCapacity()

	/**
	 * Milan's placement carries the board's agreements and still fits the
	 * placement schema; his work process progress fits its own.
	 *
	 * @return void
	 */
	public function testMilansPlacementAndWorkProcessesFitTheirSchemas(): void {
		$placements = array_column(self::seeds(set: 'mbo', schema: 'bpv-placement'), null, 'uuid');
		$milan      = $placements['ee030015-0000-4000-8000-000000000152'];
		self::assertSame(
			['Maandag tot en met woensdag, 08.00 tot 16.30 uur', '[adres leerbedrijf], Zuiddrecht', 'Mechatronica niveau 4', '25743'],
			[$milan['workdaysLabel'], $milan['workplaceAddress'], $milan['qualificationName'], $milan['crebo']]
		);
		self::assertNull(self::schemaError(slug: 'bpv-placement', payload: self::payload(row: $milan)));

		$progress = self::seeds(set: 'mbo', schema: 'werkproces-progress');
		self::assertSame(['B1-K1-W1', 'B1-K1-W2', 'B1-K1-W3', 'B1-K1-W4', 'B1-K2-W1', 'B1-K2-W2'], array_column($progress, 'werkprocesCode'));
		self::assertSame([14, 22, 38, 26, 12, 8], array_column($progress, 'hoursSpent'));
		foreach ($progress as $row) {
			self::assertSame('ee030015-0000-4000-8000-000000000152', $row['bpvPlacementId']);
			self::assertNull(self::schemaError(slug: 'werkproces-progress', payload: self::payload(row: $row)), $row['werkprocesCode']);
		}
	}//end testMilansPlacementAndWorkProcessesFitTheirSchemas()
}//end class
