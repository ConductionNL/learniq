<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\CsvCellSanitizer;
use OCA\Learniq\Service\ExternalTrainingCsvBuilder;
use OCA\Learniq\Tests\Support\GoldenFile;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Pins build() against golden/ExternalTrainingCsv.json: the query it sends
 * and the CSV it writes, for date windows on and around each record.
 *
 * @covers \OCA\Learniq\Service\ExternalTrainingCsvBuilder
 * @uses   \OCA\Learniq\Service\CsvCellSanitizer
 */
class ExternalTrainingCsvGoldenTest extends TestCase {
	use GoldenFile;

	private const GOLDEN = __DIR__ . '/golden/ExternalTrainingCsv.json';

	/**
	 * Every window writes the CSV as recorded.
	 *
	 * @return void
	 */
	public function testBuildMatchesGolden(): void {
		$queries = [];
		$rows = $this->rows();
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use (&$queries, &$rows): array {
				$queries[] = $config;
				return $rows;
			}
		);
		$builder = new ExternalTrainingCsvBuilder($objects, new CsvCellSanitizer());

		$actual = [];
		$windows = [
			'all of 2026' => ['bhv', '2026-01-01', '2026-12-31', 't-1'],
			'march only' => ['bhv', '2026-03-01', '2026-03-31', 't-1'],
			'last day, end of day' => ['bhv', '2026-03-15', '2026-03-15', 't-2'],
			'nothing in range' => ['vca', '2030-01-01', '2030-12-31', ''],
			'reversed window' => ['bhv', '2026-12-31', '2026-01-01', 't-1'],
		];
		foreach ($windows as $name => [$slug, $from, $to, $tenant]) {
			$queries = [];
			$csv = $builder->build(regulationSlug: $slug, dateFrom: $from, dateTo: $to, tenantId: $tenant);
			$actual[$name] = ['query' => $queries, 'csv' => explode("\n", $csv)];
		}

		$rows = [];
		$actual['no records'] = explode("\n", $builder->build(regulationSlug: 'bhv', dateFrom: '2026-01-01', dateTo: '2026-12-31', tenantId: 't-1'));

		$this->assertMatchesGolden(file: self::GOLDEN, actual: $actual);
	}//end testBuildMatchesGolden()

	/**
	 * Records as arrays and as entities, with and without dates and evidence.
	 *
	 * @return list<mixed>
	 */
	private function rows(): array {
		return [
			['learnerId' => 'anna', 'title' => 'BHV basis', 'provider' => 'NIBHV', 'kind' => 'course', 'regulationSlug' => 'bhv', 'completedAt' => '2026-03-15T10:00:00Z', 'validUntil' => '2027-03-15', 'verifiedBy' => 'jan', 'verifiedAt' => '2026-03-16T09:00:00Z', 'credentialId' => 'cred-1', '@self' => ['files' => [['name' => 'cert.pdf'], ['title' => 'foto'], ['id' => 42], 'los.txt', []]]],
			OrEntityFactory::make(['learnerId' => '=cmd()', 'title' => 'BHV herhaling, "deel 2"', 'completedAt' => '2026-03-15T23:59:59Z', 'files' => ['a.pdf']], 'external-training-record'),
			['learnerId' => 'bert', 'title' => 'Na middernacht', 'completedAt' => '2026-03-16T00:00:00Z', 'files' => 'not a list'],
			['learnerId' => 'carla', 'title' => 'Zonder datum'],
			['learnerId' => 'dirk', 'title' => 'Vroeg', 'completedAt' => '2026-02-28', '@self' => ['files' => null], 'files' => ['fallback.pdf']],
			['learnerId' => 'eva', 'title' => 'Begin', 'completedAt' => '2026-03-01', 'kind' => "+meer\nregels"],
		];
	}//end rows()
}//end class
