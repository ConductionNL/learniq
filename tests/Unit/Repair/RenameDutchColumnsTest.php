<?php

/**
 * Tests for the RenameDutchColumns repair step.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Repair
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
 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary migration.
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

require_once __DIR__ . '/../../Stubs/DoctrineParameterType.php';

use OCA\Learniq\Repair\RenameDutchColumns;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCP\DB\Exception as DbException;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Pins which statements the step runs, in which order, and what it reports.
 */
class RenameDutchColumnsTest extends TestCase {

	/**
	 * Register ids per slug prefix; null makes the register lookup fail.
	 *
	 * @var array<string, array<int, int>>|null
	 */
	private ?array $registers = [];

	/**
	 * Table names information_schema lists; null makes the listing fail.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $tables = [];

	/**
	 * Columns per table; a table missing here makes its column read fail.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $columns = [];

	/**
	 * Statements that fail when executed.
	 *
	 * @var array<int, string>
	 */
	private array $failing = [];

	/**
	 * Every executed statement, in order.
	 *
	 * @var array<int, string>
	 */
	private array $executed = [];

	/**
	 * Messages the step wrote to the repair output.
	 *
	 * @var array<int, string>
	 */
	private array $messages = [];

	/**
	 * The logger the step writes to.
	 *
	 * @var CapturingLogger
	 */
	private CapturingLogger $logger;

