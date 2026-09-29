<?php

/**
 * Learniq AuditPackExportController tests.
 *
 * Drives export() through the real AuditPackBuilder and its CSV builders, with
 * only OpenRegister's mapper, hash service, object service and HTTP client
 * doubled, then opens the ZIP it returns. Two tenants share the date range;
 * only the caller's tenant may appear in the pack.
 *
 * The mapper double behaves like OpenRegister's AuditTrailMapper: it DROPS
 * every filter outside the real column allowlist (the trail has no tenant
 * column), and its rows have the real AuditTrail::jsonSerialize() shape. The
 * earlier double applied a `tenant_id` filter the real mapper drops, so it
 * passed while every tenant's entries reached the pack in production.
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
 * @spec openspec/changes/archive/2026-09-29-controller-test-coverage-security-critical/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use JsonSerializable;
use OCA\Learniq\Controller\AuditPackExportController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\AuditEntryAttribution;
use OCA\Learniq\Service\AuditPackBuilder;
use OCA\Learniq\Service\CallerTenantResolver;
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
use RuntimeException;
use ZipArchive;

/**
 * Tests that the audit pack is tenant-scoped and complete.
 */
class AuditPackExportControllerTest extends TestCase {
	private const CALLER_TENANT = 'tenant-a';
	private const OTHER_TENANT = 'tenant-b';

	/**
	 * The learniq schema id the audit rows point at.
	 */
	private const ATTESTATION_SCHEMA = 12;

	/**
	 * A schema id that does not resolve inside the learniq register.
	 */
	private const FOREIGN_SCHEMA = 99;

	/**
	 * The columns OpenRegister's AuditTrailMapper::findAll() filters on; any
	 * other filter is silently dropped (AuditTrailMapper.php, findAll()).
	 */
	private const MAPPER_FILTER_COLUMNS = [
		'id', 'uuid', 'schema', 'register', 'object', 'object_uuid', 'action', 'changed', 'user', 'user_name',
		'session', 'request', 'ip_address', 'version', 'created', 'flow_run', 'flow_node', 'flow_step', 'cause', 'cause_run',
	];

	/**
	 * The learniq objects the audit rows are about, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $objects = [];

	/**
	 * Every object load the attribution made: [filters, ids, rbac, multitenancy].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: array<int, string>, 2: bool, 3: bool}>
	 */
	private array $objectLoads = [];

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
	 * The pack builder of the last controller() call.
	 *
	 * @var AuditPackBuilder
	 */
	private AuditPackBuilder $builder;

	/**
	 * The mapper double of the last controller() call.
	 *
	 * @var AuditTrailMapper
	 */
	private AuditTrailMapper $mapper;

	/**
	 * Seed both tenants.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objects = [
			'object-of-a' => $this->object(uuid: 'object-of-a', tenant: self::CALLER_TENANT),
			'object-of-b' => $this->object(uuid: 'object-of-b', tenant: self::OTHER_TENANT),
		];
		$this->auditRows = [
			$this->auditRow(id: 11, objectUuid: 'object-of-a'),
			$this->auditRow(id: 12, objectUuid: 'object-of-b'),
		];
		$this->objectLoads = [];
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

		// The external-training query is filtered on the record's own tenant.
		// The audit trail cannot be: it has no tenant column, so the entries
		// are attributed through their objects, loaded without RBAC.
		self::assertNotSame([], $this->queries);
		foreach ($this->queries as $filters) {
			self::assertSame(self::CALLER_TENANT, ($filters['tenant_id'] ?? null), 'A training query ran without the caller\'s tenant.');
		}

		self::assertNotSame([], $this->objectLoads);
		foreach ($this->objectLoads as [$filters, $ids, $rbac, $multitenancy]) {
			self::assertSame('learniq', $filters['register']);
			self::assertFalse($rbac);
			self::assertFalse($multitenancy);
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
		self::assertSame(0, $manifest['unattributed_entries_excluded']);

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
	 * Without a regulation (the builder allows it; the controller requires
	 * one) every entry of the period is a candidate, and still only the
	 * caller's tenant reaches the pack. The old builder put the other tenant's
	 * entry in here, because the mapper dropped its tenant_id filter; through
	 * the controller that leak was masked by a second defect, a regulation
	 * match on `changed` that never matched OpenRegister's `{old, new}` shape.
	 *
	 * @return void
	 */
	public function testWithoutARegulationThePackHoldsOnlyTheCallersEntries(): void {
		$this->controller();
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer-a');
		$files = $this->unzip(bytes: $this->builder->build(user: $user, regulationSlug: '', dateFrom: '2026-01-01', dateTo: '2026-12-31'));

		self::assertStringContainsString('object-of-a', $files['audit-trail.ndjson']);
		self::assertStringNotContainsString('object-of-b', $files['audit-trail.ndjson']);
		self::assertStringNotContainsString('object-of-b', $files['audit-trail.csv']);
		self::assertSame(1, json_decode($files['manifest.json'], true)['event_count']);
	}//end testWithoutARegulationThePackHoldsOnlyTheCallersEntries()

