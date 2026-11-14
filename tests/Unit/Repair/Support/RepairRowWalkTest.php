<?php
/**
 * Unit tests for RepairRowWalk: it pages through one learniq schema 200 rows
 * at a time without RBAC or multitenancy, hands every row to the visit, and
 * turns a failed read into a warning instead of an exception.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Repair\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair\Support;

use JsonSerializable;
use OCA\Learniq\Repair\Support\RepairRowWalk;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Repair\Support\RepairRowWalk
 * @uses   \OCA\Learniq\Repair\Support\RepairTally
 */
class RepairRowWalkTest extends TestCase {

	/**
	 * Rows numbered from $from, $count of them.
	 *
	 * @param int $from  First number.
	 * @param int $count How many.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows(int $from, int $count): array {
		$rows = [];
		for ($i = 0; $i < $count; $i++) {
			$rows[] = ['id' => 'r'.($from + $i)];
		}

		return $rows;
	}//end rows()

	/**
	 * An object service that answers findAll from the given pages in turn and
	 * records each config and flag pair it was called with.
	 *
	 * @param array<int, array<int, mixed>|\Throwable> $pages The answers, in order.
	 * @param array<int, array<string, mixed>>         $calls Receives the calls.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $pages, array &$calls): ObjectService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use (&$pages, &$calls): array {
				$calls[] = ['config' => $config, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				$answer = array_shift($pages);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return ($answer ?? []);
			}
		);

		return $objectService;
	}//end objectService()

	/**
	 * Pages are read 200 at a time from the learniq register, without RBAC or
	 * multitenancy, until a short page.
	 *
	 * @return void
	 */
	public function testPagesUntilAShortPageWithoutRbacOrMultitenancy(): void {
		$calls = [];
		$walk = new RepairRowWalk(
			objectService: $this->objectService(pages: [self::rows(from: 0, count: 200), self::rows(from: 200, count: 3)], calls: $calls),
			logger: $this->createMock(LoggerInterface::class),
			label: 'Step',
		);

		$seen = [];
		$tally = $walk->walk(
			schema: 'grade-entry',
			visit: static function (array $row) use (&$seen): ?string {
				$seen[] = $row['id'];
				return 'stamped';
			}
		);

		self::assertSame(203, $tally->countOf(key: 'scanned'));
		self::assertSame(203, $tally->countOf(key: 'stamped'));
		self::assertSame('r0', $seen[0]);
		self::assertSame('r202', $seen[202]);
		self::assertSame(
			[
				[
					'config' => ['filters' => ['register' => 'learniq', 'schema' => 'grade-entry'], 'limit' => 200, 'offset' => 0],
					'rbac' => false,
					'multitenancy' => false,
				],
				[
					'config' => ['filters' => ['register' => 'learniq', 'schema' => 'grade-entry'], 'limit' => 200, 'offset' => 200],
					'rbac' => false,
					'multitenancy' => false,
				],
			],
			$calls
		);
	}//end testPagesUntilAShortPageWithoutRbacOrMultitenancy()

	/**
	 * A full last page is followed by one more read, which comes back empty.
	 *
	 * @return void
	 */
	public function testAFullLastPageEndsOnTheEmptyPageAfterIt(): void {
		$calls = [];
		$walk = new RepairRowWalk(
			objectService: $this->objectService(pages: [self::rows(from: 0, count: 200), []], calls: $calls),
			logger: $this->createMock(LoggerInterface::class),
			label: 'Step',
		);

		$tally = $walk->walk(schema: 'lesson', visit: static fn (array $row): ?string => null);

		self::assertSame(200, $tally->countOf(key: 'scanned'));
		self::assertCount(2, $calls);
		self::assertSame(200, $calls[1]['config']['offset']);
	}//end testAFullLastPageEndsOnTheEmptyPageAfterIt()

	/**
	 * An empty schema reads once, visits nothing and logs nothing.
	 *
	 * @return void
	 */
	public function testAnEmptySchemaVisitsNothing(): void {
		$calls = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');
		$walk = new RepairRowWalk(
			objectService: $this->objectService(pages: [[]], calls: $calls),
			logger: $logger,
			label: 'Step',
		);

		$tally = $walk->walk(
			schema: 'lesson',
			visit: static function (array $row): ?string {
				throw new RuntimeException('no row expected');
			}
		);

		self::assertSame(0, $tally->countOf(key: 'scanned'));
		self::assertCount(1, $calls);
	}//end testAnEmptySchemaVisitsNothing()

