<?php

/**
 * Tests for ExchangeGateService: the five conditions of learniq's exchange gate
 * and what may leave.
 *
 * @category Test
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
 *
 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-the-gate-enforces-partner-approval-teldatum-confirmation-and-flag-handling
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\DataExchangePayloadBuilder;
use OCA\Learniq\Service\DataExchangeTransformer;
use OCA\Learniq\Service\ExchangeDisclosure;
use OCA\Learniq\Service\ExchangeGateService;
use OCA\Learniq\Service\RodPersonalNumberResolver;
use OCA\Learniq\Service\RodSchoolAdviceComposer;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The gate over an in-memory store; the records come from the real builder.
 */
class ExchangeGateServiceTest extends TestCase {

	/**
	 * Rows by register/schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Every findAll config.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $reads = [];

	/**
	 * The gate under test.
	 *
	 * @var ExchangeGateService
	 */
	private ExchangeGateService $gate;

	/**
	 * Every log call of the gate and the resolver.
	 *
	 * @var CapturingLogger
	 */
	private CapturingLogger $logger;

	/**
	 * Build the gate.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], ...$rest): array {
				$this->reads[] = $config;
				$filters = $config['filters'] ?? [];
				$key = ($filters['register'] ?? '') . '/' . ($filters['schema'] ?? '');
				$out = [];
				foreach (($this->rows[$key] ?? []) as $row) {
					$keep = true;
					foreach ($filters as $field => $value) {
						if (in_array($field, ['register', 'schema', 'tenant_id'], true) === false && ($row[$field] ?? null) !== $value) {
							$keep = false;
						}
					}

					if ($keep === true) {
						$out[] = OrEntityFactory::make($row, (string)($filters['schema'] ?? ''));
					}
				}

				return $out;
			}
		);
		$objects->method('find')->willReturnCallback(
			function ($id, ?array $_extend = [], bool $files = false, $register = null, $schema = null, ...$rest): ?ObjectEntity {
				foreach (($this->rows[$register . '/' . $schema] ?? []) as $row) {
					if (($row['id'] ?? '') === $id) {
						return OrEntityFactory::make($row, (string)$schema);
					}
				}

				throw new \RuntimeException('not found');
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		$this->logger = new CapturingLogger();
		$disclosure = new ExchangeDisclosure();
		$resolver = new RodPersonalNumberResolver($objects, $this->logger);
		$builder = new DataExchangePayloadBuilder(
			$objects,
			new DataExchangeTransformer($objects),
			$disclosure,
			$resolver,
			new RodSchoolAdviceComposer($objects, $resolver)
		);
		$this->gate = new ExchangeGateService($objects, $builder, $disclosure, $l10n, $this->logger);
	}//end setUp()

	/**
	 * An integriq job row for the gate to read its mapping from.
	 *
	 * @param string      $jobId   The job's uuid.
	 * @param string|null $mapping The mapping slug.
	 *
	 * @return void
	 */
	private function job(string $jobId, ?string $mapping): void {
		$this->rows['integriq/job'][] = ['id' => $jobId, 'ownerApp' => 'learniq', 'exchangeMapping' => $mapping];
	}//end job()

	/**
	 * Two complete learner profiles.
	 *
	 * @return void
	 */
	private function learners(): void {
		$this->rows['learniq/learner-profile'] = [
			['id' => 'lp-1', 'ncUserId' => 'pupil-1', 'eckId' => 'eck-1', 'givenName' => 'Sanne', 'familyName' => 'Bakker', 'birthDate' => '2014-03-02', 'schoolId' => '00AA', 'bsnEncrypted' => 'SECRET', 'email' => 'x@y', 'personalNumber' => '111222333', 'personalNumberType' => 'bsn'],
			['id' => 'lp-2', 'ncUserId' => 'pupil-2', 'eckId' => 'eck-2', 'givenName' => 'Daan', 'familyName' => 'de Vries', 'birthDate' => '2014-05-20', 'schoolId' => '00AA', 'personalNumber' => '101234564', 'personalNumberType' => 'onderwijsnummer'],
		];
	}//end learners()

