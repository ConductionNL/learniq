<?php

/**
 * Learniq PruneRetiredSchemas repair step tests.
 *
 * @category Tests
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
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-schemas-learniq-retired-leave-the-instance-once-their-rows-are-kept-elsewhere
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use OCA\Learniq\Repair\ArchiveRetiredPaymentObjects;
use OCA\Learniq\Repair\PruneRetiredSchemas;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\SchemaDeletionService;
use OCP\AppFramework\Db\Entity;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for PruneRetiredSchemas::run().
 */
class PruneRetiredSchemasTest extends TestCase {

	/**
	 * Schemas by "application/slug".
	 *
	 * @var array<string, array<int, Schema>>
	 */
	private array $schemas = [];

	/**
	 * Rows each schema id owns.
	 *
	 * @var array<int, int>
	 */
	private array $objectCounts = [];

	/**
	 * Schema ids declaring x-openregister-archival.
	 *
	 * @var array<int, int>
	 */
	private array $archival = [];

	/**
	 * Slugs whose rows are all in the payments archive.
	 *
	 * @var array<int, string>
	 */
	private array $archived = [];

	/**
	 * Schema ids whose deletion throws.
	 *
	 * @var array<int, int>
	 */
	private array $failingDeletes = [];

	/**
	 * The registers, with their stored schema lists.
	 *
	 * @var array<int, Register>
	 */
	private array $registers = [];

	/**
	 * Every action, in order: "unlink:<register>:<id>" and "delete:<id>".
	 *
	 * @var array<int, string>
	 */
	private array $log = [];

	/**
	 * Messages written to the repair output.
	 *
	 * @var array<int, string>
	 */
	private array $messages = [];

