<?php

/**
 * Tests for MigrateDataExchangeToIntegriq: archive always, migrate once when
 * integriq is there, delete nothing.
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-existing-exchange-rows-are-moved-to-integriq-or-archived
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use DateTime;
use OCA\Learniq\Repair\LegacyExchangeTranslator;
use OCA\Learniq\Repair\MigrateDataExchangeToIntegriq;
use OCA\Learniq\Service\IntegriqExchangeClient;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The repair step over an in-memory register and app data folder.
 */
class MigrateDataExchangeToIntegriqTest extends TestCase {

	/**
	 * Rows per schema slug.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Saved objects.
	 *
	 * @var array<int, array{schema: string, object: array<string, mixed>, uuid: mixed}>
	 */
	private array $saved = [];

	/**
	 * Archive files: name => content.
	 *
	 * @var array<string, string>
	 */
	private array $files = [];

	/**
	 * App config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The integriq client double.
	 *
	 * @var IntegriqExchangeClient&MockObject
	 */
	private $integriq;

	/**
	 * Build the step.
	 *
	 * @param bool $integriqThere Whether integriq is installed.
	 *
	 * @return MigrateDataExchangeToIntegriq The step.
	 */
	private function step(bool $integriqThere): MigrateDataExchangeToIntegriq {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], ...$rest): array {
				$filters = $config['filters'] ?? [];
				$rows = $this->rows[(string)($filters['schema'] ?? '')] ?? [];
				if (isset($filters['dataExchangeJobId']) === true) {
					$rows = array_values(array_filter($rows, static fn (array $row): bool => ($row['dataExchangeJobId'] ?? null) === $filters['dataExchangeJobId']));
				}

				return OrEntityFactory::makeMany(array_slice($rows, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 200)), (string)($filters['schema'] ?? ''));
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null, $uuid = null): ObjectEntity {
				$data = ($object instanceof ObjectEntity) ? $object->jsonSerialize() : $object;
				$this->saved[] = ['schema' => (string)$schema, 'object' => $data, 'uuid' => $uuid];
				return OrEntityFactory::make($data, (string)$schema);
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
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willThrowException(new NotFoundException('data-exchange-archive'));
		$appData->method('newFolder')->willReturn($folder);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new DateTime('2026-09-28T09:00:00+00:00'));

		$this->integriq = $this->createMock(IntegriqExchangeClient::class);
		$this->integriq->method('isAvailable')->willReturn($integriqThere);

		return new MigrateDataExchangeToIntegriq($objects, $this->integriq, new LegacyExchangeTranslator(), $factory, $appConfig, $clock, new NullLogger());
	}//end step()

	/**
	 * Two jobs, one with a waived rejection, one waiting for a parent, a
	 * seeded and a customised mapping profile, and a flag naming a job.
	 *
	 * @return void
	 */
	private function givenRows(): void {
		$this->rows = [
			'data-exchange-job' => [
				['id' => 'old-1', 'target' => 'bron-rod', 'direction' => 'export', 'lifecycle' => 'succeeded', 'mappingProfileId' => 'dmp-1',
					'scope' => ['schema' => 'learner-profile', 'filters' => []], 'requestedBy' => 'admin', 'tenant_id' => 't1',
					'result' => ['recordsProcessed' => 2, 'recordsAccepted' => 1, 'recordsRejected' => 1], 'connectorRunId' => 'run-9'],
				['id' => 'old-2', 'target' => 'oso', 'direction' => 'export', 'lifecycle' => 'pending-parent-review', 'mappingProfileId' => null,
					'scope' => ['schema' => 'learner-profile', 'filters' => ['learnerId' => 'pupil-1']], 'requestedBy' => 'rollover', 'tenant_id' => 't1'],
			],
			'exchange-rejection' => [
				['id' => 'rej-1', 'dataExchangeJobId' => 'old-1', 'errorCode' => 'BRON-102', 'errorMessage' => 'Sanne Bakker has no birth date',
					'sourceKind' => 'learner-profile', 'learnerProfileId' => 'lp-2', 'status' => 'waived', 'waiveReason' => 'Left the school.', 'rawRecord' => ['bsn' => 'X']],
			],
			'data-mapping-profile' => [
				['id' => 'dmp-1', 'name' => 'BRON/ROD learner export', 'target' => 'bron-rod', 'direction' => 'export', 'fieldMappings' => []],
				['id' => 'dmp-2', 'name' => 'Our HR sync', 'target' => 'hr', 'direction' => 'export',
					'fieldMappings' => [['scholiqField' => 'familyName', 'targetField' => 'achternaam'], ['scholiqField' => 'bsnEncrypted', 'targetField' => 'bsn']]],
			],
			'exchange-error-code' => [['id' => 'code-1', 'code' => 'BRON-101']],
			'attendance-flag' => [['id' => 'flag-1', 'dataExchangeJobId' => 'old-2', 'lifecycle' => 'in-handling']],
		];
	}//end givenRows()

	/**
	 * An output double.
	 *
	 * @return IOutput The output.
	 */
	private function repairOutput(): IOutput {
		return $this->createMock(IOutput::class);
	}//end repairOutput()

	/**
	 * An install with integriq: every job and the custom mapping move, once.
	 *
	 * @return void
	 */
	public function testAnInstallWithIntegriq(): void {
		$this->givenRows();
		$step = $this->step(true);
		$requests = [];
		$this->integriq->method('requestJob')->willReturnCallback(
			static function (...$args) use (&$requests): string {
				$requests[] = $args;
				return 'new-' . count($requests);
			}
		);
		$this->integriq->expects($this->once())->method('requestMapping')->with(
			'learniq-custom-our-hr-sync',
			'learniq: Our HR sync',
			$this->anything(),
			['achternaam' => 'familyName']
		)->willReturn('map-1');

		$step->run($this->repairOutput());

		$this->assertCount(2, $requests);
		[$target, , $ownerRef, $scope, $mapping, $requestedBy, , $history] = $requests[0];
		$this->assertSame('bron-rod', $target);
		$this->assertSame('data-exchange-job/old-1', $ownerRef);
		$this->assertSame('t1', $scope['tenantId']);
		$this->assertSame('learniq-bron-rod-export-learner', $mapping);
		$this->assertSame('admin', $requestedBy);
		$this->assertSame('old-1', $history['legacyId']);
		$this->assertSame('run-9', $history['result']['runId']);
		$this->assertCount(1, $history['rejections']);
		$this->assertSame('lp-2', $history['rejections'][0]['recordId']);
		$this->assertSame('waived', $history['rejections'][0]['status']);
		$this->assertArrayNotHasKey('errorMessage', $history['rejections'][0], 'DUO\'s text quotes names; it stays behind.');
		$this->assertArrayNotHasKey('rawRecord', $history['rejections'][0]);

		$reviews = array_values(array_filter($this->saved, static fn (array $s): bool => $s['schema'] === 'dossier-review'));
		$this->assertSame([['exchangeJobId' => 'new-2', 'target' => 'oso', 'learnerUserId' => 'pupil-1', 'status' => 'pending', 'tenant_id' => 't1']], array_column($reviews, 'object'));

		$flags = array_values(array_filter($this->saved, static fn (array $s): bool => $s['schema'] === 'attendance-flag'));
		$this->assertSame('new-2', $flags[0]['object']['dataExchangeJobId'], 'A flag in flight names its integriq job.');

		$archive = json_decode($this->files['retired-data-exchange.json'], true);
		$this->assertSame(['data-exchange-job' => 2, 'data-mapping-profile' => 2, 'exchange-rejection' => 1, 'exchange-error-code' => 1], $archive['counts']);
		$this->assertNotSame('', $this->config['exchange_migrated_to_integriq'] ?? '');

		// A second run sends nothing.
		$again = count($requests);
		$step->run($this->repairOutput());
		$this->assertCount($again, $requests);
	}//end testAnInstallWithIntegriq()

	/**
	 * An install without integriq: everything archived, nothing dispatched, not marked.
	 *
	 * @return void
	 */
	public function testAnInstallWithoutIntegriq(): void {
		$this->givenRows();
		$step = $this->step(false);
		$this->integriq->expects($this->never())->method('requestJob');

		$step->run($this->repairOutput());

		$this->assertArrayHasKey('retired-data-exchange.json', $this->files);
		$this->assertSame([], $this->saved, 'Nothing is deleted or rewritten.');
		$this->assertArrayNotHasKey('exchange_migrated_to_integriq', $this->config, 'The move waits for integriq.');
	}//end testAnInstallWithoutIntegriq()

	/**
	 * A new install has nothing to move and is marked done.
	 *
	 * @return void
	 */
	public function testANewInstall(): void {
		$step = $this->step(true);
		$this->integriq->expects($this->never())->method('requestJob');

		$step->run($this->repairOutput());

		$this->assertSame([], $this->files);
		$this->assertArrayHasKey('exchange_migrated_to_integriq', $this->config);
	}//end testANewInstall()
}//end class
