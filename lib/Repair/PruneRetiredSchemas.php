<?php

/**
 * Repair step removing the schemas learniq retired from its register (D19, D20)
 * from OpenRegister instances that imported them.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-schemas-learniq-retired-leave-the-instance-once-their-rows-are-kept-elsewhere
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\SchemaDeletionService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Prunes the retired order, order-line, payment-transaction and
 * data-subject-request schemas through OpenRegister's retirement path.
 *
 * WHY. Dropping a schema from `learniq_register.json` is only half its
 * retirement: OpenRegister's import unions schema ids into the register and
 * never removes one, so on every instance that imported the old descriptor the
 * four schemas keep their row, their table and their place in the register.
 * OpenRegister's answer is `occ openregister:schemas:prune-retired`
 * (PruneRetiredSchemasCommand, spec schema-import "A schema retired from a
 * descriptor MUST be removable from the instance"). This step runs the same
 * mechanics on upgrade, so no administrator has to remember the command:
 * every row under (application, slug), unlink from every register first, then
 * `SchemaDeletionService::cascadeDeleteSchema()`.
 *
 * ROWS ARE KEPT ELSEWHERE FIRST. The command refuses a schema that still owns
 * objects unless `--force`. This step never forces blindly: a payment schema
 * is pruned with its rows only when ArchiveRetiredPaymentObjects' archive file
 * holds every current row of it, which is what that archive exists for.
 * data-subject-request is pruned only when it owns no rows: its rows are AVG
 * records, and privacy-reuse-openregister-register decided a repair step does
 * not delete them; a school drops them with the command after checking the
 * copies in OpenRegister's register. Whatever stays is reported and retried on
 * the next upgrade. A schema that declares `x-openregister-archival` is never
 * pruned here.
 *
 * APP-SCOPED. Schemas are resolved by application: `learniq`, and `scholiq`
 * for an instance whose last import predates the rename (an import stamps the
 * application only on schemas still in the descriptor). No other app owns a
 * schema under either id.
 *
 * IDEMPOTENT. Once pruned, the lookup finds nothing and the step logs a no-op.
 * It never raises: a repair step that aborted the upgrade over a retired
 * schema would trade dead data for an instance that will not start.
 *
 * ORDER. After MigrateDataSubjectRequestsToOpenRegister and
 * ArchiveRetiredPaymentObjects, which read the rows first.
 *
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-schemas-learniq-retired-leave-the-instance-once-their-rows-are-kept-elsewhere
 */
class PruneRetiredSchemas implements IRepairStep {

	/**
	 * The application ids a learniq schema can carry.
	 *
	 * @var array<int, string>
	 */
	public const APPLICATIONS = ['learniq', 'scholiq'];

	/**
	 * The retired payment slugs (D19), exported by ArchiveRetiredPaymentObjects.
	 *
	 * @var array<int, string>
	 */
	public const PAYMENT_SLUGS = ArchiveRetiredPaymentObjects::RETIRED_SCHEMAS;

	/**
	 * The retired privacy request slug (D20), copied by
	 * MigrateDataSubjectRequestsToOpenRegister. Pruned only when empty.
	 */
	public const DSR_SLUG = 'data-subject-request';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server container, for lazy OpenRegister resolution
	 *                                      (OpenRegister may be absent or predate the deletion service).
	 * @param ArchiveRetiredPaymentObjects $paymentArchive The payments archive, which says what it holds.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ArchiveRetiredPaymentObjects $paymentArchive,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-schemas-learniq-retired-leave-the-instance-once-their-rows-are-kept-elsewhere
	 */
	public function getName(): string {
		return 'Remove the retired order, payment and privacy request schemas from OpenRegister';
	}//end getName()

	/**
	 * Prune every retired schema whose rows are kept elsewhere.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-schemas-learniq-retired-leave-the-instance-once-their-rows-are-kept-elsewhere
	 */
	public function run(IOutput $output): void {
		try {
			$schemaMapper = $this->container->get(SchemaMapper::class);
			$registerMapper = $this->container->get(RegisterMapper::class);
			$deletionService = $this->container->get(SchemaDeletionService::class);
		} catch (Throwable $exception) {
			$output->info('Learniq retired schemas: OpenRegister schema pruning is not available, nothing pruned.');
			$this->logger->info('[PruneRetiredSchemas] Skipped: ' . $exception->getMessage());
			return;
		}

		foreach ([...self::PAYMENT_SLUGS, self::DSR_SLUG] as $slug) {
			foreach ($this->schemasFor(schemaMapper: $schemaMapper, slug: $slug) as $schema) {
				try {
					$this->pruneOne(
						schema: $schema,
						slug: $slug,
						registerMapper: $registerMapper,
						deletionService: $deletionService,
						output: $output
					);
				} catch (Throwable $exception) {
					$this->logger->warning(
						'[PruneRetiredSchemas] Could not prune "{slug}": {msg}',
						['slug' => $slug, 'msg' => $exception->getMessage(), 'exception' => $exception]
					);
					$output->warning('Learniq retired schemas: could not prune "' . $slug . '" (' . $exception->getMessage() . ').');
				}
			}
		}
	}//end run()

