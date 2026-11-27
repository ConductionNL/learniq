<?php

/**
 * Tests for the ArchiveRetiredPaymentObjects repair step (D19).
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
 * @spec openspec/specs/payments/spec.md#requirement-retired-payment-rows-are-archived-before-their-schemas-go
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use DateTime;
use OCA\Learniq\Repair\ArchiveRetiredPaymentObjects;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for ArchiveRetiredPaymentObjects::run().
 */
class ArchiveRetiredPaymentObjectsTest extends TestCase {

	/**
	 * Rows per schema slug; a slug in $gone throws on read.
	 *
	 * @var array<string, array<int, array<string,mixed>>>
	 */
	private array $rows = [];

	/**
	 * Schemas whose read throws.
	 *
	 * @var array<int, string>
	 */
	private array $gone = [];

	/**
	 * Files in the archive folder: name => content.
	 *
	 * @var array<string, string>
	 */
	private array $files = [];

	/**
	 * Whether the folder existed before the run.
	 *
	 * @var bool
	 */
	private bool $folderExists = false;

	/**
	 * Messages the step wrote.
	 *
	 * @var array<int, string>
	 */
	private array $messages = [];

	/**
	 * Build the step.
	 *
	 * @return ArchiveRetiredPaymentObjects
	 */
	private function makeStep(): ArchiveRetiredPaymentObjects {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$schema = (string)($config['filters']['schema'] ?? '');
				if ($_rbac !== false || ($config['filters']['register'] ?? null) !== 'learniq') {
					return [];
				}

				if (in_array($schema, $this->gone, true) === true) {
					throw new RuntimeException('Schema not found');
				}

				$page = array_slice(($this->rows[$schema] ?? []), (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 200));

				return OrEntityFactory::makeMany($page, $schema);
			}
		);

		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('fileExists')->willReturnCallback(fn (string $name): bool => isset($this->files[$name]));
		$folder->method('newFile')->willReturnCallback(
			function (string $name, $content = null): ISimpleFile {
				$this->files[$name] = (string)$content;

				return $this->createMock(ISimpleFile::class);
			}
		);

		$folder->method('getFile')->willReturnCallback(
			function (string $name): ISimpleFile {
				if (isset($this->files[$name]) === false) {
					throw new NotFoundException($name);
				}

				$file = $this->createMock(ISimpleFile::class);
				$file->method('getContent')->willReturn($this->files[$name]);

				return $file;
			}
		);

		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturnCallback(
			function (string $name) use ($folder): ISimpleFolder {
				if ($this->folderExists === false) {
					throw new NotFoundException($name);
				}

				return $folder;
			}
		);
		$appData->method('newFolder')->willReturnCallback(
			function (string $name) use ($folder): ISimpleFolder {
				$this->folderExists = true;

				return $folder;
			}
		);

		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->with('learniq')->willReturn($appData);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new DateTime('2026-09-27T12:00:00+00:00'));

		return new ArchiveRetiredPaymentObjects($objectService, $factory, $clock, new NullLogger());
	}//end makeStep()

	/**
	 * An IOutput double that records messages.
	 *
	 * @return IOutput
	 */
	private function repairOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$record = function (string $message): void {
			$this->messages[] = $message;
		};
		$output->method('info')->willReturnCallback($record);
		$output->method('warning')->willReturnCallback($record);

		return $output;
	}//end repairOutput()

	/**
	 * Every retired row lands in one JSON file, grouped by schema.
	 *
	 * @return void
	 */
	public function testEveryRetiredRowIsWrittenToTheArchive(): void {
		$this->rows = [
			'order' => [['id' => 'o-1', 'totalAmount' => 45.0, 'lifecycle' => 'paid'], ['id' => 'o-2', 'totalAmount' => 12.5, 'lifecycle' => 'open']],
			'order-line' => [['id' => 'l-1', 'orderId' => 'o-1']],
			'payment-transaction' => [['id' => 't-1', 'orderId' => 'o-1', 'lifecycle' => 'succeeded']],
		];
		$this->makeStep()->run($this->repairOutput());

		self::assertArrayHasKey('retired-payments.json', $this->files);
		$export = json_decode($this->files['retired-payments.json'], true);
		self::assertSame(['order' => 2, 'order-line' => 1, 'payment-transaction' => 1], $export['counts']);
		self::assertSame('o-2', $export['objects']['order'][1]['id']);
		self::assertSame('2026-09-27T12:00:00+00:00', $export['exportedAt']);
		self::assertStringContainsString('shillinq', $export['reason']);
		self::assertStringContainsString('2 order(s), 1 order line(s), 1 payment transaction(s)', end($this->messages));
	}//end testEveryRetiredRowIsWrittenToTheArchive()

	/**
	 * A second run writes nothing.
	 *
	 * @return void
	 */
	public function testASecondRunLeavesTheArchiveAlone(): void {
		$this->rows = ['order' => [['id' => 'o-1']]];
		$this->makeStep()->run($this->repairOutput());
		$first = $this->files['retired-payments.json'];

		$this->rows = ['order' => [['id' => 'o-1'], ['id' => 'o-2']]];
		$this->makeStep()->run($this->repairOutput());

		self::assertSame($first, $this->files['retired-payments.json']);
		self::assertStringContainsString('already exists', end($this->messages));
	}//end testASecondRunLeavesTheArchiveAlone()

	/**
	 * With no rows, or with the schemas already gone, no file is written.
	 *
	 * @return void
	 */
	public function testNoRowsMeansNoFile(): void {
		$this->makeStep()->run($this->repairOutput());
		$this->gone = ['order', 'order-line', 'payment-transaction'];
		$this->makeStep()->run($this->repairOutput());

		self::assertSame([], $this->files);
		self::assertStringContainsString('no orders, order lines or payment transactions', end($this->messages));
	}//end testNoRowsMeansNoFile()

	/**
	 * A schema that is already gone does not stop the others being archived.
	 *
	 * @return void
	 */
	public function testAMissingSchemaDoesNotStopTheOthers(): void {
		$this->rows = ['order' => [['id' => 'o-1']]];
		$this->gone = ['payment-transaction'];
		$this->makeStep()->run($this->repairOutput());

		$export = json_decode($this->files['retired-payments.json'], true);
		self::assertSame(['order' => 1, 'order-line' => 0, 'payment-transaction' => 0], $export['counts']);
	}//end testAMissingSchemaDoesNotStopTheOthers()

	/**
	 * The step runs before the register import in the upgrade path.
	 *
	 * @return void
	 */
	public function testTheStepRunsBeforeTheRegisterImport(): void {
		$info = (string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/info.xml');
		$start = (int)strpos($info, '<post-migration>');
		$postMigration = substr($info, $start, ((int)strpos($info, '</post-migration>') - $start));
		$archive = strpos($postMigration, 'Repair\\ArchiveRetiredPaymentObjects');
		$import = strpos($postMigration, 'Repair\\InitializeSettings');

		self::assertNotFalse($archive);
		self::assertNotFalse($import);
		self::assertLessThan($import, $archive);
	}//end testTheStepRunsBeforeTheRegisterImport()

	/**
	 * After the archive is written, a schema whose every row is in it reads
	 * as archived (retired-schemas-prune).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-schemas-learniq-retired-leave-the-instance-once-their-rows-are-kept-elsewhere
	 */
	public function testEveryRowInTheArchiveReadsAsArchived(): void {
		$this->rows = ['order' => [['id' => 'o-1'], ['id' => 'o-2']], 'order-line' => [['id' => 'l-1']]];
		$this->makeStep()->run($this->repairOutput());

		self::assertTrue($this->makeStep()->isFullyArchived(schema: 'order', expectedRows: 2));
		self::assertTrue($this->makeStep()->isFullyArchived(schema: 'order-line', expectedRows: 1));
	}//end testEveryRowInTheArchiveReadsAsArchived()

	/**
	 * A row written after the archive, a count that differs from what the
	 * prune would delete, or a schema the archive does not cover all read as
	 * not archived.
	 *
	 * @return void
	 */
	public function testAnythingOutsideTheArchiveReadsAsNotArchived(): void {
		$this->rows = ['order' => [['id' => 'o-1'], ['id' => 'o-2']]];
		$step = $this->makeStep();
		$step->run($this->repairOutput());

		self::assertFalse($step->isFullyArchived(schema: 'order', expectedRows: 5));
		self::assertFalse($step->isFullyArchived(schema: 'entitlement', expectedRows: 2));

		$this->rows['order'][] = ['id' => 'o-3'];
		self::assertFalse($step->isFullyArchived(schema: 'order', expectedRows: 3));
	}//end testAnythingOutsideTheArchiveReadsAsNotArchived()

	/**
	 * Without an archive, or with one that does not parse, nothing reads as
	 * archived.
	 *
	 * @return void
	 */
	public function testNoReadableArchiveMeansNotArchived(): void {
		$this->rows = ['order' => [['id' => 'o-1']]];
		self::assertFalse($this->makeStep()->isFullyArchived(schema: 'order', expectedRows: 1));

		$this->folderExists = true;
		$this->files['retired-payments.json'] = 'not json';
		self::assertFalse($this->makeStep()->isFullyArchived(schema: 'order', expectedRows: 1));
	}//end testNoReadableArchiveMeansNotArchived()

	/**
	 * Rows archived under `uuid` or `@self.id` read as archived; a current row
	 * without any id never does.
	 *
	 * @return void
	 */
	public function testEveryIdShapeCountsAndARowWithoutAnIdRefuses(): void {
		$this->folderExists = true;
		$this->files['retired-payments.json'] = (string)json_encode(
			['objects' => ['order' => [['uuid' => 'o-1'], ['@self' => ['id' => 'o-2']], 'not-a-row']]]
		);
		$this->rows = ['order' => [['id' => 'o-1'], ['id' => 'o-2']]];
		self::assertTrue($this->makeStep()->isFullyArchived(schema: 'order', expectedRows: 2));

		$this->files['retired-payments.json'] = (string)json_encode(['objects' => ['order' => [['id' => 'o-1']]]]);
		$this->rows = ['order' => [['id' => 'o-1'], ['id' => 'o-2']]];
		self::assertFalse($this->makeStep()->isFullyArchived(schema: 'order', expectedRows: 2));

		$this->files['retired-payments.json'] = (string)json_encode(5);
		self::assertFalse($this->makeStep()->isFullyArchived(schema: 'order', expectedRows: 2));
	}//end testEveryIdShapeCountsAndARowWithoutAnIdRefuses()

	/**
	 * A schema whose rows cannot be read counts as zero rows: archived when
	 * the prune would delete none, refused otherwise.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaCountsAsNoRows(): void {
		$this->folderExists = true;
		$this->files['retired-payments.json'] = (string)json_encode(['objects' => []]);
		$this->gone = ['order'];

		self::assertTrue($this->makeStep()->isFullyArchived(schema: 'order', expectedRows: 0));
		self::assertFalse($this->makeStep()->isFullyArchived(schema: 'order', expectedRows: 1));
	}//end testAnUnreadableSchemaCountsAsNoRows()

	/**
	 * More rows than one page are all read and archived, and the summary names
	 * the exact counts and the file.
	 *
	 * @return void
	 */
	public function testEveryPageIsReadAndTheSummaryIsExact(): void {
		$this->rows = ['order' => array_map(static fn (int $i): array => ['id' => 'o-' . $i], range(1, 450))];
		$this->makeStep()->run($this->repairOutput());

		$export = json_decode($this->files['retired-payments.json'], true);
		self::assertSame(['order' => 450, 'order-line' => 0, 'payment-transaction' => 0], $export['counts']);
		self::assertSame('learniq', $export['register']);
		self::assertSame(
			'Learniq payments archive: 450 order(s), 0 order line(s), 0 payment transaction(s) written to payments-archive/retired-payments.json.',
			end($this->messages)
		);
		self::assertTrue($this->makeStep()->isFullyArchived(schema: 'order', expectedRows: 450));
	}//end testEveryPageIsReadAndTheSummaryIsExact()

	/**
	 * An app data folder that cannot be opened, and a write that fails, are
	 * reported as warnings and leave no archive.
	 *
	 * @return void
	 */
	public function testUnwritableFolderAndFailedWriteAreReported(): void {
		$objectService = $this->createMock(ObjectService::class);
		$factory       = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willThrowException(new RuntimeException('no app data'));
		$step = new ArchiveRetiredPaymentObjects($objectService, $factory, $this->createMock(ITimeFactory::class), new NullLogger());
		$objectService->expects(self::never())->method('findAll');
		$step->run($this->repairOutput());
		self::assertSame('Learniq payments archive: the app data folder is not writable, nothing archived (no app data).', end($this->messages));

		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('fileExists')->willReturn(false);
		$folder->method('newFile')->willThrowException(new RuntimeException('disk full'));
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($folder);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);
		$finder = $this->createMock(ObjectService::class);
		$finder->method('findAll')->willReturnCallback(
			static fn (array $config = []): array => OrEntityFactory::makeMany(
				(($config['filters']['schema'] ?? '') === 'order' ? [['id' => 'o-1']] : []),
				'order'
			)
		);
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new DateTime('2026-09-27T12:00:00+00:00'));
		(new ArchiveRetiredPaymentObjects($finder, $factory, $clock, new NullLogger()))->run($this->repairOutput());
		self::assertSame('Learniq payments archive: could not write the archive (disk full).', end($this->messages));
	}//end testUnwritableFolderAndFailedWriteAreReported()

	/**
	 * The exact messages for an existing archive and for nothing to archive.
	 *
	 * @return void
	 */
	public function testTheNoOpMessagesAreExact(): void {
		$this->makeStep()->run($this->repairOutput());
		self::assertSame('Learniq payments archive: no orders, order lines or payment transactions to archive.', end($this->messages));

		$this->files['retired-payments.json'] = '{}';
		$this->makeStep()->run($this->repairOutput());
		self::assertSame('Learniq payments archive: payments-archive/retired-payments.json already exists, nothing to do.', end($this->messages));
	}//end testTheNoOpMessagesAreExact()
}//end class
