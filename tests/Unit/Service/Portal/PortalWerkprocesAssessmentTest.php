<?php

/**
 * Tests for PortalWerkprocesAssessment.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Portal
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

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use OCA\Learniq\Service\Portal\PortalWerkprocesAssessment;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * An invited trainer may assess, the evidence says who and how sure, and a
 * school can still demand more.
 */
class PortalWerkprocesAssessmentTest extends TestCase {
	use RegisterSchemaPayloads;

	private const TRAINER = 'ee010027-0000-4000-8000-000000000001';

	private const PLACEMENT = 'ee010021-0000-4000-8000-000000000001';

	private const TENANT = '00000000-0000-4000-8000-000000000000';

	/**
	 * The fake OpenRegister store.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * Build the service over the fake store.
	 *
	 * @param string $floor       The school's assurance floor.
	 * @param bool   $otherOwner  Whether the placement belongs to someone else.
	 * @param bool   $failWrites  Whether saving throws.
	 *
	 * @return PortalWerkprocesAssessment
	 */
	private function service(string $floor = 'basic', bool $otherOwner = false, bool $failWrites = false): PortalWerkprocesAssessment {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'praktijkopleider' => [
				[
					'id' => self::TRAINER,
					'givenName' => 'Karin',
					'familyName' => 'Smit',
					'trainingCompanyName' => 'Installatiebedrijf Van Dam',
					'trainingCompanyKvkNumber' => '12345678',
					'tenant_id' => self::TENANT,
				],
			],
			'bpv-placement' => [
				[
					'id' => self::PLACEMENT,
					'practicalTrainerId' => ($otherOwner === true ? 'someone-else' : self::TRAINER),
				],
			],
		];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) use ($failWrites) {
				if ($failWrites === true) {
					throw new RuntimeException('SQLSTATE secret table name');
				}

				return $this->store->save((string)$schema, $object, $uuid ?? 'assessment-1');
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => ($key === PortalWerkprocesAssessment::MIN_ASSURANCE_KEY ? $floor : $default)
		);

		return new PortalWerkprocesAssessment(objectService: $objectService, appConfig: $appConfig, logger: new NullLogger());
	}//end service()

	/**
	 * What the trainer's form sends.
	 *
	 * @return array<string, mixed>
	 */
	private static function form(): array {
		return [
			'bpvPlacementId' => self::PLACEMENT,
			'curriculumPlanId' => 'ee010005-0000-4000-8000-000000000001',
			'componentId' => 'component-1',
			'kwalificatiedossierCode' => '25331',
			'coreTaskCode' => 'B1-K1',
			'werkprocesCode' => 'B1-K1-W2',
			'werkprocesLabel' => 'Sluit een meterkast aan',
			'assessment' => 'competent',
			'notes' => 'Werkte netjes en veilig.',
		];
	}//end form()

	/**
	 * An invited trainer (a `low` session) may assess, and the stored row says
	 * who assessed, for which company, and that the sign-in was `basic`.
	 *
	 * @return void
	 */
	public function testAnInvitedTrainerMayAssessAndTheRowSaysWho(): void {
		$outcome = $this->service()->submit(trainerRef: self::TRAINER, trust: 'low', body: self::form());

		self::assertSame(201, $outcome->status);
		self::assertSame('basic', $outcome->body['assuranceLevel']);

		$saved = $this->store->saves[0]['object'];
		self::assertSame('werkproces-assessment', $this->store->saves[0]['schema']);
		self::assertSame(self::TRAINER, $saved['assessorId']);
		self::assertSame('Karin Smit', $saved['assessorName']);
		self::assertSame('Installatiebedrijf Van Dam', $saved['assessorCompany']);
		self::assertSame('12345678', $saved['assessorCompanyKvkNumber']);
		self::assertSame('basic', $saved['assuranceLevel']);
		self::assertSame('submitted', $saved['lifecycle']);
		self::assertSame(self::TENANT, $saved['tenant_id']);
		self::assertSame('competent', $saved['assessment']);
	}//end testAnInvitedTrainerMayAssessAndTheRowSaysWho()

	/**
	 * An eHerkenning session is recorded as `substantial`, so the two are told
	 * apart afterwards.
	 *
	 * @return void
	 */
	public function testAnEherkenningSessionIsRecordedAsSubstantial(): void {
		$outcome = $this->service()->submit(trainerRef: self::TRAINER, trust: 'substantial', body: self::form());

		self::assertSame(201, $outcome->status);
		self::assertSame('substantial', $this->store->saves[0]['object']['assuranceLevel']);
	}//end testAnEherkenningSessionIsRecordedAsSubstantial()

	/**
	 * A school that demands eHerkenning refuses an invited trainer, and writes
	 * nothing.
	 *
	 * @return void
	 */
	public function testASchoolMayStillDemandEherkenning(): void {
		$service = $this->service(floor: 'substantial');
		$refused = $service->submit(trainerRef: self::TRAINER, trust: 'low', body: self::form());

		self::assertSame(403, $refused->status);
		self::assertSame('substantial', $refused->body['required']);
		self::assertSame([], $this->store->saves);

		$accepted = $service->submit(trainerRef: self::TRAINER, trust: 'substantial', body: self::form());
		self::assertSame(201, $accepted->status);
	}//end testASchoolMayStillDemandEherkenning()

	/**
	 * A client cannot name another assessor, another company or a higher
	 * assurance than their session reached.
	 *
	 * @return void
	 */
	public function testAClientCannotDressUpItsOwnEvidence(): void {
		$this->service()->submit(
			trainerRef: self::TRAINER,
			trust: 'low',
			body: array_merge(self::form(), [
				'assessorId' => 'someone-else',
				'assessorName' => 'Iemand anders',
				'assessorCompany' => 'Ander bedrijf',
				'assessorCompanyKvkNumber' => '99999999',
				'assuranceLevel' => 'high',
				'lifecycle' => 'confirmed',
				'tenant_id' => 'another-tenant',
			])
		);

		$saved = $this->store->saves[0]['object'];
		self::assertSame(self::TRAINER, $saved['assessorId']);
		self::assertSame('Karin Smit', $saved['assessorName']);
		self::assertSame('Installatiebedrijf Van Dam', $saved['assessorCompany']);
		self::assertSame('basic', $saved['assuranceLevel']);
		self::assertSame('submitted', $saved['lifecycle']);
		self::assertSame(self::TENANT, $saved['tenant_id']);
	}//end testAClientCannotDressUpItsOwnEvidence()

	/**
	 * A placement that is not hers, an unknown assessor and a missing
	 * placement are each refused without a write.
	 *
	 * @return void
	 */
	public function testOnlyHerOwnPlacementIsAccepted(): void {
		$other = $this->service(otherOwner: true)->submit(trainerRef: self::TRAINER, trust: 'low', body: self::form());
		self::assertSame(403, $other->status);
		self::assertSame('not_your_placement', $other->body['error']);
		self::assertSame([], $this->store->saves);

		$unknown = $this->service()->submit(trainerRef: 'ghost', trust: 'low', body: self::form());
		self::assertSame(403, $unknown->status);
		self::assertSame('unknown_assessor', $unknown->body['error']);

		$incomplete = $this->service()->submit(trainerRef: self::TRAINER, trust: 'low', body: []);
		self::assertSame(422, $incomplete->status);
		self::assertSame([], $this->store->saves);
	}//end testOnlyHerOwnPlacementIsAccepted()

	/**
	 * A failed write answers a bad gateway and leaks no internals.
	 *
	 * @return void
	 */
	public function testAFailedWriteLeaksNothing(): void {
		$outcome = $this->service(failWrites: true)->submit(trainerRef: self::TRAINER, trust: 'low', body: self::form());

		self::assertSame(502, $outcome->status);
		self::assertSame(['error' => 'downstream_error'], $outcome->body);
		self::assertStringNotContainsStringIgnoringCase('SQLSTATE', json_encode($outcome->body));
	}//end testAFailedWriteLeaksNothing()

	/**
	 * The row the service stores passes the shipped WerkprocesAssessment
	 * fragment, and a wrongly spelled assurance does not.
	 *
	 * @return void
	 */
	public function testTheStoredRowPassesTheRealSchema(): void {
		$this->service()->submit(trainerRef: self::TRAINER, trust: 'low', body: self::form());
		$saved = $this->store->saves[0]['object'];

		self::assertNull(self::schemaError(slug: 'werkproces-assessment', payload: $saved));
		self::assertNotNull(
			self::schemaError(slug: 'werkproces-assessment', payload: array_merge($saved, ['assuranceLevel' => 'invitation'])),
			'control: the assurance is one of the eIDAS levels'
		);
	}//end testTheStoredRowPassesTheRealSchema()
}//end class
