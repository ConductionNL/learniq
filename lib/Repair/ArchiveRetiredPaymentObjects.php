<?php

/**
 * Repair step exporting learniq's retired Order, OrderLine and PaymentTransaction
 * rows to a JSON file in the app data folder (D19, payments-to-shillinq-migration).
 *
 * @category Repair
 * @package  OCA\Learniq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/payments/spec.md#requirement-retired-payment-rows-are-archived-before-their-schemas-go
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCA\Learniq\AppInfo\Application;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes every retired payment row to `payments-archive/retired-payments.json`.
 *
 * WHY. Decision D19 moves school contributions to shillinq invoices paid from
 * portaliq. Learniq's own Order, OrderLine and PaymentTransaction schemas leave
 * the register, so their rows would sit in OpenRegister with no page, no
 * schema definition and no owner. They are financial records a school may
 * have to show an accountant, so before the schemas go every row is written
 * to one JSON file in learniq's app data folder, readable by an administrator.
 *
 * NOT A MIGRATION. The rows are not turned into shillinq invoices: an order
 * that was paid is history, and an open one is for the school to raise again
 * in shillinq. The export says so in its header. The rows themselves are left
 * in place.
 *
 * IDEMPOTENT. When the archive file exists the step does nothing, so an
 * upgrade that runs twice writes one file. A run that finds no rows writes
 * nothing, so a fresh install gets no empty archive.
 *
 * ORDER. Runs before InitializeSettings, while the retired schemas and their
 * rows are certainly still readable.
 *
 * @spec openspec/specs/payments/spec.md#requirement-retired-payment-rows-are-archived-before-their-schemas-go
 */
class ArchiveRetiredPaymentObjects implements IRepairStep {

	public const FOLDER = 'payments-archive';
	public const FILE = 'retired-payments.json';

	private const LEARNIQ_REGISTER = 'learniq';
	private const PAGE_SIZE = 200;
	private const MAX_PAGES = 1000;