	/**
	 * Serialisable objects are visited as their array form; other values
	 * among the results are skipped and do not count as scanned.
	 *
	 * @return void
	 */
	public function testSerialisableObjectsBecomeRowsAndOtherValuesAreSkipped(): void {
		$entity = new class implements JsonSerializable {
			/**
			 * The array form.
			 *
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'from-entity'];
			}
		};
		$calls = [];
		$walk = new RepairRowWalk(
			objectService: $this->objectService(pages: [[['id' => 'plain'], $entity, 'text', 42, new \stdClass(), null]], calls: $calls),
			logger: $this->createMock(LoggerInterface::class),
			label: 'Step',
		);

		$seen = [];
		$tally = $walk->walk(
			schema: 'lesson',
			visit: static function (array $row) use (&$seen): ?string {
				$seen[] = $row;
				return null;
			}
		);

		self::assertSame([['id' => 'plain'], ['id' => 'from-entity']], $seen);
		self::assertSame(2, $tally->countOf(key: 'scanned'));
	}//end testSerialisableObjectsBecomeRowsAndOtherValuesAreSkipped()

	/**
	 * A failed read stops the walk with a warning under the step's label; the
	 * rows of earlier pages stay counted.
	 *
	 * @return void
	 */
	public function testAFailedReadStopsTheWalkWithAWarning(): void {
		$calls = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'[BackfillThing] Stopped early: {msg}',
			['msg' => 'register not imported']
		);
		$walk = new RepairRowWalk(
			objectService: $this->objectService(
				pages: [self::rows(from: 0, count: 200), new RuntimeException('register not imported'), self::rows(from: 400, count: 1)],
				calls: $calls
			),
			logger: $logger,
			label: 'BackfillThing',
		);

		$tally = $walk->walk(schema: 'lesson', visit: static fn (array $row): ?string => 'seen');

		self::assertSame(200, $tally->countOf(key: 'scanned'));
		self::assertSame(200, $tally->countOf(key: 'seen'));
		self::assertCount(2, $calls);
	}//end testAFailedReadStopsTheWalkWithAWarning()

	/**
	 * A visit that throws stops the walk the same way, after counting its row.
	 *
	 * @return void
	 */
	public function testAThrowingVisitStopsTheWalk(): void {
		$calls = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'[Step] Stopped early: {msg}',
			['msg' => 'bad row r1']
		);
		$walk = new RepairRowWalk(
			objectService: $this->objectService(pages: [self::rows(from: 0, count: 5)], calls: $calls),
			logger: $logger,
			label: 'Step',
		);

		$tally = $walk->walk(
			schema: 'lesson',
			visit: static function (array $row): ?string {
				if ($row['id'] === 'r1') {
					throw new RuntimeException('bad row r1');
				}

				return 'ok';
			}
		);

		self::assertSame(2, $tally->countOf(key: 'scanned'));
		self::assertSame(1, $tally->countOf(key: 'ok'));
	}//end testAThrowingVisitStopsTheWalk()

	/**
	 * walkNamingSchema walks the same way, and its warning names the schema.
	 *
	 * @return void
	 */
	public function testWalkNamingSchemaNamesTheSchemaInTheWarning(): void {
		$calls = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'[Step] Stopped early on {schema}: {msg}',
			['schema' => 'report-card', 'msg' => 'no OpenRegister']
		);
		$walk = new RepairRowWalk(
			objectService: $this->objectService(pages: [new RuntimeException('no OpenRegister')], calls: $calls),
			logger: $logger,
			label: 'Step',
		);

		$tally = $walk->walkNamingSchema(schema: 'report-card', visit: static fn (array $row): ?string => 'x');

		self::assertSame(0, $tally->countOf(key: 'scanned'));
		self::assertSame('report-card', $calls[0]['config']['filters']['schema']);
	}//end testWalkNamingSchemaNamesTheSchemaInTheWarning()

	/**
	 * walkNamingSchema pages and counts like walk, and logs nothing on success.
	 *
	 * @return void
	 */
	public function testWalkNamingSchemaVisitsEveryRow(): void {
		$calls = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');
		$walk = new RepairRowWalk(
			objectService: $this->objectService(
				pages: [self::rows(from: 0, count: 200), self::rows(from: 200, count: 200), self::rows(from: 400, count: 1)],
				calls: $calls
			),
			logger: $logger,
			label: 'Step',
		);

		$tally = $walk->walkNamingSchema(schema: 'lesson', visit: static fn (array $row): ?string => null);

		self::assertSame(401, $tally->countOf(key: 'scanned'));
		self::assertSame([0, 200, 400], array_map(static fn (array $call): int => $call['config']['offset'], $calls));
	}//end testWalkNamingSchemaVisitsEveryRow()

	/**
	 * A store that always returns a full page is cut off at 10,000 pages.
	 *
	 * @return void
	 */
	public function testAnEndlessStoreIsCutOffAtTheHardStop(): void {
		$page = self::rows(from: 0, count: 200);
		$reads = 0;
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static function () use ($page, &$reads): array {
				$reads++;
				return $page;
			}
		);
		$walk = new RepairRowWalk(objectService: $objectService, logger: $this->createMock(LoggerInterface::class), label: 'Step');

		$tally = $walk->walk(schema: 'lesson', visit: static fn (array $row): ?string => null);

		self::assertSame(10000, $reads);
		self::assertSame(2000000, $tally->countOf(key: 'scanned'));
	}//end testAnEndlessStoreIsCutOffAtTheHardStop()
}//end class
