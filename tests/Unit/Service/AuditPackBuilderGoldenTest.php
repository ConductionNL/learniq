<?php

/**
 * Learniq AuditPackBuilder characterization test.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/compliance-audit/spec.md#requirement-export-audit-ready-zip-per-regulation-and-date-range
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\AuditEntryAttribution;
use OCA\Learniq\Service\AuditPackBuilder;
use OCA\Learniq\Service\AuditPeriodReader;
use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\CsvCellSanitizer;
use OCA\Learniq\Service\ExternalTrainingCsvBuilder;
use OCA\Learniq\Service\VerwerkingsregisterCsvBuilder;
use OCA\OpenRegister\Service\AuditHashService;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Pins every file of the audit-pack ZIP and every collaborator call, per
 * scenario, against tests/Unit/Service/golden/AuditPackBuilder.json. With
 * RECORD_GOLDEN=1 the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Service\AuditPackBuilder
 * @uses   \OCA\Learniq\Service\CsvCellSanitizer
 * @uses   \OCA\Learniq\Service\AuditPackScope
 * @uses   \OCA\Learniq\Service\AuditPackContent
 * @uses   \OCA\Learniq\Service\InMemoryZip
 */
class AuditPackBuilderGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/AuditPackBuilder.json';

	/**
	 * Each scenario: the attributed entries and the chain verification answer.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array{0: string, 1: string, 2: string}}>
	 */
	private static function scenarios(): array {
		$events = [
			['id' => 7, 'uuid' => 'a-7', 'action' => 'update', 'objectUuid' => 'o-1', 'register' => 3, 'schema' => 9, 'user' => 'ann', 'created' => '2026-10-01T09:00:00+00:00'],
			['id' => 3, 'uuid' => 'a-3', 'action' => 'create', 'object' => 12, 'register' => 3, 'schema' => 9, 'user' => '=cmd()', 'created' => '2026-10-02T09:00:00+00:00'],
			['uuid' => 'a-x', 'action' => 'read', 'user' => 'bob'],
		];
		$period = ['NIS2', '2026-10-01', '2026-10-31'];
		return [
			'valid chain' => [['events' => $events, 'unattributed' => 2], ['valid' => true, 'keyFingerprint' => 'fp-1'], $period],
			'broken chain' => [['events' => $events, 'unattributed' => 0], ['valid' => false, 'keyFingerprint' => 'fp-1', 'brokenAt' => 5], $period],
			'valid without fingerprint' => [['events' => $events, 'unattributed' => 1], ['valid' => true], $period],
			'no events' => [['events' => [], 'unattributed' => 0], ['valid' => true, 'keyFingerprint' => 'fp-2'], ['AVG', '2026-01-01', '2026-01-31']],
			'events without ids' => [['events' => [$events[2]], 'unattributed' => 4], ['valid' => 'yes', 'keyFingerprint' => 'fp-3'], $period],
		];
	}//end scenarios()

	/**
	 * Every scenario's pack and calls equal the recorded golden.
	 *
	 * @return void
	 */
	public function testEveryScenarioBuildsTheRecordedPack(): void {
		$actual = [];
		foreach (self::scenarios() as $name => [$own, $verification, $period]) {
			$actual[$name] = $this->packFor($own, $verification, $period);
		}

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		$this->assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testEveryScenarioBuildsTheRecordedPack()

	/**
	 * Build one pack and return its files and the calls made.
	 *
	 * @param array<string, mixed>               $own          What the attribution keeps.
	 * @param array<string, mixed>               $verification The chain verification answer.
	 * @param array{0: string, 1: string, 2: string} $period   Regulation, from and to.
	 *
	 * @return array<string, mixed>
	 */
	private function packFor(array $own, array $verification, array $period): array {
		$calls  = [];
		$reader = $this->createMock(AuditPeriodReader::class);
		$reader->method('entries')->willReturnCallback(
			static function (string $dateFrom, string $dateTo) use (&$calls): array {
				$calls[] = ['entries' => [$dateFrom, $dateTo]];
				return [['raw' => 1]];
			}
		);
		$hash = $this->createMock(AuditHashService::class);
		$hash->method('verifyChain')->willReturnCallback(
			static function (?int $from = null, ?int $to = null) use (&$calls, $verification): array {
				$calls[] = ['verifyChain' => [$from, $to]];
				return $verification;
			}
		);
		$tenants = $this->createMock(CallerTenantResolver::class);
		$tenants->method('resolve')->willReturn('t1');
		$registerCsv = $this->createMock(VerwerkingsregisterCsvBuilder::class);
		$registerCsv->method('build')->willReturnCallback(
			static fn (string $dateFrom, string $dateTo): string => "register $dateFrom $dateTo\n"
		);
		$trainingCsv = $this->createMock(ExternalTrainingCsvBuilder::class);
		$trainingCsv->method('build')->willReturnCallback(
			static fn (string $regulationSlug, string $dateFrom, string $dateTo, string $tenantId): string => "training $regulationSlug $dateFrom $dateTo $tenantId\n"
		);
		$attribution = $this->createMock(AuditEntryAttribution::class);
		$attribution->method('ownEntries')->willReturnCallback(
			static function (array $entries, string $tenantId, string $regulationSlug) use (&$calls, $own): array {
				$calls[] = ['ownEntries' => [$entries, $tenantId, $regulationSlug]];
				return $own;
			}
		);

		$builder = new AuditPackBuilder($reader, $hash, $tenants, new CsvCellSanitizer(), $registerCsv, $trainingCsv, $attribution);
		$zip     = $builder->build($this->createMock(IUser::class), $period[0], $period[1], $period[2]);

		return ['calls' => $calls, 'files' => self::files($zip)];
	}//end packFor()

	/**
	 * The files of a ZIP, in archive order, with the export time masked.
	 *
	 * @param string $zip The ZIP bytes.
	 *
	 * @return array<string, mixed>
	 */
	private static function files(string $zip): array {
		$path = (string)tempnam(sys_get_temp_dir(), 'golden_zip_');
		file_put_contents($path, $zip);
		$archive = new ZipArchive();
		$archive->open($path);
		$files = [];
		for ($i = 0; $i < $archive->numFiles; $i++) {
			$name    = (string)$archive->getNameIndex($i);
			$content = (string)$archive->getFromIndex($i);
			if ($name === 'manifest.json') {
				$content = (string)preg_replace('/"export_timestamp": "\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00"/', '"export_timestamp": "(now, UTC)"', $content);
			}

			$files[$name] = $content;
		}

		$archive->close();
		unlink($path);
		return $files;
	}//end files()
}//end class
