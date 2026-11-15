<?php

/**
 * The register, mock register, example sets and manifest after
 * data-exchange-to-integriq: the four exchange schemas are gone everywhere,
 * the three gate records are in.
 *
 * @category Test
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-the-data-exchange-menu-is-a-read-only-status-panel-beside-the-gate-pages
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Repair\MigrateDataExchangeToIntegriq;
use OCA\Learniq\Service\ExchangeDisclosure;
use PHPUnit\Framework\TestCase;

/**
 * Every place a retired schema could hide.
 */
class DataExchangeToIntegriqRegisterTest extends TestCase {

	/**
	 * The retired schema keys and slugs.
	 *
	 * @var array<string, string>
	 */
	private const RETIRED = [
		'DataExchangeJob' => 'data-exchange-job',
		'DataMappingProfile' => 'data-mapping-profile',
		'ExchangeRejection' => 'exchange-rejection',
		'ExchangeErrorCode' => 'exchange-error-code',
	];

	/**
	 * A decoded JSON file of the app.
	 *
	 * @param string $path Path from the app root.
	 *
	 * @return array<string, mixed> The decoded file.
	 */
	private static function json(string $path): array {
		$decoded = json_decode((string)file_get_contents(__DIR__ . '/../../../' . $path), true);
		self::assertIsArray($decoded, $path . ' must be valid JSON');
		return $decoded;
	}//end json()

	/**
	 * The retired schemas are gone from the register, its schema list and the mock register.
	 *
	 * @return void
	 */
	public function testTheRetiredSchemasAreGone(): void {
		foreach (['lib/Settings/learniq_register.json', 'lib/Settings/learniq_mock_register.json'] as $file) {
			$register = self::json($file);
			foreach (self::RETIRED as $key => $slug) {
				$this->assertArrayNotHasKey($key, ($register['components']['schemas'] ?? []), $file . ' still declares ' . $key);
			}

			foreach (($register['components']['objects'] ?? []) as $object) {
				$this->assertNotContains($object['@self']['schema'] ?? '', array_merge(array_keys(self::RETIRED), array_values(self::RETIRED)), $file);
			}

			$schemaList = $register['components']['registers']['learniq']['schemas'] ?? [];
			$this->assertSame([], array_values(array_intersect(array_values(self::RETIRED), $schemaList)), $file);
		}
	}//end testTheRetiredSchemasAreGone()

	/**
	 * No live schema points at a retired one.
	 *
	 * @return void
	 */
	public function testNoLiveSchemaPointsAtARetiredOne(): void {
		$raw = (string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json');
		foreach (array_keys(self::RETIRED) as $key) {
			$this->assertStringNotContainsString('"$ref": "' . $key . '"', $raw);
		}
	}//end testNoLiveSchemaPointsAtARetiredOne()

	/**
	 * The three gate records are registered, guarded and seeded.
	 *
	 * @return void
	 */
	public function testTheGateRecordsAreRegisteredGuardedAndSeeded(): void {
		$register = self::json('lib/Settings/learniq_register.json');
		$schemas = $register['components']['schemas'];
		$list = $register['components']['registers']['learniq']['schemas'];
		foreach (['ExchangePartnerApproval' => 'exchange-partner-approval', 'TeldatumCheck' => 'teldatum-check', 'DossierReview' => 'dossier-review'] as $key => $slug) {
			$this->assertSame($slug, $schemas[$key]['slug']);
			$this->assertContains($slug, $list);
			$this->assertNotEmpty($schemas[$key]['authorization']['read'], $key . ' read');
			$this->assertNotEmpty($schemas[$key]['x-openregister-seed'], $key . ' needs a seed row (gate 101)');
			$this->assertArrayNotHasKey('appendOnly', $schemas[$key], $key . ' has a lifecycle');
		}

		$approve = $schemas['DossierReview']['x-openregister-lifecycle']['transitions']['approve'];
		$this->assertSame('OCA\\Learniq\\Lifecycle\\OsoDossierReviewGuard', $approve['requires']);
		$this->assertSame('scholiq-data-exchange', $schemas['DossierReview']['x-openregister-processing']['code']);
	}//end testTheGateRecordsAreRegisteredGuardedAndSeeded()

	/**
	 * No example set seeds a retired schema.
	 *
	 * @return void
	 */
	public function testNoExampleSetSeedsARetiredSchema(): void {
		foreach (glob(__DIR__ . '/../../../lib/Settings/profiles/*.json') as $file) {
			$set = json_decode((string)file_get_contents($file), true);
			$buckets = array_keys($set['x-openregister']['seedData']['objects'] ?? []);
			$this->assertSame([], array_values(array_intersect(array_values(self::RETIRED), $buckets)), basename($file));
		}
	}//end testNoExampleSetSeedsARetiredSchema()

	/**
	 * No manifest page or widget reads a retired schema; the status panel reads integriq's own.
	 *
	 * @return void
	 */
	public function testTheStatusPanelReadsIntegriq(): void {
		foreach (glob(__DIR__ . '/../../../src/manifest.d/*.json') as $file) {
			$raw = (string)file_get_contents($file);
			foreach (self::RETIRED as $slug) {
				$this->assertStringNotContainsString('"schema": "' . $slug . '"', $raw, basename($file));
			}
		}

		$pages = array_column(self::json('src/manifest.d/data-exchange.json')['pages'], null, 'id');
		foreach (['ExchangeJobs' => 'job', 'ExchangeRejections' => 'sync_item_dead_letter'] as $id => $schema) {
			$this->assertSame('integriq', $pages[$id]['config']['register']);
			$this->assertSame($schema, $pages[$id]['config']['schema']);
			$this->assertSame(['ownerApp' => 'learniq'], $pages[$id]['config']['filter']);
			$this->assertFalse($pages[$id]['config']['showAdd'], $id . ' is read-only');
			$this->assertFalse($pages[$id]['config']['showEditAction'], $id . ' is read-only');
			$this->assertSame('integriq', $pages[$id]['requiresApp']['id']);
		}
	}//end testTheStatusPanelReadsIntegriq()

	/**
	 * Every export mapping learniq discloses for is one the migration knows.
	 *
	 * @return void
	 */
	public function testTheSharedMappingSlugsAgree(): void {
		$slugs = array_values(MigrateDataExchangeToIntegriq::SEEDED_PROFILES);
		$this->assertCount(23, array_unique($slugs));

		$disclosure = new ExchangeDisclosure();
		foreach ($slugs as $slug) {
			if (str_contains($slug, '-import-') === true) {
				$this->assertNull($disclosure->fieldsFor($slug), $slug . ': nothing leaves on an import');
				continue;
			}

			$this->assertNotNull($disclosure->fieldsFor($slug), $slug . ' needs a field list');
			$this->assertNotContains('bsnEncrypted', $disclosure->fieldsFor($slug));
		}
	}//end testTheSharedMappingSlugsAgree()
}//end class