	/**
	 * An entry whose object no longer exists cannot be attributed to a tenant:
	 * it is left out and counted in the manifest.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/compliance-audit/spec.md#scenario-unattributable-audit-trail-entries-are-excluded-and-counted
	 */
	public function testAnEntryForADeletedObjectIsLeftOutAndCounted(): void {
		$this->auditRows[] = $this->auditRow(id: 13, objectUuid: 'deleted-object');

		$files    = $this->exportAndUnzip();
		$manifest = json_decode($files['manifest.json'], true);

		self::assertStringNotContainsString('deleted-object', $files['audit-trail.ndjson']);
		self::assertSame(1, $manifest['event_count']);
		self::assertSame(1, $manifest['unattributed_entries_excluded']);
	}//end testAnEntryForADeletedObjectIsLeftOutAndCounted()

	/**
	 * Entries about objects outside the learniq register, or without a
	 * tenant, or without an object at all, are left out and counted.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/compliance-audit/spec.md#scenario-unattributable-audit-trail-entries-are-excluded-and-counted
	 */
	public function testUnattributableEntriesAreLeftOut(): void {
		$this->objects['other-app-object'] = $this->object(uuid: 'other-app-object', tenant: self::CALLER_TENANT, schema: self::FOREIGN_SCHEMA);
		$this->objects['untenanted']       = $this->object(uuid: 'untenanted', tenant: '');
		$this->auditRows[] = $this->auditRow(id: 13, objectUuid: 'other-app-object', schema: self::FOREIGN_SCHEMA);
		$this->auditRows[] = $this->auditRow(id: 14, objectUuid: 'untenanted');
		$this->auditRows[] = $this->auditRow(id: 15, objectUuid: '');

		$files    = $this->exportAndUnzip();
		$manifest = json_decode($files['manifest.json'], true);

		self::assertStringNotContainsString('other-app-object', $files['audit-trail.ndjson']);
		self::assertStringNotContainsString('untenanted', $files['audit-trail.ndjson']);
		self::assertSame(1, $manifest['event_count']);
		self::assertSame(3, $manifest['unattributed_entries_excluded']);
	}//end testUnattributableEntriesAreLeftOut()

	/**
	 * The regulation is read from the object, else from the change the entry
	 * records (OpenRegister stores `changed` as `{field: {old, new}}`).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/compliance-audit/spec.md#scenario-the-regulation-comes-from-the-object-or-the-change
	 */
	public function testTheRegulationComesFromTheObjectOrTheChange(): void {
		$this->objects['object-of-a']['regulationSlug'] = '';
		$this->objects['avg-object'] = $this->object(uuid: 'avg-object', tenant: self::CALLER_TENANT, regulation: 'avg');
		$this->auditRows[0]['changed'] = ['regulationSlug' => ['old' => null, 'new' => 'nis2']];
		$this->auditRows[] = $this->auditRow(id: 13, objectUuid: 'avg-object');

		$files = $this->exportAndUnzip();

		self::assertStringContainsString('object-of-a', $files['audit-trail.ndjson']);
		self::assertStringNotContainsString('avg-object', $files['audit-trail.ndjson']);
		self::assertSame(1, json_decode($files['manifest.json'], true)['event_count']);
	}//end testTheRegulationComesFromTheObjectOrTheChange()

	/**
	 * Control: the mapper double really drops a tenant_id filter, as the real
	 * mapper does, so the tests above cannot pass on a filter the mapper ignores.
	 *
	 * @return void
	 */
	public function testTheMapperDoubleDropsATenantFilter(): void {
		$this->controller();
		$rows = $this->mapper->findAll(filters: ['tenant_id' => self::CALLER_TENANT]);

		self::assertCount(2, $rows);
	}//end testTheMapperDoubleDropsATenantFilter()