	/**
	 * A fresh logger per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = new CapturingLogger();
	}//end setUp()

	/**
	 * Run the step against the fake database.
	 *
	 * @return void
	 */
	private function runStep(): void {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->messages[] = $message;
			}
		);

		(new RenameDutchColumns($this->database(), $this->logger))->run($output);
	}//end runStep()

	/**
	 * The fake database.
	 *
	 * @return IDBConnection
	 */
	private function database(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params = []): IResult {
				if ($this->registers === null) {
					throw new DbException('registers unreadable');
				}

				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($this->registers[rtrim((string)$params[0], '%')] ?? []);

				return $result;
			}
		);
		$db->method('prepare')->willReturnCallback(fn (string $sql): IPreparedStatement => $this->statement(sql: $sql));
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				$this->executed[] = $sql;
				if (in_array($sql, $this->failing, true) === true) {
					throw new DbException('refused');
				}

				return 1;
			}
		);
		$db->method('getDatabasePlatform')->willReturn(
			new class {
				/**
				 * Quote like the platform does.
				 *
				 * @param string $identifier The name.
				 *
				 * @return string
				 */
				public function quoteSingleIdentifier(string $identifier): string {
					return '"' . $identifier . '"';
				}//end quoteSingleIdentifier()
			}
		);

		return $db;
	}//end database()

	/**
	 * A prepared statement over information_schema.
	 *
	 * @param string $sql The query.
	 *
	 * @return IPreparedStatement
	 */
	private function statement(string $sql): IPreparedStatement {
		$bound = new \ArrayObject();
		$rows  = new \ArrayObject();
		$stmt  = $this->createMock(IPreparedStatement::class);
		$stmt->method('bindValue')->willReturnCallback(
			function ($param, $value) use ($bound): bool {
				$bound[$param] = $value;
				return true;
			}
		);
		$stmt->method('execute')->willReturnCallback(
			function () use ($sql, $bound, $rows): IResult {
				if (str_contains($sql, 'information_schema.tables') === true) {
					if ($this->tables === null) {
						throw new DbException('no listing');
					}

					$rows->exchangeArray(array_map(static fn (string $name): array => ['table_name' => $name], $this->tables));
				} else {
					if (isset($this->columns[$bound['table']]) === false) {
						throw new DbException('no columns');
					}

					$rows->exchangeArray(array_map(static fn (string $name): array => ['column_name' => $name], $this->columns[$bound['table']]));
				}

				return $this->createMock(IResult::class);
			}
		);
		$stmt->method('fetch')->willReturnCallback(
			static function () use ($rows): array|false {
				$all = $rows->getArrayCopy();
				if ($all === []) {
					return false;
				}

				$next = array_shift($all);
				$rows->exchangeArray($all);

				return $next;
			}
		);

		return $stmt;
	}//end statement()

	/**
	 * Without a matching register or shard table the step does nothing.
	 *
	 * @return void
	 */
	public function testNothingToDoRunsNoStatement(): void {
		$this->runStep();
		$this->registers = ['learniq' => [7]];
		$this->tables    = ['oc_openregister_table_8_1', 'oc_openregister_table_7_x'];
		$this->runStep();
		$this->tables = [];
		$this->runStep();

		self::assertSame([], $this->executed);
		self::assertSame(array_fill(0, 3, 'RenameDutchColumns: no scholiq shard tables on this install; nothing to do.'), $this->messages);
	}//end testNothingToDoRunsNoStatement()

	/**
	 * Renames, back-fills and failures, in map order per table, with the
	 * exact summary; a failed statement is logged and not counted.
	 *
	 * @return void
	 */
	public function testEveryMappedColumnIsRenamedOrBackFilled(): void {
		$this->registers = ['scholiq' => [7], 'learniq' => [7, 9]];
		$this->tables    = [
			'oc_openregister_table_7_3',
			'oc_openregister_table_9_12',
			'oc_openregister_table_7_3',
			'oc_openregister_table_8_1',
		];
		$this->columns   = [
			'oc_openregister_table_7_3'  => ['id', 'toelichting', 'notes', 'kerntaak_code', 'beoordeling'],
			'oc_openregister_table_9_12' => ['schooladvies_level'],
		];
		$this->failing   = ['ALTER TABLE "oc_openregister_table_7_3" RENAME COLUMN "kerntaak_code" TO "core_task_code"'];

		$this->runStep();

		self::assertSame(
			[
				'ALTER TABLE "oc_openregister_table_7_3" RENAME COLUMN "beoordeling" TO "assessment"',
				'ALTER TABLE "oc_openregister_table_7_3" RENAME COLUMN "kerntaak_code" TO "core_task_code"',
				'UPDATE "oc_openregister_table_7_3" SET "notes" = "toelichting" WHERE "notes" IS NULL AND "toelichting" IS NOT NULL',
				'ALTER TABLE "oc_openregister_table_9_12" RENAME COLUMN "schooladvies_level" TO "school_advice_level"',
			],
			$this->executed
		);
		self::assertSame(['RenameDutchColumns: 2 renamed, 1 back-filled, 0 refused, across 2 shard table(s).'], $this->messages);
		self::assertSame(['RenameDutchColumns: statement failed; leaving the column as it was.'], array_column($this->logger->records, 'message'));
		self::assertSame($this->failing[0], $this->logger->records[0]['context']['sql']);
	}//end testEveryMappedColumnIsRenamedOrBackFilled()

	/**
	 * A register lookup or a table listing that fails is logged and skips the
	 * step; a column read that fails skips only that table.
	 *
	 * @return void
	 */
	public function testFailedReadsAreLoggedAndSkipped(): void {
		$this->registers = null;
		$this->runStep();

		$this->registers = ['learniq' => [7]];
		$this->tables    = null;
		$this->runStep();

		$this->tables  = ['oc_openregister_table_7_3', 'oc_openregister_table_7_4'];
		$this->columns = ['oc_openregister_table_7_4' => ['lesuur_minutes', 'lesson_hour_minutes']];
		$this->runStep();

		self::assertSame(
			[
				'RenameDutchColumns: could not resolve the registers; skipping.',
				'RenameDutchColumns: could not list tables; skipping.',
				'RenameDutchColumns: could not read columns; skipping table.',
			],
			array_column($this->logger->records, 'message')
		);
		self::assertSame('oc_openregister_table_7_3', $this->logger->records[2]['context']['table']);
		self::assertSame(
			['UPDATE "oc_openregister_table_7_4" SET "lesson_hour_minutes" = "lesuur_minutes" WHERE "lesson_hour_minutes" IS NULL AND "lesuur_minutes" IS NOT NULL'],
			$this->executed
		);
		self::assertSame('RenameDutchColumns: 0 renamed, 1 back-filled, 0 refused, across 2 shard table(s).', end($this->messages));
	}//end testFailedReadsAreLoggedAndSkipped()
}//end class
