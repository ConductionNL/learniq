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
 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-the-wizard-asks-what-kind-of-organisation-this-is
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\SegmentService;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
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
					static fn (array $config): bool => ($config['filters']['register'] ?? null) === 'learniq' && ($config['filters']['schema'] ?? null) === 'learniqsettings'
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

	/**
	 * The wizard's six cards follow the enum order and reuse the schema's own
	 * labels, so the card and the settings dropdown never disagree.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-the-wizard-asks-what-kind-of-organisation-this-is
	 */
	public function testTheWizardCardsUseTheSchemaLabels(): void {
		$path     = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$register = json_decode((string)file_get_contents($path), true);
		$labels   = $register['components']['schemas']['LearniqSettings']['properties']['segment']['x-enum-labels'];

		$choices = $this->service([])->listChoices();

		self::assertSame(SegmentService::SEGMENTS, array_column($choices, 'id'));
		foreach ($choices as $choice) {
			self::assertSame($labels[$choice['id']], $choice['label']);
			self::assertNotSame('', $choice['description']);
			self::assertNotSame('', $choice['icon']);
		}
	}//end testTheWizardCardsUseTheSchemaLabels()

	/**
	 * Without a row the segment is not stored yet; with a valid row it is.
	 *
	 * @return void
	 */
	public function testHasSegmentReflectsAStoredValidRow(): void {
		self::assertFalse($this->service([])->hasSegment());
		self::assertFalse($this->service([self::row('kindergarten', '2026-09-27T09:00:00+00:00')])->hasSegment());
		self::assertTrue($this->service([self::row('po', '2026-09-27T09:00:00+00:00')])->hasSegment());
	}//end testHasSegmentReflectsAStoredValidRow()

	/**
	 * With no record yet, setting the segment creates one, stamped with who
	 * and when, without RBAC (setup runs with system privileges).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#scenario-a-school-picks-primary-school
	 */
	public function testSetSegmentCreatesTheRecordWhenNoneExists(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([]);
		$objectService->expects(self::once())
			->method('saveObject')
			->with(
				self::callback(
					static fn (array $data): bool => $data['segment'] === 'po'
						&& $data['setBy'] === 'admin'
						&& strtotime((string)$data['setAt']) !== false
				),
				self::anything(),
				'learniq',
				'learniqsettings',
				null,
				false
			);

		(new SegmentService($objectService, $this->createMock(LoggerInterface::class)))->setSegment('po', 'admin');
	}//end testSetSegmentCreatesTheRecordWhenNoneExists()

	/**
	 * With a record, setting the segment updates that record by its uuid, so
	 * no second row appears.
	 *
	 * @return void
	 */
	public function testSetSegmentUpdatesTheCurrentRecord(): void {
		$row = self::row('corporate', '2026-09-01T09:00:00+00:00');
		$row['@self']['id'] = 'ee000000-0000-4000-8000-000000000001';

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([$row]);
		$objectService->expects(self::once())
			->method('saveObject')
			->with(
				self::callback(static fn (array $data): bool => $data['segment'] === 'vo'),
				self::anything(),
				'learniq',
				'learniqsettings',
				'ee000000-0000-4000-8000-000000000001',
				false
			);

		(new SegmentService($objectService, $this->createMock(LoggerInterface::class)))->setSegment('vo', 'admin');
	}//end testSetSegmentUpdatesTheCurrentRecord()

	/**
	 * An unknown segment is refused before anything is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#scenario-an-unknown-segment-is-refused
	 */
	public function testSetSegmentRefusesAnUnknownCode(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects(self::never())->method('saveObject');

		$this->expectException(InvalidArgumentException::class);
		(new SegmentService($objectService, $this->createMock(LoggerInterface::class)))->setSegment('kindergarten', 'admin');
	}//end testSetSegmentRefusesAnUnknownCode()
}//end class