	/**
	 * Every schema row under this slug that a learniq application id owns.
	 *
	 * @param SchemaMapper $schemaMapper The schema store.
	 * @param string $slug The retired slug.
	 *
	 * @return array<int, Schema>
	 */
	private function schemasFor(SchemaMapper $schemaMapper, string $slug): array {
		$schemas = [];
		foreach (self::APPLICATIONS as $application) {
			try {
				$schemas = [...$schemas, ...$schemaMapper->findAllByApplicationAndSlug(slug: $slug, application: $application)];
			} catch (Throwable $exception) {
				$this->logger->info(
					'[PruneRetiredSchemas] Lookup of "{slug}" for {app} failed: {msg}',
					['slug' => $slug, 'app' => $application, 'msg' => $exception->getMessage()]
				);
			}
		}

		return $schemas;
	}//end schemasFor()

	/**
	 * Prune one schema row, or report why it stays.
	 *
	 * @param Schema $schema The retired schema row.
	 * @param string $slug Its slug.
	 * @param RegisterMapper $registerMapper The register store.
	 * @param SchemaDeletionService $deletionService OpenRegister's cascade teardown.
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 */
	private function pruneOne(
		Schema $schema,
		string $slug,
		RegisterMapper $registerMapper,
		SchemaDeletionService $deletionService,
		IOutput $output
	): void {
		if ($schema->hasArchivalAnnotation() === true) {
			$output->warning('Learniq retired schemas: "' . $slug . '" declares archival retention, left in place.');
			return;
		}

		$objectCount = $deletionService->countObjectsCascadeWouldDelete(schema: $schema);
		if ($this->rowsKeptElsewhere(slug: $slug, objectCount: $objectCount) === false) {
			$output->warning($this->keptMessage(slug: $slug, objectCount: $objectCount));
			return;
		}

		$this->unlinkFromRegisters(registerMapper: $registerMapper, schemaId: (int)$schema->getId());
		$result = $deletionService->cascadeDeleteSchema(schema: $schema);
		$output->info(
			'Learniq retired schemas: pruned "' . $slug . '" (rows removed: ' . (int)$result['deletedCount'] . ').'
		);
	}//end pruneOne()

	/**
	 * Whether every row of a retired schema is kept somewhere else, so pruning
	 * it destroys nothing that exists only here.
	 *
	 * @param string $slug The retired slug.
	 * @param int $objectCount Rows the schema owns.
	 *
	 * @return bool
	 */
	private function rowsKeptElsewhere(string $slug, int $objectCount): bool {
		if ($objectCount === 0) {
			return true;
		}

		if ($slug === self::DSR_SLUG) {
			return false;
		}

		return $this->paymentArchive->isFullyArchived(schema: $slug, expectedRows: $objectCount);
	}//end rowsKeptElsewhere()

	/**
	 * What the upgrade output says about a schema left in place.
	 *
	 * @param string $slug The retired slug.
	 * @param int $objectCount Rows the schema owns.
	 *
	 * @return string
	 */
	private function keptMessage(string $slug, int $objectCount): string {
		if ($slug === self::DSR_SLUG) {
			return 'Learniq retired schemas: "' . $slug . '" still owns ' . $objectCount . ' privacy request(s),'
				. ' left in place. Check their copies in OpenRegister\'s data subject request register, then run'
				. ' occ openregister:schemas:prune-retired --app learniq --slug ' . $slug . ' --apply --force.';
		}

		return 'Learniq retired schemas: "' . $slug . '" still owns ' . $objectCount
			. ' row(s) that are not all in the payments archive, left in place until the next upgrade.';
	}//end keptMessage()

	/**
	 * Drop the schema id from every register that references it, before the
	 * row goes, with the prune command's own coercion rule.
	 *
	 * @param RegisterMapper $registerMapper The register store.
	 * @param int $schemaId The schema id.
	 *
	 * @return void
	 */
	private function unlinkFromRegisters(RegisterMapper $registerMapper, int $schemaId): void {
		foreach ($registerMapper->findAll(_rbac: false, _multitenancy: false) as $register) {
			$refs = $register->getSchemas();
			$remaining = self::unlinkSchemaId(schemaRefs: $refs, schemaId: $schemaId);
			if (count($remaining) === count($refs)) {
				continue;
			}

			$register->setSchemas($remaining);
			$registerMapper->update($register);
		}
	}//end unlinkFromRegisters()

	/**
	 * Drop one schema id from a register's stored list. Ids are stored as ints
	 * or numeric strings depending on the import era, so both forms go; any
	 * other entry is kept. Mirrors PruneRetiredSchemasCommand::unlinkSchemaId().
	 *
	 * @param array<int, mixed> $schemaRefs The register's stored schema list.
	 * @param int $schemaId The schema id to remove.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-schemas-learniq-retired-leave-the-instance-once-their-rows-are-kept-elsewhere
	 */
	public static function unlinkSchemaId(array $schemaRefs, int $schemaId): array {
		return array_values(
			array_filter(
				$schemaRefs,
				static function (mixed $ref) use ($schemaId): bool {
					if (is_int($ref) === false && (is_string($ref) === false || is_numeric($ref) === false)) {
						return true;
					}

					return ((int)$ref !== $schemaId);
				}
			)
		);
	}//end unlinkSchemaId()
}//end class
