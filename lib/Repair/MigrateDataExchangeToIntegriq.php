<?php

/**
 * Learniq Migrate Data Exchange To Integriq repair step
 *
 * Moves learniq's retired data exchange rows to integriq (decision D7): every
 * row of DataExchangeJob, DataMappingProfile, ExchangeRejection and
 * ExchangeErrorCode is archived to a file, and when integriq is installed each
 * job (with its rejections) and each customised mapping profile is handed to
 * integriq through its typed events.
 *
 * @category Repair
 * @package  OCA\Learniq\Repair
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-existing-exchange-rows-are-moved-to-integriq-or-archived
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\IntegriqExchangeClient;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Archive always, migrate once when integriq is there, delete nothing.
 *
 * The schemas are gone from the register, but OpenRegister's import never
 * deletes a schema, so the rows stay readable by slug on an existing install
 * until this step has copied them. A new install has nothing to move.
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-existing-exchange-rows-are-moved-to-integriq-or-archived
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MigrateDataExchangeToIntegriq implements IRepairStep {

	public const FOLDER = 'data-exchange-archive';
	public const FILE = 'retired-data-exchange.json';
	public const MIGRATED_KEY = 'exchange_migrated_to_integriq';

	private const LEARNIQ_REGISTER = 'learniq';
	private const PAGE_SIZE = 200;
	private const MAX_PAGES = 500;

	/**
	 * The retired schema slugs, in export order.
	 *
	 * @var array<int, string>
	 */
	public const RETIRED_SCHEMAS = ['data-exchange-job', 'data-mapping-profile', 'exchange-rejection', 'exchange-error-code'];

	/**
	 * Schemas whose `dataExchangeJobId` named a learniq job.
	 *
	 * @var array<int, string>
	 */
	private const REFERRING_SCHEMAS = ['attendance-flag', 'support-request', 'school-advies', 'lvs-result', 'oso-import-dossier'];

	/**
	 * The 23 seeded profile names and the integriq mapping slugs they became.
	 *
	 * @var array<string, string>
	 */
	public const SEEDED_PROFILES = LegacyExchangeTranslator::SEEDED_PROFILES;

	/**
	 * Constructor.
	 *
	 * @param ObjectService            $objectService  OpenRegister object access.
	 * @param IntegriqExchangeClient   $integriq       Hands rows to integriq.
	 * @param LegacyExchangeTranslator $translator     Turns old rows into integriq requests.
	 * @param IAppDataFactory          $appDataFactory Learniq's app data folder.
	 * @param IAppConfig               $appConfig      Remembers that the move ran.
	 * @param ITimeFactory             $timeFactory    Clock for the archive header.
	 * @param LoggerInterface          $logger         PSR logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IntegriqExchangeClient $integriq,
		private readonly LegacyExchangeTranslator $translator,
		private readonly IAppDataFactory $appDataFactory,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-existing-exchange-rows-are-moved-to-integriq-or-archived
	 */
	public function getName(): string {
		return 'Move learniq\'s data exchange jobs and mappings to integriq, archiving every row first';
	}//end getName()

	/**
	 * Archive, then migrate once.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-existing-exchange-rows-are-moved-to-integriq-or-archived
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueString(Application::APP_ID, self::MIGRATED_KEY, '') !== '') {
			$output->info('Learniq data exchange: already moved to integriq, nothing to do.');
			return;
		}

		$rows = [];
		$total = 0;
		foreach (self::RETIRED_SCHEMAS as $schema) {
			$rows[$schema] = $this->readAll(schema: $schema);
			$total += count($rows[$schema]);
		}

		if ($total === 0) {
			$output->info('Learniq data exchange: no retired exchange rows, nothing to move.');
			$this->markMigrated();
			return;
		}

		$this->archive(rows: $rows, output: $output);

		if ($this->integriq->isAvailable() === false) {
			$output->warning(
				'Learniq data exchange: integriq is not installed, so ' . $total . ' row(s) were archived only. '
				. 'Install integriq and upgrade learniq again to move the jobs.'
			);
			return;
		}

		$moved = $this->migrateJobs(jobs: $rows['data-exchange-job'], rejections: $rows['exchange-rejection'], profiles: $rows['data-mapping-profile']);
		$mappings = $this->migrateMappings(profiles: $rows['data-mapping-profile']);
		$this->markMigrated();

		$summary = sprintf(
			'Learniq data exchange: %d of %d job(s) and %d customised mapping(s) moved to integriq; error codes archived only.',
			$moved,
			count($rows['data-exchange-job']),
			$mappings
		);
		$output->info($summary);
		$this->logger->info('[MigrateDataExchangeToIntegriq] ' . $summary);
	}//end run()

	/**
	 * Write every row to the archive file, once.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows   Rows per schema.
	 * @param IOutput                                          $output Repair output.
	 *
	 * @return void
	 */
	private function archive(array $rows, IOutput $output): void {
		try {
			$appData = $this->appDataFactory->get(Application::APP_ID);
			try {
				$folder = $appData->getFolder(self::FOLDER);
			} catch (NotFoundException $exception) {
				$folder = $appData->newFolder(self::FOLDER);
			}

			if ($folder->fileExists(self::FILE) === true) {
				return;
			}

			$export = [
				'exportedAt' => $this->timeFactory->getDateTime()->format(\DATE_ATOM),
				'reason' => 'data-exchange-to-integriq (D7): learniq no longer keeps data exchange jobs, mapping profiles, '
					. 'rejections or error codes. They are integriq job, mapping and sync_item_dead_letter rows now.',
				'register' => self::LEARNIQ_REGISTER,
				'counts' => array_map('count', $rows),
				'objects' => $rows,
			];
			$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
			$folder->newFile(self::FILE, (string)json_encode($export, $flags));
			$output->info('Learniq data exchange: rows archived to ' . self::FOLDER . '/' . self::FILE . '.');
		} catch (Throwable $exception) {
			$output->warning('Learniq data exchange: could not write the archive (' . $exception->getMessage() . ').');
		}
	}//end archive()

	/**
	 * Hand every job to integriq with its history and rejections.
	 *
	 * @param array<int, array<string, mixed>> $jobs       The job rows.
	 * @param array<int, array<string, mixed>> $rejections The rejection rows.
	 * @param array<int, array<string, mixed>> $profiles   The mapping profile rows.
	 *
	 * @return int How many jobs integriq took.
	 */
	private function migrateJobs(array $jobs, array $rejections, array $profiles): int {
		$slugByProfile = [];
		foreach ($profiles as $profile) {
			$slugByProfile[(string)($profile['id'] ?? '')] = $this->translator->slugOf(profile: $profile);
		}

		$moved = 0;
		foreach ($jobs as $job) {
			$legacyId = (string)($job['id'] ?? ($job['uuid'] ?? ''));
			try {
				$newId = $this->integriq->requestJob(
					target: (string)($job['target'] ?? ''),
					direction: (string)($job['direction'] ?? 'export'),
					ownerRef: $this->translator->ownerRefOf(job: $job, legacyId: $legacyId),
					scope: $this->translator->scopeOf(job: $job),
					mappingSlug: ($slugByProfile[(string)($job['mappingProfileId'] ?? '')] ?? null),
					requestedBy: (string)($job['requestedBy'] ?? 'system'),
					name: 'Migrated ' . (string)($job['target'] ?? '') . ' job',
					history: $this->translator->historyOf(job: $job, legacyId: $legacyId, rejections: $rejections)
				);
			} catch (Throwable $exception) {
				$this->logger->warning('[MigrateDataExchangeToIntegriq] job ' . $legacyId . ' not moved: ' . $exception->getMessage());
				continue;
			}

			$moved++;
			$this->openPendingReview(job: $job, newId: $newId);
			$this->remapReferences(legacyId: $legacyId, newId: $newId);
		}//end foreach

		return $moved;
	}//end migrateJobs()

	/**
	 * Hand every customised mapping profile to integriq; seeded ones exist there.
	 *
	 * @param array<int, array<string, mixed>> $profiles The profile rows.
	 *
	 * @return int How many mappings integriq stored.
	 */
	private function migrateMappings(array $profiles): int {
		$stored = 0;
		foreach ($profiles as $profile) {
			$name = (string)($profile['name'] ?? '');
			if ($name === '' || isset(self::SEEDED_PROFILES[$name]) === true) {
				continue;
			}

			$rules = $this->translator->rulesOf(profile: $profile);
			if ($rules === []) {
				continue;
			}

			try {
				$this->integriq->requestMapping(
					slug: $this->translator->slugOf(profile: $profile),
					name: 'learniq: ' . $name,
					description: 'Migrated from learniq DataMappingProfile "' . $name . '" (target ' . (string)($profile['target'] ?? '') . ').',
					mapping: $rules
				);
				$stored++;
			} catch (Throwable $exception) {
				$this->logger->warning('[MigrateDataExchangeToIntegriq] mapping "' . $name . '" not moved: ' . $exception->getMessage());
			}
		}

		return $stored;
	}//end migrateMappings()

	/**
	 * Point the rows that named the old job at its integriq job.
	 *
	 * Without this an attendance flag in flight would name a job integriq does
	 * not know, and its report guard would refuse it forever.
	 *
	 * @param string $legacyId The old job id.
	 * @param string $newId    The integriq job id.
	 *
	 * @return void
	 */
	private function remapReferences(string $legacyId, string $newId): void {
		foreach (self::REFERRING_SCHEMAS as $schema) {
			try {
				$objects = $this->objectService->findAll(
					config: [
						'filters' => ['register' => self::LEARNIQ_REGISTER, 'schema' => $schema, 'dataExchangeJobId' => $legacyId],
						'limit' => self::PAGE_SIZE,
					],
					_rbac: false,
					_multitenancy: false
				);
				foreach ($objects as $object) {
					$row = $object;
					if (is_array($object) === false) {
						$row = $object->jsonSerialize();
					}

					$uuid = (string)($row['id'] ?? ($row['uuid'] ?? ''));
					if ($uuid === '') {
						continue;
					}

					$row['dataExchangeJobId'] = $newId;
					$this->objectService->saveObject(object: $row, register: self::LEARNIQ_REGISTER, schema: $schema, uuid: $uuid);
				}
			} catch (Throwable $exception) {
				$this->logger->warning(
					'[MigrateDataExchangeToIntegriq] ' . $schema . ' rows naming job ' . $legacyId . ' not repointed: ' . $exception->getMessage()
				);
			}
		}//end foreach
	}//end remapReferences()

	/**
	 * A job that waited for a parent keeps waiting: open its DossierReview.
	 *
	 * @param array<string, mixed> $job   The old job.
	 * @param string               $newId The integriq job id.
	 *
	 * @return void
	 */
	private function openPendingReview(array $job, string $newId): void {
		$target = (string)($job['target'] ?? '');
		$learner = (string)($job['scope']['filters']['learnerId'] ?? '');
		if (($job['lifecycle'] ?? '') !== 'pending-parent-review' || in_array($target, ['oso', 'swv'], true) === false || $learner === '') {
			return;
		}

		try {
			$this->objectService->saveObject(
				register: self::LEARNIQ_REGISTER,
				schema: 'dossier-review',
				object: [
					'exchangeJobId' => $newId,
					'target' => $target,
					'learnerUserId' => $learner,
					'status' => 'pending',
					'tenant_id' => (string)($job['tenant_id'] ?? ''),
				]
			);
		} catch (Throwable $exception) {
			$this->logger->warning('[MigrateDataExchangeToIntegriq] no review opened for job ' . $newId . ': ' . $exception->getMessage());
		}
	}//end openPendingReview()

	/**
	 * Every row of one retired schema; empty when the schema is gone.
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
						'filters' => ['register' => self::LEARNIQ_REGISTER, 'schema' => $schema],
						'limit' => self::PAGE_SIZE,
						'offset' => ($page * self::PAGE_SIZE),
					],
					_rbac: false,
					_multitenancy: false
				);

				foreach ($objects as $object) {
					if (is_array($object) === false) {
						$object = $object->jsonSerialize();
					}

					$rows[] = $object;
				}

				if (count($objects) < self::PAGE_SIZE) {
					break;
				}
			}
		} catch (Throwable $exception) {
			$this->logger->info('[MigrateDataExchangeToIntegriq] ' . $schema . ' is not readable, nothing to move: ' . $exception->getMessage());
		}

		return $rows;
	}//end readAll()

	/**
	 * Remember that the move ran.
	 *
	 * @return void
	 */
	private function markMigrated(): void {
		$this->appConfig->setValueString(
			Application::APP_ID,
			self::MIGRATED_KEY,
			$this->timeFactory->getDateTime()->format(\DATE_ATOM)
		);
	}//end markMigrated()
}//end class
