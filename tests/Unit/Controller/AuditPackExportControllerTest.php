<?php

/**
 * Learniq AuditPackExportController tests.
 *
 * Drives export() through the real AuditPackBuilder and its CSV builders, with
 * only OpenRegister's mapper, hash service, object service and HTTP client
 * doubled, then opens the ZIP it returns. Two tenants share the date range;
 * only the caller's tenant may appear in the pack.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/controller-test-coverage-security-critical/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use JsonSerializable;
use OCA\Learniq\Controller\AuditPackExportController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\AuditPackBuilder;
use OCA\Learniq\Service\CsvCellSanitizer;
use OCA\Learniq\Service\ExternalTrainingCsvBuilder;
use OCA\Learniq\Service\VerwerkingsregisterCsvBuilder;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\AuditHashService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Tests that the audit pack is tenant-scoped and complete.
 */
class AuditPackExportControllerTest extends TestCase {
	private const CALLER_TENANT = 'tenant-a';
	private const OTHER_TENANT = 'tenant-b';

	/**
	 * Audit-trail rows of both tenants, as OpenRegister stores them.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $auditRows = [];

	/**
	 * External-training records of both tenants.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $trainingRows = [];

	/**
	 * Every filter set the audit-trail mapper and the object service received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * Headers sent to OpenRegister's processing-log endpoint.
	 *
	 * @var array<string, string>
	 */
	private array $registerHeaders = [];

	/**
	 * Seed both tenants.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->auditRows = [
			$this->auditRow(id: 11, tenant: self::CALLER_TENANT, object: 'object-of-a'),
			$this->auditRow(id: 12, tenant: self::OTHER_TENANT, object: 'object-of-b'),
		];
		$this->trainingRows = [
			$this->trainingRow(learner: 'learner-of-a', tenant: self::CALLER_TENANT),
			$this->trainingRow(learner: 'learner-of-b', tenant: self::OTHER_TENANT),
		];
		$this->queries = [];
		$this->registerHeaders = [];
	}//end setUp()

	/**
	 * Every query is scoped to the caller's tenant and the pack holds only theirs.
	 *
	 * @return void
	 */
	public function testThePackHoldsOnlyTheCallersTenant(): void {
		$files = $this->exportAndUnzip();

		self::assertNotSame([], $this->queries);
		foreach ($this->queries as $filters) {
			self::assertSame(self::CALLER_TENANT, ($filters['tenant_id'] ?? null), 'A query ran without the caller\'s tenant.');
		}

		self::assertStringContainsString('object-of-a', $files['audit-trail.ndjson']);
		self::assertStringNotContainsString('object-of-b', $files['audit-trail.ndjson']);
		self::assertStringContainsString('object-of-a', $files['audit-trail.csv']);
		self::assertStringNotContainsString('object-of-b', $files['audit-trail.csv']);
		self::assertStringContainsString('learner-of-a', $files['external-training.csv']);
		self::assertStringNotContainsString('learner-of-b', $files['external-training.csv']);

		$manifest = json_decode($files['manifest.json'], true);
		self::assertSame(self::CALLER_TENANT, $manifest['tenant_id']);
		self::assertSame(1, $manifest['event_count']);

		// The processing log is scoped by OpenRegister's own RBAC: learniq
		// forwards the caller's session, never a service identity.
		self::assertSame('oc_session=caller-session', ($this->registerHeaders['Cookie'] ?? null));
	}//end testThePackHoldsOnlyTheCallersTenant()

	/**
	 * A period with no entries yields a complete pack of header-only CSVs.
	 *
	 * @return void
	 */
	public function testAnEmptyPeriodYieldsHeaderOnlyFiles(): void {
		$this->auditRows = [];
		$this->trainingRows = [];

		$files = $this->exportAndUnzip();

		self::assertSame(
			['audit-trail.csv', 'audit-trail.ndjson', 'external-training.csv', 'manifest.json', 'signature-verification.txt', 'verwerkingsregister.csv'],
			$this->sortedKeys(files: $files)
		);
		self::assertSame('', trim($files['audit-trail.ndjson']));
		self::assertCount(1, array_filter(explode("\n", trim($files['audit-trail.csv']))));
		self::assertStringStartsWith('learner_id,', $files['external-training.csv']);
		self::assertCount(1, array_filter(explode("\n", trim($files['external-training.csv']))));
		self::assertSame(0, json_decode($files['manifest.json'], true)['event_count']);
	}//end testAnEmptyPeriodYieldsHeaderOnlyFiles()

	/**
	 * The external-training CSV carries the record's fields end to end.
	 *
	 * @return void
	 */
	public function testExternalTrainingEvidenceReachesThePack(): void {
		$csv = $this->exportAndUnzip()['external-training.csv'];

		$lines = array_values(array_filter(explode("\n", trim($csv))));
		self::assertCount(2, $lines);
		$row = str_getcsv($lines[1]);
		self::assertSame('learner-of-a', $row[0]);
		self::assertSame('BHV herhaling', $row[1]);
		self::assertSame('nis2', $row[4]);
	}//end testExternalTrainingEvidenceReachesThePack()

	/**
	 * Call export() for the caller and return the ZIP's files by name.
	 *
	 * @return array<string, string>
	 */
	private function exportAndUnzip(): array {
		$response = $this->controller()->export(regulationSlug: 'nis2', dateFrom: '2026-01-01', dateTo: '2026-12-31');

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$path = tempnam(sys_get_temp_dir(), 'auditpack');
		self::assertNotFalse($path);
		file_put_contents($path, $response->render());

		$zip = new ZipArchive();
		self::assertTrue($zip->open($path));
		$files = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			$files[$name] = (string)$zip->getFromIndex($i);
		}

