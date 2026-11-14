<?php

/**
 * Characterisation tests for LearningRecordExportService::generate().
 *
 * Pins the assembled bundle, the signed artifact handed to the writer, the
 * period narrowing of every dated collection and of the excluded staff
 * records, and every fail-closed exception message.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\LearningRecordAggregationService;
use OCA\Learniq\Service\LearningRecordBundleWriter;
use OCA\Learniq\Service\LearningRecordExportService;
use OCA\Learniq\Service\LearningRecordExportSigningService;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for LearningRecordExportService::generate().
 */
class LearningRecordExportServiceGenerateTest extends TestCase {

	/**
	 * Aggregation mock.
	 *
	 * @var LearningRecordAggregationService&MockObject
	 */
	private LearningRecordAggregationService&MockObject $aggregationService;

	/**
	 * Signing mock.
	 *
	 * @var LearningRecordExportSigningService&MockObject
	 */
	private LearningRecordExportSigningService&MockObject $signingService;

	/**
	 * Object service mock.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Bundle writer mock.
	 *
	 * @var LearningRecordBundleWriter&MockObject
	 */
	private LearningRecordBundleWriter&MockObject $bundleWriter;

	/**
	 * Service under test.
	 *
	 * @var LearningRecordExportService
	 */
	private LearningRecordExportService $service;

	/**
	 * Arguments the writer received.
	 *
	 * @var array<string,mixed>
	 */
	private array $written = [];

	/**
	 * Bundle handed to the signer.
	 *
	 * @var array<string,mixed>
	 */
	private array $signed = [];

	/**
	 * Build the service with recording mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->aggregationService = $this->createMock(LearningRecordAggregationService::class);
		$this->signingService = $this->createMock(LearningRecordExportSigningService::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->bundleWriter = $this->createMock(LearningRecordBundleWriter::class);

		$this->service = new LearningRecordExportService(
			aggregationService: $this->aggregationService,
			signingService: $this->signingService,
			objectService: $this->objectService,
			bundleWriter: $this->bundleWriter,
		);
	}//end setUp()

	/**
	 * Wire a signer that succeeds and records what it signs.
	 *
	 * @return void
	 */
	private function signerSucceeds(): void {
		$this->signingService->method('resolveIssuerDid')->willReturn('did:web:t1');
		$this->signingService->method('sign')->willReturnCallback(
			function (array $bundle, string $tenantId): string {
				$this->signed = $bundle;
				$this->signed['_tenant'] = $tenantId;
				return 'h..s';
			}
		);
	}//end signerSucceeds()

	/**
	 * Wire a writer that succeeds and records its arguments.
	 *
	 * @return void
	 */
	private function writerSucceeds(): void {
		$this->bundleWriter->method('write')->willReturnCallback(
			function (array $bundle, string $ownerUid, string $tenantId, string $exportId): string {
				$this->written = ['bundle' => $bundle, 'ownerUid' => $ownerUid, 'tenantId' => $tenantId, 'exportId' => $exportId];
				return '/Learniq/t1/learning-record-exports/' . $exportId . '.json';
			}
		);
	}//end writerSucceeds()