	/**
	 * A ROD export hands over the five fields plus the personal number, never the legacy BSN field or email.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-what-may-leave-is-decided-by-learniq-per-mapping
	 * @spec openspec/changes/rod-bsn-and-school-advice/specs/data-exchange/spec.md#scenario-a-rod-export-sends-the-bsn-and-keeps-the-eck-id
	 */
	public function testARodExportHandsOverFiveFields(): void {
		$this->learners();
		$this->job('job-1', 'learniq-bron-rod-export-learner');

		$decision = $this->gate->evaluate('job-1', 'bron-rod', 'export', 'user/admin', ['schema' => 'learner-profile']);

		$this->assertSame('allow', $decision['decision']);
		$this->assertCount(2, $decision['records']);
		$this->assertSame('lp-1', $decision['records'][0]['recordId']);
		$this->assertSame('learner-profile', $decision['records'][0]['sourceKind']);
		$this->assertSame(
			['eckId', 'givenName', 'familyName', 'birthDate', 'schoolId', 'persoonsgebondenNummer', 'persoonsgebondenNummerType'],
			array_keys($decision['records'][0]['data'])
		);
		$this->assertSame('eck-1', $decision['records'][0]['data']['eckId'], 'The ECK iD stays for publisher chains.');
		$this->assertSame('111222333', $decision['records'][0]['data']['persoonsgebondenNummer']);
		$this->assertSame('burgerservicenummer', $decision['records'][0]['data']['persoonsgebondenNummerType']);
		$this->assertSame('101234564', $decision['records'][1]['data']['persoonsgebondenNummer']);
		$this->assertSame('onderwijsnummer', $decision['records'][1]['data']['persoonsgebondenNummerType']);
		$this->assertStringNotContainsString('SECRET', (string)json_encode($decision['records']));
		$this->assertStringNotContainsString('111222333', $this->logger->dump(), 'The number is never logged.');
	}//end testARodExportHandsOverFiveFields()

	/**
	 * A statutory export without a mapping is refused before any record is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-what-may-leave-is-decided-by-learniq-per-mapping
	 */
	public function testAStatutoryExportWithoutAMapping(): void {
		$this->learners();
		$this->job('job-2', null);

		$decision = $this->gate->evaluate('job-2', 'bron-rod', 'export', 'school-advies/sa-1', ['schema' => 'learner-profile']);

		$this->assertSame('disclosure-undefined', $decision['code']);
		foreach ($this->reads as $read) {
			$this->assertNotSame('learner-profile', $read['filters']['schema'] ?? '', 'No pupil record may be read.');
		}
	}//end testAStatutoryExportWithoutAMapping()

	/**
	 * A record that misses its birth date refuses the job, naming field and record.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-what-may-leave-is-decided-by-learniq-per-mapping
	 */
	public function testARecordMissesItsBirthDate(): void {
		$this->learners();
		$this->rows['learniq/learner-profile'][1]['birthDate'] = null;
		$this->job('job-1', 'learniq-bron-rod-export-learner');

		$decision = $this->gate->evaluate('job-1', 'bron-rod', 'export', 'user/admin', ['schema' => 'learner-profile']);

		$this->assertSame('statutory-incomplete', $decision['code']);
		$this->assertStringContainsString('birthDate', $decision['reason']);
		$this->assertStringContainsString('learner-profile/lp-2', $decision['reason']);
		$this->assertStringNotContainsString('Daan', $decision['reason'], 'A refusal names no values.');
	}//end testARecordMissesItsBirthDate()

	/**
	 * An SWV file waits for a parent, and a rejected one stays refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-the-gate-refuses-an-oso-or-swv-file-until-a-parent-approved-it
	 */
	public function testAnSwvFileWaitsForAParent(): void {
		$this->assertSame('parent-review-pending', $this->gate->evaluate('job-3', 'swv', 'export', 'support-request/sr-1', [])['code']);

		$this->rows['learniq/dossier-review'] = [['id' => 'dr-1', 'exchangeJobId' => 'job-3', 'status' => 'rejected']];
		$this->assertSame('parent-review-rejected', $this->gate->evaluate('job-3', 'swv', 'export', 'support-request/sr-1', [])['code']);
	}//end testAnSwvFileWaitsForAParent()

