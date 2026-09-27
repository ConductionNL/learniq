<?php

/**
 * Learniq SegmentService unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 * @spec openspec/changes/segment-runtime-bridge/specs/nextcloud-app/spec.md#requirement-the-server-resolves-one-current-segment
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\SegmentService;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for SegmentService::currentSegment().
 */
class SegmentServiceTest extends TestCase {

	/**
	 * Build a service whose findAll() returns the given rows.
	 *
	 * @param array<int, mixed> $rows The rows findAll() returns.
	 *
	 * @return SegmentService
	 */
	private function service(array $rows): SegmentService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn($rows);

		return new SegmentService($objectService, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * A settings row as OpenRegister serialises it.
	 *
	 * @param string $segment The stored segment code.
	 * @param string $updated The `@self.updated` timestamp.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(string $segment, string $updated): array {
		return [
			'segment' => $segment,
			'@self'   => ['updated' => $updated],
		];
	}//end row()

	/**
	 * No settings record yet: the no-behaviour-change default.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-runtime-bridge/specs/nextcloud-app/spec.md#scenario-no-settings-record-yet
	 */
	public function testDefaultsToCorporateWithoutARow(): void {
		self::assertSame('corporate', $this->service([])->currentSegment());
	}//end testDefaultsToCorporateWithoutARow()

	/**
	 * One stored row is returned as is.
	 *
	 * @return void
	 */
	public function testReturnsTheStoredSegment(): void {
		$rows = [self::row('training', '2026-09-27T10:00:00+00:00')];

		self::assertSame('training', $this->service($rows)->currentSegment());
	}//end testReturnsTheStoredSegment()

	/**
	 * With several rows the most recently updated valid one wins, whatever
	 * the order OpenRegister returns them in.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-runtime-bridge/specs/nextcloud-app/spec.md#scenario-several-rows-the-newest-wins
	 */
	public function testTheNewestRowWins(): void {
		$rows = [
			self::row('corporate', '2026-03-01T09:00:00+00:00'),
			self::row('po', '2026-09-27T09:00:00+00:00'),
			self::row('corporate', '2026-03-03T09:00:00+00:00'),
		];

		self::assertSame('po', $this->service($rows)->currentSegment());
	}//end testTheNewestRowWins()

	/**
	 * A row with an unknown code is skipped, even when it is the newest.
	 *
	 * @return void
	 */
	public function testAnUnknownCodeIsIgnored(): void {
		$rows = [
			self::row('vo', '2026-03-01T09:00:00+00:00'),
			self::row('kindergarten', '2026-09-27T09:00:00+00:00'),
		];

		self::assertSame('vo', $this->service($rows)->currentSegment());
		self::assertSame('corporate', $this->service([self::row('kindergarten', '2026-09-27T09:00:00+00:00')])->currentSegment());
	}//end testAnUnknownCodeIsIgnored()

	/**
	 * An ObjectEntity-like result is read through jsonSerialize().
	 *
	 * @return void
	 */
	public function testReadsEntitiesThroughJsonSerialize(): void {
		$entity = new class {
			/**
			 * The serialised row.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['segment' => 'mbo', '@self' => ['updated' => '2026-09-27T09:00:00+00:00']];
			}//end jsonSerialize()
		};

		self::assertSame('mbo', $this->service([$entity])->currentSegment());
	}//end testReadsEntitiesThroughJsonSerialize()

	/**
	 * A failed read never breaks the page: it logs and falls back.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-runtime-bridge/specs/nextcloud-app/spec.md#scenario-a-failed-read-does-not-break-the-page
	 */
	public function testAFailedReadFallsBackAndLogs(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willThrowException(new RuntimeException('register missing'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info');

		$service = new SegmentService($objectService, $logger);

		self::assertSame('corporate', $service->currentSegment());
	}//end testAFailedReadFallsBackAndLogs()

	/**
	 * The read skips RBAC: a learner's menu depends on a record the register
	 * cascade does not let them read.
	 *
	 * @return void
	 */
	public function testTheReadSkipsRbac(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects(self::once())
			->method('findAll')
			->with(
				self::callback(
					static fn (array $config): bool => $config['register'] === 'learniq' && $config['schema'] === 'learniqsettings'
				),
				false
			)
			->willReturn([]);

		(new SegmentService($objectService, $this->createMock(LoggerInterface::class)))->currentSegment();
	}//end testTheReadSkipsRbac()

	/**
	 * The PHP list and the schema enum hold the same six codes in the same
	 * order, so a value added to one cannot silently fall back to the default.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-runtime-bridge/specs/nextcloud-app/spec.md#scenario-the-code-lists-and-the-schema-agree
	 */
	public function testTheListMatchesTheSchemaEnum(): void {
		$path     = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$register = json_decode((string)file_get_contents($path), true);
		$segment  = $register['components']['schemas']['LearniqSettings']['properties']['segment'];

		self::assertSame($segment['enum'], SegmentService::SEGMENTS);
		self::assertSame($segment['default'], SegmentService::DEFAULT_SEGMENT);
	}//end testTheListMatchesTheSchemaEnum()
}//end class
