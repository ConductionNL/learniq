<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Learniq\Service\ComplianceRollupService;
use OCA\Learniq\Service\RegulationAudienceResolver;
use OCA\Learniq\Service\RegulationCoverageService;
use OCA\Learniq\Service\RunningExemptions;
use OCA\Learniq\Tests\Support\GoldenFile;
use PHPUnit\Framework\TestCase;

/**
 * Pins byRegulation() against golden/RegulationCoverage.json: the counts,
 * percentages and RAG states per regulation, and every question asked of
 * the rollup and the audience, in order.
 *
 * @covers \OCA\Learniq\Service\RegulationCoverageService
 * @uses   \OCA\Learniq\Service\RunningExemptions
 */
class RegulationCoverageGoldenTest extends TestCase {
	use GoldenFile;

	private const GOLDEN = __DIR__ . '/golden/RegulationCoverage.json';

	/**
	 * Questions asked, in order.
	 *
	 * @var list<mixed>
	 */
	private array $calls = [];

	/**
	 * Every department filter answers as recorded, asking the same questions.
	 *
	 * @return void
	 */
	public function testByRegulationMatchesGolden(): void {
		$service = new RegulationCoverageService($this->rollup(), $this->audience(), new RunningExemptions());
		$now = new DateTimeImmutable('2026-11-03T12:00:00+00:00');

		$actual = [];
		foreach ([null, '', 'zorg', 'zorg/team-a', 'techniek', 'nobody'] as $department) {
			$this->calls = [];
			$rows = $service->byRegulation(department: $department, now: $now);
			$actual['department ' . json_encode($department)] = ['rows' => $rows, 'calls' => $this->calls];
		}

		$this->assertMatchesGolden(file: self::GOLDEN, actual: $actual);
	}//end testByRegulationMatchesGolden()

	/**
	 * A rollup over fixed learners, regulations and exemptions.
	 *
	 * @return ComplianceRollupService
	 */
	private function rollup(): ComplianceRollupService {
		$rollup = $this->createMock(ComplianceRollupService::class);
		$rollup->method('population')->willReturn(
			[
				'regulations' => [
					['id' => 'r-1', 'slug' => 'bhv', 'name' => 'BHV'],
					['uuid' => 'r-2', 'slug' => 'vca', 'name' => 'vca basis', 'ragAmberThreshold' => 60, 'ragRedThreshold' => 40],
					['slug' => 'hygiene', 'ragAmberThreshold' => '95'],
					['id' => 'r-4', 'name' => 'Zonder slug'],
					['id' => 'r-5', 'slug' => 'nobody-scope', 'name' => 'Alpha'],
				],
				'exemptions' => [
					['learnerId' => 'anna', 'regulationSlug' => 'bhv', 'lifecycle' => 'granted', 'validUntil' => '2027-01-01'],
					['learnerId' => 'emp-bert', 'regulationSlug' => 'vca', 'lifecycle' => 'granted', 'validFrom' => '2026-01-01', 'validUntil' => '2026-12-31'],
					['learnerId' => 'carla', 'regulationSlug' => 'hygiene', 'lifecycle' => 'granted', 'validUntil' => '2026-01-01'],
					['learnerId' => 'dirk', 'regulationSlug' => 'bhv', 'lifecycle' => 'requested', 'validUntil' => '2027-01-01'],
				],
				'learners' => [
					['uid' => 'anna', 'department' => 'zorg/team-a'],
					['uid' => 'bert', 'employeeId' => 'emp-bert', 'department' => 'zorg'],
					['uid' => 'carla', 'department' => 'techniek'],
					['uid' => 'dirk', 'department' => 'zorg/team-b'],
					['uid' => 'eva'],
					['uid' => 'frank', 'department' => 'techniek'],
				],
			]
		);
		$rollup->method('learnerKeys')->willReturnCallback(
			function (array $profile): array {
				$this->calls[] = ['learnerKeys' => $profile['uid']];
				return array_values(array_filter([$profile['uid'] ?? null, $profile['employeeId'] ?? null]));
			}
		);
		$rollup->method('isCovered')->willReturnCallback(
			function (array $keys, string $regulationSlug, DateTimeImmutable $now): bool {
				$this->calls[] = ['isCovered' => [$keys, $regulationSlug, $now->format('c')]];
				return in_array($keys[0] . ':' . $regulationSlug, ['anna:vca', 'bert:bhv', 'carla:bhv', 'carla:vca', 'eva:bhv', 'frank:hygiene', 'dirk:'], true);
			}
		);

		return $rollup;
	}//end rollup()

	/**
	 * An audience where everyone is in scope but for a few fixed pairs.
	 *
	 * @return RegulationAudienceResolver
	 */
	private function audience(): RegulationAudienceResolver {
		$audience = $this->createMock(RegulationAudienceResolver::class);
		$audience->method('covers')->willReturnCallback(
			function (array $regulation, array $profile): bool {
				$slug = (string)($regulation['slug'] ?? '');
				$this->calls[] = ['covers' => [$slug, $profile['uid']]];
				return $slug !== 'nobody-scope' && ($slug !== 'hygiene' || ($profile['department'] ?? '') !== 'zorg');
			}
		);
		$audience->method('departmentLevels')->willReturnCallback(
			function (string $department): array {
				$this->calls[] = ['departmentLevels' => $department];
				$levels = [];
				$path = '';
				foreach (array_filter(explode('/', $department)) as $part) {
					$path = ltrim($path . '/' . $part, '/');
					$levels[] = $path;
				}

				return $levels;
			}
		);

		return $audience;
	}//end audience()
}//end class