	/**
	 * A partner link awaiting approval blocks its target; a target without one is free.
	 *
	 * @return void
	 */
	public function testAPartnerLinkAwaitsApproval(): void {
		$this->rows['learniq/dossier-review'] = [['id' => 'dr-1', 'exchangeJobId' => 'job-4', 'status' => 'approved']];
		$this->rows['learniq/exchange-partner-approval'] = [['id' => 'pa-1', 'target' => 'swv', 'status' => 'pending']];
		$this->job('job-4', 'learniq-swv-export-zorgvraag');

		$this->assertSame('partner-approval-missing', $this->gate->evaluate('job-4', 'swv', 'export', 'support-request/sr-1', [])['code']);

		$this->rows['learniq/exchange-partner-approval'][] = ['id' => 'pa-2', 'target' => 'swv', 'status' => 'approved'];
		$this->assertSame('allow', $this->gate->evaluate('job-4', 'swv', 'export', 'support-request/sr-1', ['schema' => 'support-request'])['decision']);
	}//end testAPartnerLinkAwaitsApproval()

	/**
	 * A job that names a teldatum waits for its confirmed count.
	 *
	 * @return void
	 */
	public function testTheTeldatumIsNotConfirmed(): void {
		$this->learners();
		$this->job('job-5', 'learniq-bron-rod-export-learner');
		$scope = ['schema' => 'learner-profile', 'teldatumDate' => '2026-10-01'];

		$this->assertSame('teldatum-unconfirmed', $this->gate->evaluate('job-5', 'bron-rod', 'export', 'user/admin', $scope)['code']);

		$this->rows['learniq/teldatum-check'] = [['id' => 'tc-1', 'teldatumDate' => '2026-10-01', 'target' => 'bron-rod', 'status' => 'confirmed']];
		$this->assertSame('allow', $this->gate->evaluate('job-5', 'bron-rod', 'export', 'user/admin', $scope)['decision']);
	}//end testTheTeldatumIsNotConfirmed()

	/**
	 * A leerplicht report waits until a person took up the flag.
	 *
	 * @return void
	 */
	public function testNobodyTookUpTheFlag(): void {
		$this->rows['learniq/attendance-flag'] = [['id' => 'flag-1', 'lifecycle' => 'open', 'learnerId' => 'pupil-1', 'windowStart' => '2026-09-01', 'windowEnd' => '2026-09-28', 'metricValue' => 17, 'breachingRecordIds' => [], 'interventions' => []]];
		$this->job('job-6', 'learniq-leerplicht-export-melding');
		$scope = ['schema' => 'attendance-flag', 'recordIds' => ['flag-1']];

		$this->assertSame('flag-not-in-handling', $this->gate->evaluate('job-6', 'leerplicht', 'export', 'attendance-flag/flag-1', $scope)['code']);

		$this->rows['learniq/attendance-flag'][0]['lifecycle'] = 'in-handling';
		$decision = $this->gate->evaluate('job-6', 'leerplicht', 'export', 'attendance-flag/flag-1', $scope);
		$this->assertSame('allow', $decision['decision']);
		$this->assertArrayHasKey('breachingRecords', $decision['records'][0]['data'], 'The leerplicht file is composed as before.');
		$this->assertArrayHasKey('interventions', $decision['records'][0]['data']);
	}//end testNobodyTookUpTheFlag()

	/**
	 * An import needs no records and passes once the other conditions do.
	 *
	 * @return void
	 */
	public function testAnImportPasses(): void {
		$this->assertSame('allow', $this->gate->evaluate('job-7', 'lvs-results', 'import', 'user/admin', [])['decision']);
	}//end testAnImportPasses()

	/**
	 * A profile without a valid number refuses the job, naming the field, never the value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rod-bsn-and-school-advice/specs/data-exchange/spec.md#scenario-a-profile-without-a-valid-number
	 */
	public function testAProfileWithoutAValidNumber(): void {
		$this->learners();
		$this->rows['learniq/learner-profile'][1]['personalNumber'] = '101234565';
		$this->job('job-1', 'learniq-bron-rod-export-learner');

		$decision = $this->gate->evaluate('job-1', 'bron-rod', 'export', 'user/admin', ['schema' => 'learner-profile']);

		$this->assertSame('statutory-incomplete', $decision['code']);
		$this->assertStringContainsString('persoonsgebondenNummer', $decision['reason']);
		$this->assertStringContainsString('learner-profile/lp-2', $decision['reason']);
		$this->assertStringNotContainsString('101234565', $decision['reason']);
		$this->assertSame([], $decision['records']);
	}//end testAProfileWithoutAValidNumber()

