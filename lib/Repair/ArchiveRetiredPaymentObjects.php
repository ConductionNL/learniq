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
 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-retired-payment-rows-are-archived-before-their-schemas-go
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCA\Learniq\AppInfo\Application;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
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
 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-retired-payment-rows-are-archived-before-their-schemas-go
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
	 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-retired-payment-rows-are-archived-before-their-schemas-go
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
	 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-retired-payment-rows-are-archived-before-their-schemas-go
	 */
	public function run(IOutput $output): void {
		try {
			$folder = $this->folder();
			if ($folder->fileExists(self::FILE) === true) {
				$output->info('Learniq payments archive: ' . self::FOLDER . '/' . self::FILE . ' already exists, nothing to do.');
				return;
			}
		} catch (Throwable $exception) {
			$output->warning('Learniq payments archive: the app data folder is not writable, nothing archived (' . $exception->getMessage() . ').');
			return;
		}

		$rows = [];
		$total = 0;
		foreach (self::RETIRED_SCHEMAS as $schema) {
			$rows[$schema] = $this->readAll(schema: $schema);
			$total += count($rows[$schema]);
		}

		if ($total === 0) {
			$output->info('Learniq payments archive: no orders, order lines or payment transactions to archive.');
			return;
		}

		$export = [
			'exportedAt' => $this->timeFactory->getDateTime()->format(\DATE_ATOM),
			'reason' => 'payments-to-shillinq-migration (D19): learniq no longer keeps orders or payment transactions. '
				. 'School contributions are shillinq invoices paid from portaliq. These rows are history; an open order '
				. 'must be raised again in shillinq.',
			'register' => self::LEARNIQ_REGISTER,
			'counts' => array_map('count', $rows),
			'objects' => $rows,
		];

		try {
			$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
			$folder->newFile(self::FILE, (string)json_encode($export, $flags));
		} catch (Throwable $exception) {
			$output->warning('Learniq payments archive: could not write the archive (' . $exception->getMessage() . ').');
			return;
		}

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
	}//end run()

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
			for ($page = 0; $page < self::MAX_PAGES; $page++) {
				$objects = $this->objectService->findAll(
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

				foreach ($objects as $object) {
					if (is_array($object) === false) {
						$object = (array)$object->jsonSerialize();
					}

					$rows[] = $object;
				}

				if (count($objects) < self::PAGE_SIZE) {
					break;
				}
			}
		} catch (Throwable $exception) {
			// The schema is already gone or OpenRegister is down: nothing to read.
			$this->logger->info(
				'[ArchiveRetiredPaymentObjects] No {schema} rows read: {msg}',
				['schema' => $schema, 'msg' => $exception->getMessage()]
			);
		}//end try

		return $rows;
	}//end readAll()
}//end class