	/**
	 * The retired schema slugs, in export order.
	 *
	 * @var array<int, string>
	 */
	public const RETIRED_SCHEMAS = ['order', 'order-line', 'payment-transaction'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param IAppDataFactory $appDataFactory Learniq's app data folder.
	 * @param ITimeFactory $timeFactory Clock for the export header.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IAppDataFactory $appDataFactory,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/payments/spec.md#requirement-retired-payment-rows-are-archived-before-their-schemas-go
	 */
	public function getName(): string {
		return 'Archive the retired orders, order lines and payment transactions to a JSON file';
	}//end getName()

	/**
	 * Export every retired payment row once.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payments/spec.md#requirement-retired-payment-rows-are-archived-before-their-schemas-go
	 */
	public function run(IOutput $output): void {
		$folder = $this->freshFolder(output: $output);
		if ($folder !== null) {
			$this->archive(folder: $folder, output: $output);
		}
	}//end run()

	/**
	 * The archive folder when no archive is in it yet, or null after saying why
	 * nothing is written: the archive exists, or the folder is not writable.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return ISimpleFolder|null
	 */
	private function freshFolder(IOutput $output): ?ISimpleFolder {
		try {
			$folder = $this->folder();
			if ($folder->fileExists(self::FILE) === true) {
				$output->info('Learniq payments archive: ' . self::FOLDER . '/' . self::FILE . ' already exists, nothing to do.');
				return null;
			}
		} catch (Throwable $exception) {
			$output->warning('Learniq payments archive: the app data folder is not writable, nothing archived (' . $exception->getMessage() . ').');
			return null;
		}

		return $folder;
	}//end freshFolder()

	/**
	 * Read every retired row and write the archive, unless there are none.
	 *
	 * @param ISimpleFolder $folder The archive folder.
	 * @param IOutput       $output Repair output.
	 *
	 * @return void
	 */
	private function archive(ISimpleFolder $folder, IOutput $output): void {
		$rows = $this->retiredRows();
		if (array_sum(array_map('count', $rows)) === 0) {
			$output->info('Learniq payments archive: no orders, order lines or payment transactions to archive.');
			return;
		}

		$export = $this->exportOf(rows: $rows);
		try {
			$this->write(folder: $folder, export: $export);
		} catch (Throwable $exception) {
			$output->warning('Learniq payments archive: could not write the archive (' . $exception->getMessage() . ').');
			return;
		}

		$this->report(output: $output, rows: $rows);
	}//end archive()

	/**
	 * Write the archive document to the archive file.
	 *
	 * @param ISimpleFolder        $folder The archive folder.
	 * @param array<string, mixed> $export The archive document.
	 *
	 * @return void
	 *
	 * @throws \JsonException When the document does not encode.
	 */
	private function write(ISimpleFolder $folder, array $export): void {
		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		$folder->newFile(self::FILE, (string)json_encode($export, $flags));
	}//end write()

	/**
	 * Every row of every retired schema, by schema slug in export order.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function retiredRows(): array {
		$rows = [];
		foreach (self::RETIRED_SCHEMAS as $schema) {
			$rows[$schema] = $this->readAll(schema: $schema);
		}

		return $rows;
	}//end retiredRows()

	/**
	 * The archive document: header, counts and the rows themselves.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows Rows by schema slug.
	 *
	 * @return array<string, mixed>
	 */
	private function exportOf(array $rows): array {
		return [
			'exportedAt' => $this->timeFactory->getDateTime()->format(\DATE_ATOM),
			'reason' => 'payments-to-shillinq-migration (D19): learniq no longer keeps orders or payment transactions. '
				. 'School contributions are shillinq invoices paid from portaliq. These rows are history; an open order '
				. 'must be raised again in shillinq.',
			'register' => self::LEARNIQ_REGISTER,
			'counts' => array_map('count', $rows),
			'objects' => $rows,
		];
	}//end exportOf()

	/**
	 * Say, in the repair output and the log, what the archive holds.
	 *
	 * @param IOutput                                         $output Repair output.
	 * @param array<string, array<int, array<string, mixed>>> $rows   Rows by schema slug.
	 *
	 * @return void
	 */
	private function report(IOutput $output, array $rows): void {
		$summary = sprintf(
			'Learniq payments archive: %d order(s), %d order line(s), %d payment transaction(s) written to %s/%s.',
			count($rows['order']),
			count($rows['order-line']),
			count($rows['payment-transaction']),
			self::FOLDER,
			self::FILE
		);
		$output->info($summary);
		$this->logger->info('[ArchiveRetiredPaymentObjects] ' . $summary);
	}//end report()

	/**
	 * Whether every current row of one retired schema is in the archive, so
	 * the schema can be pruned with its rows (retired-schemas-prune).
	 *
	 * True only when the archive file exists, the schema's rows can be read,
	 * their number equals what the prune would delete, and every row's id is
	 * among the archived rows of that schema. Any failure answers false.
	 *
	 * @param string $schema       Retired schema slug, one of RETIRED_SCHEMAS.
	 * @param int    $expectedRows Rows the prune would delete.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-schemas-learniq-retired-leave-the-instance-once-their-rows-are-kept-elsewhere
	 */
	public function isFullyArchived(string $schema, int $expectedRows): bool {
		if (in_array($schema, self::RETIRED_SCHEMAS, true) === false) {
			return false;
		}

		$archived = $this->archivedIds(schema: $schema);
		if ($archived === null) {
			return false;
		}

		$current = $this->readAll(schema: $schema);
		if (count($current) !== $expectedRows) {
			return false;
		}

		return $this->allArchived(current: $current, archived: $archived);
	}//end isFullyArchived()

	/**
	 * The ids of the archived rows of one schema, or null when there is no
	 * readable archive.
	 *
	 * @param string $schema Retired schema slug.
	 *
	 * @return array<int, string>|null
	 */
	private function archivedIds(string $schema): ?array {
		try {
			$folder = $this->folder();
			if ($folder->fileExists(self::FILE) === false) {
				return null;
			}

			$export = json_decode($folder->getFile(self::FILE)->getContent(), true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $exception) {
			$this->logUnreadableArchive(exception: $exception);
			return null;
		}

		return array_map(fn (mixed $row): string => $this->idOf(row: $row), (array)($export['objects'][$schema] ?? []));
	}//end archivedIds()

	/**
	 * Log that the archive could not be read.
	 *
	 * @param Throwable $exception Why it could not be read.
	 *
	 * @return void
	 */
	private function logUnreadableArchive(Throwable $exception): void {
		$this->logger->info(
			'[ArchiveRetiredPaymentObjects] Archive not readable: {msg}',
			['msg' => $exception->getMessage()]
		);
	}//end logUnreadableArchive()

	/**
	 * Whether every current row carries an id that is among the archived ids.
	 *
	 * @param array<int, array<string, mixed>> $current  The schema's current rows.
	 * @param array<int, string>               $archived The archived ids.
	 *
	 * @return bool
	 */
	private function allArchived(array $current, array $archived): bool {
		foreach ($current as $row) {
			$id = $this->idOf(row: $row);
			if ($id === '' || in_array($id, $archived, true) === false) {
				return false;
			}
		}

		return true;
	}//end allArchived()

	/**
	 * The object uuid of a serialised row: `id`, `uuid` or `@self.id`, or ''.
	 *
	 * @param mixed $row Serialised row.
	 *
	 * @return string
	 */
	private function idOf(mixed $row): string {
		if (is_array($row) === false) {
			return '';
		}

		foreach ([($row['id'] ?? null), ($row['uuid'] ?? null), ($row['@self']['id'] ?? null)] as $candidate) {
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}
		}

		return '';
	}//end idOf()

	/**
	 * The archive folder, created when missing.
	 *
	 * @return \OCP\Files\SimpleFS\ISimpleFolder
	 */
	private function folder(): \OCP\Files\SimpleFS\ISimpleFolder {
		$appData = $this->appDataFactory->get(Application::APP_ID);
		try {
			return $appData->getFolder(self::FOLDER);
		} catch (NotFoundException $exception) {
			return $appData->newFolder(self::FOLDER);
		}
	}//end folder()

	/**
	 * Every row of one retired schema, as arrays; empty when the schema is gone.
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function readAll(string $schema): array {
		$rows = [];
		try {
			$this->readPages(schema: $schema, rows: $rows);
		} catch (Throwable $exception) {
			// The schema is already gone or OpenRegister is down: nothing to read.
			$this->logger->info(
				'[ArchiveRetiredPaymentObjects] No {schema} rows read: {msg}',
				['schema' => $schema, 'msg' => $exception->getMessage()]
			);
		}

		return $rows;
	}//end readAll()

	/**
	 * Read one schema page by page into $rows, until a short page.
	 *
	 * The rows of every page read before a failure stay in $rows.
	 *
	 * @param string                           $schema Schema slug.
	 * @param array<int, array<mixed>>         $rows   The rows read so far, appended to.
	 *
	 * @return void
	 */
	private function readPages(string $schema, array &$rows): void {
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$objects = $this->findPage(schema: $schema, page: $page);
			$this->collect(rows: $rows, objects: $objects);
			if (count($objects) < self::PAGE_SIZE) {
				return;
			}
		}
	}//end readPages()

	/**
	 * One page of a schema's rows in the learniq register, unfiltered by RBAC
	 * or tenancy.
	 *
	 * @param string $schema Schema slug.
	 * @param int    $page   Zero-based page number.
	 *
	 * @return array<int, mixed>
	 */
	private function findPage(string $schema, int $page): array {
		return $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => $schema,
				],
				'limit' => self::PAGE_SIZE,
				'offset' => ($page * self::PAGE_SIZE),
			],
			_rbac: false,
			_multitenancy: false
		);
	}//end findPage()

	/**
	 * Append a page of objects to $rows as arrays.
	 *
	 * @param array<int, array<mixed>>         $rows    The rows read so far, appended to.
	 * @param array<int, mixed>                $objects One page of objects or arrays.
	 *
	 * @return void
	 */
	private function collect(array &$rows, array $objects): void {
		foreach ($objects as $object) {
			if (is_array($object) === false) {
				$object = (array)$object->jsonSerialize();
			}

			$rows[] = $object;
		}
	}//end collect()
}//end class