	/**
	 * A vestiging, a school and a learner for a school advice.
	 *
	 * @return void
	 */
	private function schoolAdvice(): void {
		$this->learners();
		$this->rows['learniq/school'] = [['id' => 'school-1', 'brin' => '02VG', 'onderwijsaanbiedercode' => '100A200']];
		$this->rows['learniq/vestiging'] = [['id' => 'ves-1', 'schoolId' => 'school-1', 'vestigingscode' => '02VG00', 'onderwijslocatiecode' => '300X400']];
		$this->rows['learniq/school-advies'] = [[
			'id' => '0a1b2c3d-4e5f-4061-8a7b-9c0d1e2f3a4b',
			'learnerId' => 'pupil-1',
			'academicYear' => '2025-2026',
			'vestigingId' => 'ves-1',
			'voorlopigAdviesLevel' => 'vmbo-kb',
			'voorlopigAdviesDate' => '2026-01-20',
			'doorstroomtoetsResultLevel' => 'havo',
			'doorstroomtoetsResultDate' => '2026-02-15',
			'definitiefAdviesLevel' => 'vmbo-gt',
			'definitiefAdviesDate' => '2026-03-20',
			'heroverwegingMotivation' => 'PRIVATE MOTIVATION',
			'lifecycle' => 'verzonden-naar-rod',
		]];
		$this->job('job-sa', 'learniq-bron-rod-export-schooladvies');
	}//end schoolAdvice()

	/**
	 * A definitief school advice goes with DUO's AanleverenAdviesVO field set and nothing else.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rod-bsn-and-school-advice/specs/data-exchange/spec.md#scenario-a-definitief-advice-is-sent
	 */
	public function testASchoolAdviceGoesWithDuosFieldSet(): void {
		$this->schoolAdvice();
		$scope = ['schema' => 'school-advies', 'recordIds' => ['0a1b2c3d-4e5f-4061-8a7b-9c0d1e2f3a4b'], 'berichtsoort' => 'schooladvies'];

		$decision = $this->gate->evaluate('job-sa', 'bron-rod', 'export', 'school-advies/0a1b2c3d-4e5f-4061-8a7b-9c0d1e2f3a4b', $scope);

		$this->assertSame('allow', $decision['decision'], $decision['reason']);
		$this->assertCount(1, $decision['records']);
		$this->assertSame(
			[
				'persoonsgebondenNummer' => '111222333',
				'persoonsgebondenNummerType' => 'burgerservicenummer',
				'adviesvolgnummer' => '0a1b2c3d4e5f40618a7b',
				'onderwijsaanbieder' => '100A200',
				'onderwijslocatie' => '300X400',
				'vestigingscode' => '02VG00',
				'adviesjaar' => '2026',
				'advies1' => 'VMBO_KB',
				'advies1Datum' => '2026-01-20',
				'advies2' => 'VMBO_GL/TL',
				'advies2Datum' => '2026-03-20',
			],
			$decision['records'][0]['data']
		);
		$this->assertStringNotContainsString('PRIVATE MOTIVATION', (string)json_encode($decision['records']));
		$this->assertStringNotContainsString('111222333', $this->logger->dump());
	}//end testASchoolAdviceGoesWithDuosFieldSet()

	/**
	 * Without a vestiging in a tenant with two, the job is refused naming vestigingscode.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rod-bsn-and-school-advice/specs/data-exchange/spec.md#scenario-the-advice-has-no-vestiging-and-the-tenant-has-several
	 */
	public function testAnAdviceWithoutAVestigingInATenantWithTwo(): void {
		$this->schoolAdvice();
		$this->rows['learniq/school-advies'][0]['vestigingId'] = null;
		$this->rows['learniq/vestiging'][] = ['id' => 'ves-2', 'schoolId' => 'school-1', 'vestigingscode' => '02VG01', 'onderwijslocatiecode' => '300X401'];

		$decision = $this->gate->evaluate('job-sa', 'bron-rod', 'export', 'school-advies/x', ['schema' => 'school-advies']);

		$this->assertSame('statutory-incomplete', $decision['code']);
		$this->assertStringContainsString('vestigingscode', $decision['reason']);

		array_pop($this->rows['learniq/vestiging']);
		$decision = $this->gate->evaluate('job-sa', 'bron-rod', 'export', 'school-advies/x', ['schema' => 'school-advies']);
		$this->assertSame('allow', $decision['decision'], 'A tenant with one vestiging uses it.');
		$this->assertSame('02VG00', $decision['records'][0]['data']['vestigingscode']);
	}//end testAnAdviceWithoutAVestigingInATenantWithTwo()
}//end class