		$zip->close();
		unlink($path);

		return $files;
	}//end exportAndUnzip()

	/**
	 * File names of an unzipped pack, sorted.
	 *
	 * @param array<string, string> $files Unzipped files.
	 *
	 * @return array<int, string>
	 */
	private function sortedKeys(array $files): array {
		$keys = array_keys($files);
		sort($keys);

		return $keys;
	}//end sortedKeys()

	/**
	 * Keep the rows whose fields match every filter, as OpenRegister does.
	 *
	 * @param array<int, array<string, mixed>> $rows Stored rows.
	 * @param array<string, mixed> $filters Equality filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function matching(array $rows, array $filters): array {
		return array_values(
			array_filter(
				$rows,
				static function (array $row) use ($filters): bool {
					foreach ($filters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}//end matching()

	/**
	 * One audit-trail row carrying the regulation in its `changed` payload.
	 *
	 * @param int $id Audit entry id.
	 * @param string $tenant Tenant the entry belongs to.
	 * @param string $object Object uuid the entry is about.
	 *
	 * @return array<string, mixed>
	 */
	private function auditRow(int $id, string $tenant, string $object): array {
		return [
			'id' => $id,
			'action' => 'update',
			'object' => $object,
			'register' => 'learniq',
			'schema' => 'attestation',
			'user' => 'officer',
			'created' => '2026-03-01T10:00:00+00:00',
			'changed' => json_encode(['regulationSlug' => 'nis2']),
			'tenant_id' => $tenant,
		];
	}//end auditRow()

	/**
	 * One verified external-training record.
	 *
	 * @param string $learner Learner id.
	 * @param string $tenant Tenant the record belongs to.
	 *
	 * @return array<string, mixed>
	 */
	private function trainingRow(string $learner, string $tenant): array {
		return [
			'learnerId' => $learner,
			'title' => 'BHV herhaling',
			'provider' => 'Veiligheidsinstituut',
			'kind' => 'course',
			'regulationSlug' => 'nis2',
			'completedAt' => '2026-04-01T09:00:00+00:00',
			'lifecycle' => 'verified',
			'register' => 'learniq',
			'schema' => 'external-training-record',
			'tenant_id' => $tenant,
		];
	}//end trainingRow()

	/**
	 * Build the controller over the real pack builder, for a caller in CALLER_TENANT.
	 *
	 * @return AuditPackExportController
	 */
	private function controller(): AuditPackExportController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer-a');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $userId, string $appName, string $key, mixed $default = ''): mixed => ($key === 'tenant_id' ? self::CALLER_TENANT : $default)
		);
		$config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, mixed $default = ''): mixed => ($key === 'instanceid' ? 'instance-x' : $default)
		);

		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('findAll')->willReturnCallback(
			function (?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $sort = [], ?string $search = null): array {
				// OpenRegister's real signature: filters is the third argument.
				$filters = ($filters ?? []);
				$this->queries[] = $filters;
				$equality = $filters;
				unset($equality['created']);

				return array_map(
					static fn (array $row): JsonSerializable => new class($row) implements JsonSerializable {
						/**
						 * Constructor.
						 *
						 * @param array<string, mixed> $row Serialised audit entry.
						 */
						public function __construct(private readonly array $row) {
						}

						/**
						 * The serialised entry.
						 *
						 * @return array<string, mixed>
						 */
						public function jsonSerialize(): array {
							return $this->row;
						}
					},
					self::matching(rows: $this->auditRows, filters: $equality)
				);
			}
		);

		$hashService = $this->createMock(AuditHashService::class);
		$hashService->method('verifyChain')->willReturn(['valid' => true, 'keyFingerprint' => 'fp-1']);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $query): array {
				$this->queries[] = $query['filters'];
				return self::matching(rows: $this->trainingRows, filters: $query['filters']);
			}
		);

		$sanitizer = new CsvCellSanitizer();

		return new AuditPackExportController(
			request: $this->createMock(IRequest::class),
			userSession: $userSession,
			actionAuth: $this->createMock(ActionAuthService::class),
			packBuilder: new AuditPackBuilder(
				$mapper,
				$hashService,
				$config,
				$sanitizer,
				$this->registerCsv(sanitizer: $sanitizer),
				new ExternalTrainingCsvBuilder($objectService, $sanitizer),
			),
		);
	}//end controller()

	/**
	 * The real verwerkingsregister builder over a recording HTTP client.
	 *
	 * @param CsvCellSanitizer $sanitizer CSV sanitizer.
	 *
	 * @return VerwerkingsregisterCsvBuilder
	 */
	private function registerCsv(CsvCellSanitizer $sanitizer): VerwerkingsregisterCsvBuilder {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'Cookie' ? 'oc_session=caller-session' : '')
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturn('/apps/openregister/api/processing-log');
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://example.test' . $url);

		$httpResponse = $this->createMock(IResponse::class);
		$httpResponse->method('getBody')->willReturn(json_encode(['results' => []]));

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $uri, array $options) use ($httpResponse): IResponse {
				$this->registerHeaders = $options['headers'];
				return $httpResponse;
			}
		);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return new VerwerkingsregisterCsvBuilder($request, $clientService, $urls, $sanitizer);
	}//end registerCsv()
}//end class
