<?php

/**
 * Tests for the MigrateDataSubjectRequestsToOpenRegister repair step (D20).
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
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-privacy-requests-live-in-openregisters-data-subject-request-register
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use OCA\Learniq\Repair\MigrateDataSubjectRequestsToOpenRegister;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for MigrateDataSubjectRequestsToOpenRegister::run().
 */
class MigrateDataSubjectRequestsToOpenRegisterTest extends TestCase {

	/**
	 * Rows per "register/schema".
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Every save: register, schema, object, rbac.
	 *
	 * @var array<int, array{register: string, schema: string, object: array<string, mixed>, rbac: bool}>
	 */
	private array $saves = [];

	/**
	 * When set, saves to OpenRegister's register throw.
	 *
	 * @var bool
	 */
	private bool $failSaves = false;

	/**
	 * When set, reads of the learniq schema throw (a fresh install).
	 *
	 * @var bool
	 */
	private bool $noSourceSchema = false;

	/**
	 * When set, reads return plain arrays plus one object that cannot be serialised.
	 *
	 * @var bool
	 */
	private bool $rawRows = false;

	/**
	 * Messages the step wrote.
	 *
	 * @var array<int, string>
	 */
	private array $messages = [];

	/**
	 * Build the step over an in-memory store keyed on filters.register/filters.schema.
	 *
	 * @return MigrateDataSubjectRequestsToOpenRegister
	 */
	private function makeStep(): MigrateDataSubjectRequestsToOpenRegister {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$key = ($config['filters']['register'] ?? '') . '/' . ($config['filters']['schema'] ?? '');
				if ($this->noSourceSchema === true && $key === 'learniq/data-subject-request') {
					throw new RuntimeException('Schema not found');
				}

				$rows = array_slice(($this->rows[$key] ?? []), (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 200));
				if ($this->rawRows === true) {
					return array_merge($rows, [new \stdClass()]);
				}

				return OrEntityFactory::makeMany($rows, (string)($config['filters']['schema'] ?? ''));
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true): ObjectEntity {
				if ($this->failSaves === true) {
					throw new RuntimeException('register missing');
				}

				$this->saves[] = ['register' => (string)$register, 'schema' => (string)$schema, 'object' => $object, 'rbac' => $_rbac];
				$this->rows[$register . '/' . $schema][] = array_merge($object, ['id' => 'case-' . count($this->saves)]);

				return OrEntityFactory::make($object, (string)$schema);
			}
		);

		return new MigrateDataSubjectRequestsToOpenRegister($objectService, new NullLogger());
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
	 * A learniq request row.
	 *
	 * @param string $id        Uuid.
	 * @param string $kind      correction or deletion.
	 * @param string $lifecycle Lifecycle state.
	 *
	 * @return array<string, mixed>
	 */
	private function request(string $id, string $kind = 'deletion', string $lifecycle = 'completed'): array {
		return [
			'id' => $id,
			'kind' => $kind,
			'learnerId' => 'leerling-001',
			'submittedBy' => 'privacy-officer-1',
			'description' => 'Ouder vraagt om verwijdering van het dossier.',
			'requestedAt' => '2026-03-01T09:00:00+00:00',
			'decidedAt' => '2026-03-20T09:00:00+00:00',
			'lifecycle' => $lifecycle,
			'auditTrail' => [
				['recordedBy' => 'privacy-officer-1', 'recordedAt' => '2026-03-02T10:00:00+00:00', 'action' => 'startReview'],
			],
			'tenant_id' => '00000000-0000-0000-0000-000000000000',
		];
	}//end request()

	/**
	 * A request becomes a case with the mapped type, status and dates.
	 *
	 * @return void
	 */
	public function testARequestBecomesAnOpenRegisterCase(): void {
		$this->rows['learniq/data-subject-request'] = [$this->request(id: 'dsr-1')];
		$this->makeStep()->run($this->repairOutput());

		self::assertCount(1, $this->saves);
		$save = $this->saves[0];
		self::assertSame('data-subject-requests', $save['register']);
		self::assertSame('dataSubjectRequest', $save['schema']);
		self::assertFalse($save['rbac']);

		$case = $save['object'];
		self::assertSame('erasure', $case['type']);
		self::assertSame('fulfilled', $case['status']);
		self::assertSame('leerling-001', $case['subjectId']);
		self::assertSame('nextcloud-user', $case['subjectType']);
		self::assertSame('2026-03-01T09:00:00+00:00', $case['receivedAt']);
		self::assertSame('2026-03-20T09:00:00+00:00', $case['closedAt']);
		self::assertSame('privacy-officer-1', $case['handler']);
		self::assertStringStartsWith(MigrateDataSubjectRequestsToOpenRegister::MARKER . "dsr-1\n", $case['notes']);
		self::assertStringContainsString('Ouder vraagt om verwijdering van het dossier.', $case['notes']);
		self::assertStringContainsString('startReview', $case['notes']);
	}//end testARequestBecomesAnOpenRegisterCase()

	/**
	 * Every lifecycle state and kind maps onto OpenRegister's vocabulary.
	 *
	 * @return void
	 */
	public function testKindsAndStatesMapOntoOpenRegistersVocabulary(): void {
		$map = [
			['correction', 'requested', 'rectification', 'received'],
			['correction', 'in-review', 'rectification', 'in-progress'],
			['deletion', 'rejected', 'erasure', 'refused'],
		];
		foreach ($map as [$kind, $lifecycle, $type, $status]) {
			$case = MigrateDataSubjectRequestsToOpenRegister::toCase(
				row: $this->request(id: 'x', kind: $kind, lifecycle: $lifecycle),
				sourceId: 'x'
			);
			self::assertSame($type, $case['type'], "$kind");
			self::assertSame($status, $case['status'], "$lifecycle");
		}

		// An open request carries no closedAt even when decidedAt was filled in.
		$open = MigrateDataSubjectRequestsToOpenRegister::toCase(row: $this->request(id: 'x', lifecycle: 'in-review'), sourceId: 'x');
		self::assertArrayNotHasKey('closedAt', $open);
	}//end testKindsAndStatesMapOntoOpenRegistersVocabulary()

	/**
	 * A second run moves nothing twice.
	 *
	 * @return void
	 */
	public function testASecondRunMovesNothingTwice(): void {
		$this->rows['learniq/data-subject-request'] = [$this->request(id: 'dsr-1'), $this->request(id: 'dsr-2')];
		$this->makeStep()->run($this->repairOutput());
		$this->makeStep()->run($this->repairOutput());

		self::assertCount(2, $this->saves);
		self::assertStringContainsString('0 moved, 2 already moved', end($this->messages));
	}//end testASecondRunMovesNothingTwice()

	/**
	 * A failed save is counted and retried on the next run.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsCountedAndRetriedNextRun(): void {
		$this->rows['learniq/data-subject-request'] = [$this->request(id: 'dsr-1')];
		$this->failSaves = true;
		$this->makeStep()->run($this->repairOutput());
		self::assertStringContainsString('0 moved, 0 already moved, 1 failed', end($this->messages));

		$this->failSaves = false;
		$this->makeStep()->run($this->repairOutput());
		self::assertCount(1, $this->saves);
	}//end testAFailedSaveIsCountedAndRetriedNextRun()

	/**
	 * A fresh install without the retired schema is a no-op.
	 *
	 * @return void
	 */
	public function testAFreshInstallIsANoOp(): void {
		$this->noSourceSchema = true;
		$this->makeStep()->run($this->repairOutput());

		self::assertSame([], $this->saves);
		self::assertStringContainsString('no learniq data subject requests to move', $this->messages[0]);
	}//end testAFreshInstallIsANoOp()

	/**
	 * The step runs before InitializeSettings in the upgrade path.
	 *
	 * @return void
	 */
	public function testTheStepRunsBeforeTheRegisterImport(): void {
		$info = (string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/info.xml');
		$postMigration = substr($info, (int)strpos($info, '<post-migration>'), (int)strpos($info, '</post-migration>') - (int)strpos($info, '<post-migration>'));
		$migrate = strpos($postMigration, 'Repair\\MigrateDataSubjectRequestsToOpenRegister');
		$import = strpos($postMigration, 'Repair\\InitializeSettings');

		self::assertNotFalse($migrate);
		self::assertNotFalse($import);
		self::assertLessThan($import, $migrate);
	}//end testTheStepRunsBeforeTheRegisterImport()

	/**
	 * The notes carry the marker, an unmapped kind, the trimmed description
	 * and one history line per entry, exactly; they stop at 4000 characters.
	 *
	 * @return void
	 */
	public function testTheNotesAreWrittenExactly(): void {
		$row = [
			'kind'        => 'objection',
			'description' => "  Bezwaar tegen verwerking.\n",
			'auditTrail'  => [
				['recordedAt' => '2026-03-02', 'recordedBy' => 'po-1', 'action' => 'startReview', 'note' => 'urgent'],
				'not-an-entry',
				['action' => 'close', 'note' => ''],
			],
		];
		self::assertSame(
			"learniq-migration: dsr-9\nOriginal kind: objection\n\nBezwaar tegen verwerking.\n\nHistory:\n- 2026-03-02 po-1: startReview (urgent)\n-  : close",
			MigrateDataSubjectRequestsToOpenRegister::toCase(row: $row, sourceId: 'dsr-9')['notes']
		);

		$bare = ['kind' => 'deletion', 'description' => '   ', 'auditTrail' => 'none'];
		self::assertSame('learniq-migration: dsr-9', MigrateDataSubjectRequestsToOpenRegister::toCase(row: $bare, sourceId: 'dsr-9')['notes']);

		$long = ['description' => str_repeat('a', 5000)];
		self::assertSame(4000, mb_strlen(MigrateDataSubjectRequestsToOpenRegister::toCase(row: $long, sourceId: 'dsr-9')['notes']));
	}//end testTheNotesAreWrittenExactly()

	/**
	 * Only a case whose notes start with the marker and name a source counts
	 * as migrated; every other case leaves its request to be moved.
	 *
	 * @return void
	 */
	public function testOnlyAMarkedCaseCountsAsMigrated(): void {
		$this->rows['learniq/data-subject-request'] = [
			$this->request(id: 'dsr-1'),
			$this->request(id: 'dsr-2'),
			$this->request(id: 'dsr-3'),
			$this->request(id: 'dsr-4'),
		];
		$this->rows['data-subject-requests/dataSubjectRequest'] = [
			['id' => 'c-1', 'notes' => MigrateDataSubjectRequestsToOpenRegister::MARKER . "dsr-1\nmore"],
			['id' => 'c-2', 'notes' => 'dsr-2'],
			['id' => 'c-3', 'notes' => MigrateDataSubjectRequestsToOpenRegister::MARKER . " dsr-3 \n"],
			['id' => 'c-4', 'notes' => MigrateDataSubjectRequestsToOpenRegister::MARKER . "   \ndsr-4"],
			['id' => 'c-5'],
		];
		$this->makeStep()->run($this->repairOutput());

		self::assertSame(['dsr-2', 'dsr-4'], array_map(static fn (array $save): string => substr(strtok($save['object']['notes'], "\n"), 19), $this->saves));
		self::assertSame('Learniq privacy requests: 2 moved, 2 already moved, 0 failed (of 4).', end($this->messages));
	}//end testOnlyAMarkedCaseCountsAsMigrated()

	/**
	 * Every page is read, plain array rows count, and an object that cannot
	 * be serialised is passed over.
	 *
	 * @return void
	 */
	public function testEveryPageAndEveryRowShapeIsRead(): void {
		$this->rows['learniq/data-subject-request'] = array_map(fn (int $i): array => $this->request(id: 'dsr-' . $i), range(1, 450));
		$this->makeStep()->run($this->repairOutput());
		self::assertSame('Learniq privacy requests: 450 moved, 0 already moved, 0 failed (of 450).', end($this->messages));

		$this->rawRows = true;
		$this->rows['learniq/data-subject-request'][] = $this->request(id: 'dsr-new');
		$this->makeStep()->run($this->repairOutput());
		self::assertSame('Learniq privacy requests: 1 moved, 450 already moved, 0 failed (of 451).', end($this->messages));
	}//end testEveryPageAndEveryRowShapeIsRead()

	/**
	 * An unreadable OpenRegister register stops the run before anything moves.
	 *
	 * @return void
	 */
	public function testAnUnreadableTargetMovesNothing(): void {
		$this->rows['learniq/data-subject-request'] = [$this->request(id: 'dsr-1')];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				if (($config['filters']['register'] ?? '') === 'data-subject-requests') {
					throw new RuntimeException('register missing');
				}

				return OrEntityFactory::makeMany($this->rows['learniq/data-subject-request'], 'data-subject-request');
			}
		);
		$objectService->expects(self::never())->method('saveObject');

		(new MigrateDataSubjectRequestsToOpenRegister($objectService, new NullLogger()))->run($this->repairOutput());

		self::assertSame('Learniq privacy requests: could not read the OpenRegister register, nothing moved (register missing).', end($this->messages));
	}//end testAnUnreadableTargetMovesNothing()
}//end class