	/**
	 * Call export() for the caller and return the ZIP's files by name.
	 *
	 * @return array<string, string>
	 */
	private function exportAndUnzip(): array {
		$response = $this->controller()->export(regulationSlug: 'nis2', dateFrom: '2026-01-01', dateTo: '2026-12-31');

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		return $this->unzip(bytes: $response->render());
	}//end exportAndUnzip()

	/**
	 * The files of a ZIP, by name.
	 *
	 * @param string $bytes The ZIP bytes.
	 *
	 * @return array<string, string>
	 */
	private function unzip(string $bytes): array {
		$path = tempnam(sys_get_temp_dir(), 'auditpack');
		self::assertNotFalse($path);
		file_put_contents($path, $bytes);

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
	}//end unzip()

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
	 * One audit-trail row in the shape AuditTrail::jsonSerialize() returns:
	 * numeric register, schema and object ids, the object uuid, `changed` as
	 * `{field: {old, new}}`, and no tenant.
	 *
	 * @param int $id Audit entry id.
	 * @param string $objectUuid Object uuid the entry is about.
	 * @param int $schema Schema id the object lives in.
	 *
	 * @return array<string, mixed>
	 */
	private function auditRow(int $id, string $objectUuid, int $schema = self::ATTESTATION_SCHEMA): array {
		return [
			'id' => $id,
			'uuid' => 'audit-' . $id,
			'schema' => $schema,
			'register' => 3,
			'object' => 1000 + $id,
			'objectUuid' => $objectUuid,
			'action' => 'update',
			'changed' => ['status' => ['old' => 'draft', 'new' => 'signed']],
			'user' => 'officer',
			'created' => '2026-03-01T10:00:00+00:00',
		];
	}//end auditRow()

	/**
	 * One learniq object an audit row can point at.
	 *
	 * @param string $uuid The object uuid.
	 * @param string $tenant Its tenant_id, or '' for none.
	 * @param int $schema The schema id it lives in.
	 * @param string $regulation Its regulationSlug.
	 *
	 * @return array<string, mixed>
	 */
	private function object(string $uuid, string $tenant, int $schema = self::ATTESTATION_SCHEMA, string $regulation = 'nis2'): array {
		return ['id' => $uuid, 'tenant_id' => $tenant, 'regulationSlug' => $regulation, '_schema' => $schema];
	}//end object()

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
				// OpenRegister's real signature: filters is the third argument,
				// and a filter outside the column allowlist is silently dropped.
				$filters  = array_intersect_key(($filters ?? []), array_flip(self::MAPPER_FILTER_COLUMNS));
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
			function (array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				if (isset($query['ids']) === true) {
					return $this->loadObjects(query: $query, rbac: $_rbac, multitenancy: $_multitenancy);
				}

				$this->queries[] = $query['filters'];
				return self::matching(rows: $this->trainingRows, filters: $query['filters']);
			}
		);
		$this->mapper = $mapper;

		$sanitizer = new CsvCellSanitizer();

		return new AuditPackExportController(
			request: $this->createMock(IRequest::class),
			userSession: $userSession,
			actionAuth: $this->createMock(ActionAuthService::class),
			packBuilder: $this->builder = new AuditPackBuilder(
				$mapper,
				$hashService,
				new CallerTenantResolver($config, $this->createMock(ObjectService::class)),
				$sanitizer,
				$this->registerCsv(sanitizer: $sanitizer),
				new ExternalTrainingCsvBuilder($objectService, $sanitizer),
				new AuditEntryAttribution($objectService),
			),
		);
	}//end controller()

	/**
	 * Objects by uuid within one schema of the learniq register, as
	 * OpenRegister answers an `ids` query. A schema that does not resolve in
	 * the learniq register throws, as setSchema() does.
	 *
	 * @param array<string, mixed> $query The findAll config.
	 * @param bool $rbac Whether RBAC was on.
	 * @param bool $multitenancy Whether multitenancy was on.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadObjects(array $query, bool $rbac, bool $multitenancy): array {
		$this->objectLoads[] = [$query['filters'], $query['ids'], $rbac, $multitenancy];
		$schema = (int)$query['filters']['schema'];
		if ($query['filters']['register'] !== 'learniq' || $schema === self::FOREIGN_SCHEMA) {
			throw new RuntimeException('Schema not found in register');
		}

		$rows = [];
		foreach ($query['ids'] as $uuid) {
			$object = ($this->objects[$uuid] ?? null);
			if ($object !== null && $object['_schema'] === $schema) {
				$rows[] = $object;
			}
		}

		return $rows;
	}//end loadObjects()

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