	/**
	 * A schema double with an id.
	 *
	 * @param int $id The schema id.
	 *
	 * @return Schema
	 */
	private function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);

		return $schema;
	}//end schema()

	/**
	 * Seed one retired schema under an application.
	 *
	 * @param string $slug The slug.
	 * @param int $id The schema id.
	 * @param int $rows The rows it owns.
	 * @param string $application The owning application.
	 *
	 * @return void
	 */
	private function seed(string $slug, int $id, int $rows = 0, string $application = 'learniq'): void {
		$schema = $this->schema(id: $id);
		if (in_array($id, $this->archival, true) === true) {
			$schema->setConfiguration(['x-openregister-archival' => ['retention' => 'P7Y']]);
		}

		$this->schemas[$application . '/' . $slug][] = $schema;
		$this->objectCounts[$id] = $rows;
	}//end seed()

	/**
	 * A register double whose schema list is stored.
	 *
	 * @param string $slug The register slug.
	 * @param array<int, mixed> $schemas The stored schema list.
	 *
	 * @return void
	 */
	private function register(string $slug, array $schemas): void {
		$register = new Register();
		$register->setSlug($slug);
		$register->setSchemas($schemas);
		$this->registers[] = $register;
	}//end register()

	/**
	 * Build the step over the doubles.
	 *
	 * @param bool $openRegisterPresent Whether the container resolves OpenRegister's services.
	 *
	 * @return PruneRetiredSchemas
	 */
	private function makeStep(bool $openRegisterPresent = true): PruneRetiredSchemas {
		$schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$schemaMapper->method('findAllByApplicationAndSlug')->willReturnCallback(
			fn (string $slug, string $application): array => ($this->schemas[$application . '/' . $slug] ?? [])
		);

		$registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$registerMapper->method('findAll')->willReturnCallback(
			function (?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return ($_rbac === false && $_multitenancy === false) ? $this->registers : [];
			}
		);
		$registerMapper->method('update')->willReturnCallback(
			function (Entity $register): Entity {
				$this->log[] = 'unlink:' . $register->getSlug() . ':' . json_encode($register->getSchemas());

				return $register;
			}
		);

		$deletion = $this->createMock(originalClassName: SchemaDeletionService::class);
		$deletion->method('countObjectsCascadeWouldDelete')->willReturnCallback(
			fn (Schema $schema): int => ($this->objectCounts[$schema->getId()] ?? 0)
		);
		$deletion->method('cascadeDeleteSchema')->willReturnCallback(
			function (Schema $schema, bool $archivalOverride = false): array {
				if ($archivalOverride === true) {
					throw new RuntimeException('archival override must never be passed');
				}

				if (in_array($schema->getId(), $this->failingDeletes, true) === true) {
					throw new RuntimeException('table locked');
				}

				$this->log[] = 'delete:' . $schema->getId();

				return ['deletedCount' => ($this->objectCounts[$schema->getId()] ?? 0), 'tableDropped' => true];
			}
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($openRegisterPresent, $schemaMapper, $registerMapper, $deletion): object {
				if ($openRegisterPresent === false) {
					throw new RuntimeException('Could not resolve ' . $id);
				}

				return match ($id) {
					SchemaMapper::class => $schemaMapper,
					RegisterMapper::class => $registerMapper,
					SchemaDeletionService::class => $deletion,
				};
			}
		);

		$archive = $this->createMock(originalClassName: ArchiveRetiredPaymentObjects::class);
		$archive->method('isFullyArchived')->willReturnCallback(
			fn (string $schema, int $expectedRows): bool => in_array($schema, $this->archived, true)
		);
		return new PruneRetiredSchemas(
			container: $container,
			paymentArchive: $archive,
			logger: new NullLogger()
		);
	}//end makeStep()

	/**
	 * An IOutput double that records messages.
	 *
	 * @return IOutput
	 */
	private function repairOutput(): IOutput {
		$output = $this->createMock(originalClassName: IOutput::class);
		$record = function (string $message): void {
			$this->messages[] = $message;
		};
		$output->method('info')->willReturnCallback($record);
		$output->method('warning')->willReturnCallback($record);

		return $output;
	}//end repairOutput()

	/**
	 * An empty retired schema leaves every register that lists it, then goes,
	 * and the unrelated schema ids stay.
	 *
	 * @return void
	 */
	public function testAnEmptyRetiredSchemaIsUnlinkedThenDeleted(): void {
		$this->seed(slug: 'order-line', id: 41);
		$this->register(slug: 'learniq', schemas: [12, '41', 41, 'not-an-id', 77]);
		$this->register(slug: 'other', schemas: [5]);

		$this->makeStep()->run($this->repairOutput());

		self::assertSame(['unlink:learniq:[12,"not-an-id",77]', 'delete:41'], $this->log);
	}//end testAnEmptyRetiredSchemaIsUnlinkedThenDeleted()

	/**
	 * A payment schema whose rows are not all archived stays, and the output
	 * says so.
	 *
	 * @return void
	 */
	public function testAPaymentSchemaWithUnarchivedRowsStays(): void {
		$this->seed(slug: 'order', id: 40, rows: 3);
		$this->register(slug: 'learniq', schemas: [40]);

		$this->makeStep()->run($this->repairOutput());

		self::assertSame([], $this->log);
		self::assertStringContainsString('"order" still owns 3 row(s)', implode("\n", $this->messages));
	}//end testAPaymentSchemaWithUnarchivedRowsStays()

	/**
	 * A payment schema whose rows are all in the archive is pruned with them.
	 *
	 * @return void
	 */
	public function testAPaymentSchemaWithArchivedRowsIsPruned(): void {
		$this->seed(slug: 'order', id: 40, rows: 3);
		$this->archived = ['order'];
		$this->register(slug: 'learniq', schemas: [40]);

		$this->makeStep()->run($this->repairOutput());

		self::assertSame(['unlink:learniq:[]', 'delete:40'], $this->log);
	}//end testAPaymentSchemaWithArchivedRowsIsPruned()

	/**
	 * Privacy requests are AVG records: a schema that still owns any stays,
	 * and the output names the command a school runs after checking the
	 * copies. An empty one goes.
	 *
	 * @return void
	 */
	public function testPrivacyRequestsStayUntilTheSchoolDropsThem(): void {
		$this->seed(slug: 'data-subject-request', id: 50, rows: 2);

		$this->makeStep()->run($this->repairOutput());

		self::assertSame([], $this->log);
		self::assertStringContainsString(
			'occ openregister:schemas:prune-retired --app learniq --slug data-subject-request --apply --force',
			implode("\n", $this->messages)
		);
	}//end testPrivacyRequestsStayUntilTheSchoolDropsThem()

	/**
	 * An empty privacy request schema is pruned: nothing is deleted but the schema.
	 *
	 * @return void
	 */
	public function testAnEmptyPrivacyRequestSchemaGoes(): void {
		$this->seed(slug: 'data-subject-request', id: 50);

		$this->makeStep()->run($this->repairOutput());

		self::assertSame(['delete:50'], $this->log);
	}//end testAnEmptyPrivacyRequestSchemaGoes()

	/**
	 * A schema that declares archival retention is never pruned, not even empty.
	 *
	 * @return void
	 */
	public function testAnArchivalSchemaIsNeverPruned(): void {
		$this->archival = [42];
		$this->seed(slug: 'payment-transaction', id: 42);

		$this->makeStep()->run($this->repairOutput());

		self::assertSame([], $this->log);
		self::assertStringContainsString('archival retention', implode("\n", $this->messages));
	}//end testAnArchivalSchemaIsNeverPruned()

	/**
	 * Every row under the slug goes, under either application id, and a
	 * same-slug schema of another app is out of reach.
	 *
	 * @return void
	 */
	public function testEveryRowUnderBothApplicationIdsGoes(): void {
		$this->seed(slug: 'order', id: 40);
		$this->seed(slug: 'order', id: 43);
		$this->seed(slug: 'order', id: 30, application: 'scholiq');
		$this->seed(slug: 'order', id: 99, application: 'shillinq');

		$this->makeStep()->run($this->repairOutput());

		self::assertSame(['delete:40', 'delete:43', 'delete:30'], $this->log);
	}//end testEveryRowUnderBothApplicationIdsGoes()

	/**
	 * A second run finds nothing and changes nothing.
	 *
	 * @return void
	 */
	public function testNothingLeftIsANoOp(): void {
		$this->register(slug: 'learniq', schemas: [12, 77]);

		$this->makeStep()->run($this->repairOutput());

		self::assertSame([], $this->log);
	}//end testNothingLeftIsANoOp()

	/**
	 * A deletion that fails is reported, and the other slugs are still pruned.
	 *
	 * @return void
	 */
	public function testAFailedDeletionDoesNotStopTheOthers(): void {
		$this->seed(slug: 'order', id: 40);
		$this->seed(slug: 'order-line', id: 41);
		$this->failingDeletes = [40];

		$this->makeStep()->run($this->repairOutput());

		self::assertSame(['delete:41'], $this->log);
		self::assertStringContainsString('could not prune "order"', implode("\n", $this->messages));
	}//end testAFailedDeletionDoesNotStopTheOthers()

	/**
	 * Without OpenRegister's services the step reports and skips.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheStepSkips(): void {
		$this->seed(slug: 'order', id: 40);

		$this->makeStep(openRegisterPresent: false)->run($this->repairOutput());

		self::assertSame([], $this->log);
		self::assertStringContainsString('not available', implode("\n", $this->messages));
	}//end testWithoutOpenRegisterTheStepSkips()

	/**
	 * The unlink keeps every entry that is not this id, in order.
	 *
	 * @return void
	 */
	public function testUnlinkRemovesBothFormsOfTheId(): void {
		self::assertSame(
			[1, 'x', ['nested'], '7'],
			PruneRetiredSchemas::unlinkSchemaId(schemaRefs: [1, 41, 'x', '41', ['nested'], '7'], schemaId: 41)
		);
	}//end testUnlinkRemovesBothFormsOfTheId()

	/**
	 * The four retired slugs are the ones #1070 and #1082 dropped.
	 *
	 * @return void
	 */
	public function testTheRetiredSlugsAreTheFourDroppedToday(): void {
		self::assertSame(['order', 'order-line', 'payment-transaction'], PruneRetiredSchemas::PAYMENT_SLUGS);
		self::assertSame('data-subject-request', PruneRetiredSchemas::DSR_SLUG);

		$register = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/learniq_register.json'), true);
		$slugs = array_column($register['components']['schemas'], 'slug');
		foreach ([...PruneRetiredSchemas::PAYMENT_SLUGS, PruneRetiredSchemas::DSR_SLUG] as $slug) {
			self::assertNotContains($slug, $slugs, $slug . ' is still in the register');
		}
	}//end testTheRetiredSlugsAreTheFourDroppedToday()

	/**
	 * The step runs after both steps that read the rows, before the import.
	 *
	 * @return void
	 */
	public function testTheStepRunsAfterTheArchiveAndBeforeTheImport(): void {
		$info = (string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/info.xml');
		$start = (int)strpos($info, '<post-migration>');
		$postMigration = substr($info, $start, ((int)strpos($info, '</post-migration>') - $start));

		$prune = strpos($postMigration, 'Repair\\PruneRetiredSchemas');
		self::assertNotFalse($prune);
		self::assertLessThan($prune, strpos($postMigration, 'Repair\\ArchiveRetiredPaymentObjects'));
		self::assertLessThan($prune, strpos($postMigration, 'Repair\\MigrateDataSubjectRequestsToOpenRegister'));
		self::assertLessThan(strpos($postMigration, 'Repair\\InitializeSettings'), $prune);
	}//end testTheStepRunsAfterTheArchiveAndBeforeTheImport()
}//end class
