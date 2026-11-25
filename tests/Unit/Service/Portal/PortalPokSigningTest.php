<?php

/**
 * Tests for PortalPokSigning.
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
 *
 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-an-invited-trainer-may-sign-a-praktijkovereenkomst
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use OCA\Learniq\Service\Portal\PortalPokSigning;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * An invited trainer may sign, the signature says how sure the school is,
 * and a school can still demand more.
 */
class PortalPokSigningTest extends TestCase {
	use RegisterSchemaPayloads;

	private const TRAINER = 'ee010027-0000-4000-8000-000000000001';

	private const PLACEMENT = 'ee010021-0000-4000-8000-000000000001';

	private const AGREEMENT = 'ee010022-0000-4000-8000-000000000001';

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
	 * @param string $floor      The school's assurance floor.
	 * @param string $owner      Who the placement's praktijkopleider is.
	 * @param string $lifecycle  The agreement's state.
	 * @param bool   $failWrites Whether saving throws.
	 *
	 * @return PortalPokSigning
	 */
	private function service(
		string $floor = 'basic',
		string $owner = self::TRAINER,
		string $lifecycle = 'pending-signatures',
		bool $failWrites = false
	): PortalPokSigning {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'praktijkopleider' => [
				['id' => self::TRAINER, 'givenName' => 'Karin', 'familyName' => 'Smit', 'tenant_id' => self::TENANT],
			],
			'bpv-placement' => [
				['id' => self::PLACEMENT, 'practicalTrainerId' => $owner],
			],
			'praktijkovereenkomst' => [
				[
					'id' => self::AGREEMENT,
					'bpvPlacementId' => self::PLACEMENT,
					'version' => 2,
					'lifecycle' => $lifecycle,
					'tenant_id' => self::TENANT,
				],
			],
		];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions()) use ($failWrites) {
				if ($failWrites === true) {
					throw new RuntimeException('SQLSTATE secret table name');
				}

				$schema = (string)$options->target->schema;
				$saved = $this->store->save($schema, $object, ($options->uuid ?? 'signature-' . count($this->store->saves)));
				$this->store->rows[$schema][] = array_merge($object, ['id' => 'signature-' . count($this->store->saves)]);

				return $saved;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => ($key === PortalPokSigning::MIN_ASSURANCE_KEY ? $floor : $default)
		);

		return new PortalPokSigning(objectService: $objectService, appConfig: $appConfig, logger: new NullLogger());
	}//end service()

	/**
	 * An invited trainer (a `low` session) signs her own student's agreement,
	 * and the stored row says she signed as praktijkopleider at `basic`.
	 *
	 * @return void
	 */
	public function testAnInvitedTrainerSignsAndTheRowSaysBasic(): void {
		$outcome = $this->service()->sign(trainerRef: self::TRAINER, trust: 'low', body: ['subjectId' => self::AGREEMENT]);

		self::assertSame(201, $outcome->status);
		self::assertSame('basic', $outcome->body['assuranceLevel']);

		self::assertSame('pok-signature', $this->store->saves[0]['schema']);
		$saved = $this->store->saves[0]['object'];
		self::assertSame(self::AGREEMENT, $saved['subjectId']);
		self::assertSame(2, $saved['subjectVersion']);
		self::assertSame(self::TRAINER, $saved['signerId']);
		self::assertSame('praktijkopleider', $saved['signerRole']);
		self::assertSame('basic', $saved['assuranceLevel']);
		self::assertSame(PortalPokSigning::METHOD, $saved['method']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $saved['signedAt']);
		self::assertSame(self::TENANT, $saved['tenant_id']);
	}//end testAnInvitedTrainerSignsAndTheRowSaysBasic()

	/**
	 * An eHerkenning session is recorded as what it was.
	 *
	 * @return void
	 */
	public function testAnEherkenningSessionIsRecordedAsSubstantial(): void {
		$outcome = $this->service()->sign(trainerRef: self::TRAINER, trust: 'substantial', body: ['subjectId' => self::AGREEMENT]);

		self::assertSame(201, $outcome->status);
		self::assertSame('substantial', $this->store->saves[0]['object']['assuranceLevel']);
	}//end testAnEherkenningSessionIsRecordedAsSubstantial()

	/**
	 * The client cannot claim a stronger signature, another signer, role,
	 * version, time or method: everything but the agreement is the server's.
	 *
	 * @return void
	 */
	public function testTheClientCannotClaimAStrongerSignature(): void {
		$this->service()->sign(
			trainerRef: self::TRAINER,
			trust: 'low',
			body: [
				'subjectId' => self::AGREEMENT,
				'assuranceLevel' => 'high',
				'signerId' => 'someone-else',
				'signerRole' => 'school',
				'subjectVersion' => 1,
				'signedAt' => '2020-01-01T00:00:00Z',
				'method' => 'digid',
				'tenant_id' => 'other-tenant',
			]
		);

		$saved = $this->store->saves[0]['object'];
		self::assertSame('basic', $saved['assuranceLevel']);
		self::assertSame(self::TRAINER, $saved['signerId']);
		self::assertSame('praktijkopleider', $saved['signerRole']);
		self::assertSame(2, $saved['subjectVersion']);
		self::assertNotSame('2020-01-01T00:00:00Z', $saved['signedAt']);
		self::assertSame(PortalPokSigning::METHOD, $saved['method']);
		self::assertSame(self::TENANT, $saved['tenant_id']);
	}//end testTheClientCannotClaimAStrongerSignature()

	/**
	 * A school that demands `substantial` refuses a `basic` signature and
	 * names what it needs; nothing is read or written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-a-school-may-still-demand-eherkenning-for-a-praktijkovereenkomst
	 */
	public function testASchoolMayStillDemandEherkenning(): void {
		$service = $this->service(floor: 'substantial');
		$outcome = $service->sign(trainerRef: self::TRAINER, trust: 'low', body: ['subjectId' => self::AGREEMENT]);

		self::assertSame(403, $outcome->status);
		self::assertSame(['error' => 'assurance_too_low', 'required' => 'substantial'], $outcome->body);
		self::assertSame([], $this->store->saves);
		self::assertSame([], $this->store->reads);

		// The same school accepts an eHerkenning session.
		$accepted = $service->sign(trainerRef: self::TRAINER, trust: 'substantial', body: ['subjectId' => self::AGREEMENT]);
		self::assertSame(201, $accepted->status);
	}//end testASchoolMayStillDemandEherkenning()

	/**
	 * The default school, and a floor set to a word that is no eIDAS level,
	 * both read as `basic`: the setting is read on every write.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-a-school-may-still-demand-eherkenning-for-a-praktijkovereenkomst
	 */
	public function testTheDefaultAndAnUnknownFloorReadAsBasic(): void {
		foreach (['basic', '', 'eHerkenning'] as $declared) {
			$outcome = $this->service(floor: $declared)->sign(trainerRef: self::TRAINER, trust: 'low', body: ['subjectId' => self::AGREEMENT]);
			self::assertSame(201, $outcome->status, $declared);
		}
	}//end testTheDefaultAndAnUnknownFloorReadAsBasic()

	/**
	 * Another trainer's agreement, and one that does not exist, are refused
	 * the same way, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnotherStudentsAgreementIsRefused(): void {
		$outcome = $this->service(owner: 'someone-else')->sign(trainerRef: self::TRAINER, trust: 'low', body: ['subjectId' => self::AGREEMENT]);
		self::assertSame(403, $outcome->status);
		self::assertSame(['error' => 'not_your_placement'], $outcome->body);
		self::assertSame([], $this->store->saves);

		$unknown = $this->service()->sign(trainerRef: self::TRAINER, trust: 'low', body: ['subjectId' => 'ee010022-0000-4000-8000-00000000dead']);
		self::assertSame($outcome->body, $unknown->body);
		self::assertSame([], $this->store->saves);

		$stranger = $this->service()->sign(trainerRef: 'ee010027-0000-4000-8000-00000000dead', trust: 'low', body: ['subjectId' => self::AGREEMENT]);
		self::assertSame(403, $stranger->status);
		self::assertSame([], $this->store->saves);
	}//end testAnotherStudentsAgreementIsRefused()

	/**
	 * An agreement that is already active or ended takes no signature.
	 *
	 * @return void
	 */
	public function testAnAgreementThatNoLongerTakesSignaturesIsRefused(): void {
		foreach (['active', 'completed', 'terminated'] as $state) {
			$outcome = $this->service(lifecycle: $state)->sign(trainerRef: self::TRAINER, trust: 'low', body: ['subjectId' => self::AGREEMENT]);
			self::assertSame(409, $outcome->status, $state);
			self::assertSame('not_open_for_signing', $outcome->body['error']);
			self::assertSame([], $this->store->saves);
		}

		$draft = $this->service(lifecycle: 'draft')->sign(trainerRef: self::TRAINER, trust: 'low', body: ['subjectId' => self::AGREEMENT]);
		self::assertSame(201, $draft->status);
	}//end testAnAgreementThatNoLongerTakesSignaturesIsRefused()

	/**
	 * She signs one version once.
	 *
	 * @return void
	 */
	public function testSheSignsAVersionOnce(): void {
		$service = $this->service();
		self::assertSame(201, $service->sign(trainerRef: self::TRAINER, trust: 'low', body: ['subjectId' => self::AGREEMENT])->status);

		$again = $service->sign(trainerRef: self::TRAINER, trust: 'low', body: ['subjectId' => self::AGREEMENT]);
		self::assertSame(409, $again->status);
		self::assertSame(['error' => 'already_signed'], $again->body);
		self::assertCount(1, $this->store->saves);
	}//end testSheSignsAVersionOnce()

	/**
	 * A request without an agreement names the missing field.
	 *
	 * @return void
	 */
	public function testAnIncompleteRequestNamesTheField(): void {
		$outcome = $this->service()->sign(trainerRef: self::TRAINER, trust: 'low', body: []);

		self::assertSame(422, $outcome->status);
		self::assertSame('incomplete', $outcome->body['error']);
		self::assertArrayHasKey('subjectId', $outcome->body['errors']);
		self::assertSame([], $this->store->saves);
	}//end testAnIncompleteRequestNamesTheField()

	/**
	 * A failed write answers 502 and leaks nothing of the cause.
	 *
	 * @return void
	 */
	public function testAFailedWriteLeaksNothing(): void {
		$outcome = $this->service(failWrites: true)->sign(trainerRef: self::TRAINER, trust: 'low', body: ['subjectId' => self::AGREEMENT]);

		self::assertSame(502, $outcome->status);
		self::assertSame(['error' => 'downstream_error'], $outcome->body);
	}//end testAFailedWriteLeaksNothing()

	/**
	 * The row the service stores passes the shipped PokSignature fragment,
	 * and a wrongly spelled assurance does not.
	 *
	 * @return void
	 */
	public function testTheStoredRowPassesTheRealSchema(): void {
		$this->service()->sign(trainerRef: self::TRAINER, trust: 'low', body: ['subjectId' => self::AGREEMENT]);
		$saved = $this->store->saves[0]['object'];

		self::assertNull(self::schemaError(slug: 'pok-signature', payload: $saved));
		self::assertNotNull(
			self::schemaError(slug: 'pok-signature', payload: array_merge($saved, ['assuranceLevel' => 'invitation'])),
			'control: the assurance is one of the eIDAS levels'
		);
	}//end testTheStoredRowPassesTheRealSchema()
}//end class