	/**
	 * A base export, overridden per test.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return array<string,mixed>
	 */
	private function export(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'export-1',
				'learnerId' => 'anna',
				'learnerRef' => 'ref-1',
				'requestedBy' => 'mentor',
				'tenant_id' => 't1',
				'periodFrom' => null,
				'periodTo' => null,
				'errorMessage' => 'old',
			],
			$overrides
		);
	}//end export()

	/**
	 * Outcomes per source id from a coverage report.
	 *
	 * @param array<int,array<string,mixed>> $coverage Coverage report.
	 *
	 * @return array<string,string>
	 */
	private function outcomes(array $coverage): array {
		$out = [];
		foreach ($coverage as $entry) {
			$out[$entry['sourceSchema'] . ':' . $entry['sourceId']] = $entry['outcome'];
		}

		return $out;
	}//end outcomes()

	/**
	 * The full happy path: bundle shape, signed artifact, writer arguments and returned export.
	 *
	 * @return void
	 */
	public function testGenerateAssemblesSignsAndStoresTheBundle(): void {
		$composition = [
			'credentials' => [
				['id' => 'c1', 'kind' => 'badge', 'edciPayload' => ['e' => 1], 'openbadges3Payload' => ['o' => 1]],
				['uuid' => 'c2', 'kind' => 'diploma', 'edciPayload' => [], 'openbadges3Payload' => ['o' => 2]],
				['id' => 'c3', 'kind' => 'none'],
			],
			'lessonCompletions' => [['courseId' => 'course-9', 'count' => 3]],
			'finalGrades' => [['id' => 'fg1']],
			'bpvPlacements' => [['id' => 'bp1', 'trainingCompanyName' => 'Bakker BV']],
		];
		$this->aggregationService->expects($this->once())->method('compose')->with('ref-1')->willReturn($composition);
		$this->objectService->method('findAll')->willReturn([]);
		$this->signerSucceeds();
		$this->writerSucceeds();

		$saved = $this->service->generate(export: $this->export());

		self::assertSame('/Learniq/t1/learning-record-exports/export-1.json', $saved['bundleRef']);
		self::assertSame('h..s', $saved['bundleSignature']);
		self::assertSame('did:web:t1', $saved['issuerDid']);
		self::assertNull($saved['errorMessage']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $saved['generatedAt']);

		self::assertSame(
			[
				[
					'sourceSchema' => 'credential',
					'sourceId' => 'c1',
					'sourceTitle' => 'Credential (badge)',
					'outcome' => 'included',
					'reason' => null,
				],
				[
					'sourceSchema' => 'credential',
					'sourceId' => 'c2',
					'sourceTitle' => 'Credential (diploma)',
					'outcome' => 'included',
					'reason' => null,
				],
				[
					'sourceSchema' => 'credential',
					'sourceId' => 'c3',
					'sourceTitle' => 'Credential (none)',
					'outcome' => 'included',
					'reason' => null,
				],
				[
					'sourceSchema' => 'lesson-completion',
					'sourceId' => 'course-9',
					'sourceTitle' => 'Lesson completions — course course-9',
					'outcome' => 'summarized',
					'reason' => 'Summarized per course (count + percentage) — the raw per-lesson log is never exported.',
				],
				[
					'sourceSchema' => 'final-grade',
					'sourceId' => 'fg1',
					'sourceTitle' => 'Final grade',
					'outcome' => 'included',
					'reason' => null,
				],
				[
					'sourceSchema' => 'bpv-placement',
					'sourceId' => 'bp1',
					'sourceTitle' => 'Bakker BV',
					'outcome' => 'included',
					'reason' => null,
				],
			],
			$saved['coverageReport']
		);

		$signed = $this->signed;
		self::assertSame('t1', $signed['_tenant']);
		unset($signed['_tenant']);
		self::assertSame(
			['bundleType', 'issuerDid', 'generatedAt', 'learnerRef', 'periodFrom', 'periodTo', 'elm', 'scholiqNative'],
			array_keys($signed)
		);
		self::assertSame('scholiq-learning-record', $signed['bundleType']);
		self::assertSame('ref-1', $signed['learnerRef']);
		self::assertSame($saved['generatedAt'], $signed['generatedAt']);
		self::assertSame(
			[
				['credentialId' => 'c1', 'kind' => 'badge', 'payload' => ['e' => 1]],
				['credentialId' => 'c2', 'kind' => 'diploma', 'payload' => ['o' => 2]],
			],
			$signed['elm']
		);
		self::assertSame($composition, $signed['scholiqNative']);

		$expectedArtifact = $signed;
		$expectedArtifact['proof'] = [
			'type' => 'DataIntegrityProof',
			'cryptosuite' => 'rsa-signature-2025',
			'created' => $saved['generatedAt'],
			'verificationMethod' => 'did:web:t1',
			'proofPurpose' => 'assertionMethod',
			'jws' => 'h..s',
		];
		self::assertSame($expectedArtifact, $this->written['bundle']);
		self::assertSame('anna', $this->written['ownerUid']);
		self::assertSame('t1', $this->written['tenantId']);
		self::assertSame('export-1', $this->written['exportId']);
	}//end testGenerateAssemblesSignsAndStoresTheBundle()

	/**
	 * Without a learner id the requester owns the file, and the uuid names it.
	 *
	 * @return void
	 */
	public function testRequesterOwnsTheFileWithoutLearnerId(): void {
		$this->aggregationService->method('compose')->willReturn([]);
		$this->objectService->expects($this->never())->method('findAll');
		$this->signerSucceeds();
		$this->writerSucceeds();

		$this->service->generate(export: $this->export(['learnerId' => '', 'id' => null, 'uuid' => 'u-7']));

		self::assertSame('mentor', $this->written['ownerUid']);
		self::assertSame('u-7', $this->written['exportId']);
	}//end testRequesterOwnsTheFileWithoutLearnerId()

	/**
	 * Without id or uuid a random 16-hex export id is used.
	 *
	 * @return void
	 */
	public function testRandomExportIdWithoutIdOrUuid(): void {
		$this->aggregationService->method('compose')->willReturn([]);
		$this->objectService->method('findAll')->willReturn([]);
		$this->signerSucceeds();
		$this->writerSucceeds();

		$export = $this->export();
		unset($export['id']);
		$this->service->generate(export: $export);

		self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $this->written['exportId']);
	}//end testRandomExportIdWithoutIdOrUuid()

	/**
	 * Period narrowing applies to dated schemas only, with permissive handling of missing or bad dates.
	 *
	 * @return void
	 */
	public function testPeriodNarrowsDatedSchemasOnly(): void {
		$this->aggregationService->method('compose')->willReturn(
			[
				'credentials' => [
					['id' => 'early', 'kind' => 'k', 'issuedAt' => '2025-12-31T23:59:59+00:00'],
					['id' => 'start', 'kind' => 'k', 'issuedAt' => '2026-01-01T00:00:00+00:00'],
					['id' => 'end', 'kind' => 'k', 'issuedAt' => '2026-06-30T00:00:00+00:00'],
					['id' => 'late', 'kind' => 'k', 'issuedAt' => '2026-06-30T00:00:01+00:00'],
					['id' => 'nodate', 'kind' => 'k'],
					['id' => 'baddate', 'kind' => 'k', 'issuedAt' => 'not a date'],
					['id' => 'intdate', 'kind' => 'k', 'issuedAt' => 20200101],
				],
				'externalTrainingRecords' => [['id' => 'x1', 'title' => 'EHBO', 'completedAt' => '2020-01-01']],
				'werkprocesAssessments' => [['id' => 'w1', 'assessedAt' => '2030-01-01']],
				'reportCards' => [['id' => 'r1', 'composedAt' => '2026-03-01']],
				'enrolments' => [['id' => 'e1', 'createdAt' => '2000-01-01']],
				'portfolios' => [['id' => 'p1']],
			]
		);
		$this->objectService->method('findAll')->willReturn([]);
		$this->signerSucceeds();
		$this->writerSucceeds();

		$saved = $this->service->generate(
			export: $this->export(['periodFrom' => '2026-01-01T00:00:00+00:00', 'periodTo' => '2026-06-30T00:00:00+00:00'])
		);

		self::assertSame(
			[
				'credential:early' => 'omitted',
				'credential:start' => 'included',
				'credential:end' => 'included',
				'credential:late' => 'omitted',
				'credential:nodate' => 'included',
				'credential:baddate' => 'included',
				'credential:intdate' => 'included',
				'external-training-record:x1' => 'omitted',
				'werkproces-assessment:w1' => 'omitted',
				'report-card:r1' => 'included',
				'enrolment:e1' => 'included',
				'portfolio:p1' => 'included',
			],
			$this->outcomes($saved['coverageReport'])
		);
		self::assertSame('Outside the requested export period.', $saved['coverageReport'][0]['reason']);
		self::assertSame(
			['start', 'end', 'nodate', 'baddate', 'intdate'],
			array_column($this->signed['scholiqNative']['credentials'], 'id')
		);
		self::assertSame([], $this->signed['scholiqNative']['externalTrainingRecords']);
		self::assertSame('2026-01-01T00:00:00+00:00', $this->signed['periodFrom']);
	}//end testPeriodNarrowsDatedSchemasOnly()

	/**
	 * A single bound, or an unparseable bound, narrows only on what it can.
	 *
	 * @return void
	 */
	public function testSingleAndUnparseableBounds(): void {
		$rows = [
			'credentials' => [
				['id' => 'old', 'kind' => 'k', 'issuedAt' => '2020-01-01'],
				['id' => 'new', 'kind' => 'k', 'issuedAt' => '2030-01-01'],
			],
		];
		$this->aggregationService->method('compose')->willReturn($rows);
		$this->objectService->method('findAll')->willReturn([]);
		$this->signerSucceeds();
		$this->writerSucceeds();

		$fromOnly = $this->service->generate(export: $this->export(['periodFrom' => '2025-01-01']));
		self::assertSame(['credential:old' => 'omitted', 'credential:new' => 'included'], $this->outcomes($fromOnly['coverageReport']));

		$toOnly = $this->service->generate(export: $this->export(['periodTo' => '2025-01-01']));
		self::assertSame(['credential:old' => 'included', 'credential:new' => 'omitted'], $this->outcomes($toOnly['coverageReport']));

		$badFrom = $this->service->generate(export: $this->export(['periodFrom' => 'garbage', 'periodTo' => '2025-01-01']));
		self::assertSame(['credential:old' => 'included', 'credential:new' => 'omitted'], $this->outcomes($badFrom['coverageReport']));

		$emptyBounds = $this->service->generate(export: $this->export(['periodFrom' => '', 'periodTo' => '']));
		self::assertSame(['credential:old' => 'included', 'credential:new' => 'included'], $this->outcomes($emptyBounds['coverageReport']));
	}//end testSingleAndUnparseableBounds()

	/**
	 * Staff records are queried per excluded schema and reported omitted when in the period.
	 *
	 * @return void
	 */
	public function testExcludedStaffRecordsAreReportedOmitted(): void {
		$this->aggregationService->method('compose')->willReturn([]);
		$serializable = new class implements \JsonSerializable {
			/**
			 * Serialised row.
			 *
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return ['uuid' => 'bi-1', 'occurredAt' => '2026-02-01'];
			}
		};
		$queries = [];
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use (&$queries, $serializable): array {
				$queries[] = $config;
				return match ($config['filters']['schema']) {
					'dossier-note' => [['id' => 'dn-in', 'date' => '2026-03-01'], ['id' => 'dn-out', 'date' => '2019-01-01']],
					'behaviour-incident' => [$serializable, 'scalar-row'],
					default => [],
				};
			}
		);
		$this->signerSucceeds();
		$this->writerSucceeds();

		$saved = $this->service->generate(export: $this->export(['periodFrom' => '2026-01-01', 'periodTo' => '2026-12-31']));

		self::assertSame(
			[
				['filters' => ['register' => 'learniq', 'schema' => 'dossier-note', 'learnerId' => 'anna']],
				['filters' => ['register' => 'learniq', 'schema' => 'behaviour-incident', 'learnerId' => 'anna']],
				['filters' => ['register' => 'learniq', 'schema' => 'wellbeing-check-in', 'learnerId' => 'anna']],
			],
			$queries
		);
		$reason = 'staff professional-judgment record, not learner-portable evidence';
		self::assertSame(
			[
				[
					'sourceSchema' => 'dossier-note',
					'sourceId' => 'dn-in',
					'sourceTitle' => 'Dossier note',
					'outcome' => 'omitted',
					'reason' => $reason,
				],
				[
					'sourceSchema' => 'behaviour-incident',
					'sourceId' => 'bi-1',
					'sourceTitle' => 'Behaviour incident',
					'outcome' => 'omitted',
					'reason' => $reason,
				],
				[
					'sourceSchema' => 'behaviour-incident',
					'sourceId' => '',
					'sourceTitle' => 'Behaviour incident',
					'outcome' => 'omitted',
					'reason' => $reason,
				],
			],
			$saved['coverageReport']
		);
	}//end testExcludedStaffRecordsAreReportedOmitted()

	/**
	 * A missing tenant throws before anything is composed.
	 *
	 * @return void
	 */
	public function testMissingTenantThrowsBeforeComposing(): void {
		$this->aggregationService->expects($this->never())->method('compose');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Missing learnerRef or tenant_id — cannot compose a record to export.');
		$this->service->generate(export: $this->export(['tenant_id' => '']));
	}//end testMissingTenantThrowsBeforeComposing()

	/**
	 * A missing key throws before anything is signed.
	 *
	 * @return void
	 */
	public function testMissingKeyThrowsBeforeSigning(): void {
		$this->aggregationService->method('compose')->willReturn([]);
		$this->objectService->method('findAll')->willReturn([]);
		$this->signingService->method('resolveIssuerDid')->willReturn(null);
		$this->signingService->expects($this->never())->method('sign');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No signing key configured for this tenant — an admin must generate one before an export can be signed.');
		$this->service->generate(export: $this->export());
	}//end testMissingKeyThrowsBeforeSigning()

	/**
	 * A failed signature throws before anything is written.
	 *
	 * @return void
	 */
	public function testFailedSignatureThrowsBeforeWriting(): void {
		$this->aggregationService->method('compose')->willReturn([]);
		$this->objectService->method('findAll')->willReturn([]);
		$this->signingService->method('resolveIssuerDid')->willReturn('did:web:t1');
		$this->signingService->method('sign')->willReturn(null);
		$this->bundleWriter->expects($this->never())->method('write');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Signing the export bundle failed.');
		$this->service->generate(export: $this->export());
	}//end testFailedSignatureThrowsBeforeWriting()

	/**
	 * A failed write throws.
	 *
	 * @return void
	 */
	public function testFailedWriteThrows(): void {
		$this->aggregationService->method('compose')->willReturn([]);
		$this->objectService->method('findAll')->willReturn([]);
		$this->signerSucceeds();
		$this->bundleWriter->method('write')->willReturn(null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not store the signed bundle.');
		$this->service->generate(export: $this->export());
	}//end testFailedWriteThrows()
}//end class
