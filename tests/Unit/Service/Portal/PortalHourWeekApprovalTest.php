<?php

/**
 * Tests for PortalHourWeekApproval.
 *
 * The week the student submitted and the week the trainer approved are the
 * same row with two numbers on it, so every test here reads both: a
 * correction that lost the student's number would pass a test that only
 * checked the approved one.
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
 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use OCA\Learniq\Service\Portal\PortalHourWeekApproval;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A trainer approves, corrects or is refused one week of hours.
 */
class PortalHourWeekApprovalTest extends TestCase {

	private const TRAINER = 'ee030010-0000-4000-8000-000000000001';

	private const OTHER_TRAINER = 'ee030010-0000-4000-8000-000000000002';

	private const PLACEMENT = 'ee030020-0000-4000-8000-000000000001';

	private const WEEK = 'ee030040-0000-4000-8000-000000000001';

	/**
	 * The fake OpenRegister store.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * Build the service over a store holding one trainer, one placement and
	 * one week of 32 hours.
	 *
	 * @param string $floor      The school's assurance floor.
	 * @param string $lifecycle  The week's lifecycle.
	 * @param bool   $saveThrows Whether the write fails downstream.
	 *
	 * @return PortalHourWeekApproval
	 */
	private function approvals(string $floor = 'basic', string $lifecycle = 'submitted', bool $saveThrows = false): PortalHourWeekApproval {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'praktijkopleider' => [
				['id' => self::TRAINER, 'givenName' => 'Karin', 'familyName' => 'Smit', 'active' => true],
			],
			'bpv-placement' => [
				['id' => self::PLACEMENT, 'practicalTrainerId' => self::TRAINER, 'learnerRef' => 'lp-1'],
			],
			'bpv-hour-week' => [
				[
					'id' => self::WEEK,
					'bpvPlacementId' => self::PLACEMENT,
					'learnerRef' => 'lp-1',
					'isoWeek' => '2026-W39',
					'hoursSubmitted' => 32,
					'lifecycle' => $lifecycle,
					'tenant_id' => '00000000-0000-4000-8000-000000000000',
				],
			],
		];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			// The real saveObject() answers an entity, never an array.
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) use ($saveThrows): object {
				if ($saveThrows === true) {
					throw new RuntimeException('SQLSTATE 23505 secret table name');
				}

				return $this->store->save((string)$schema, $object, $uuid ?? self::WEEK);
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($floor);

		return new PortalHourWeekApproval(
			objectService: $objectService,
			appConfig: $config,
			logger: new NullLogger()
		);
	}//end approvals()

	/**
	 * A plain approval keeps the student's number and adds the trainer's.
	 *
	 * @return void
	 */
	public function testAPlainApprovalKeepsBothNumbers(): void {
		$outcome = $this->approvals()->approve(
			trainerRef: self::TRAINER,
			trust: 'low',
			body: ['hourWeekId' => self::WEEK]
		);

		self::assertSame(200, $outcome->status);
		self::assertSame('approved', $outcome->body['lifecycle']);
		self::assertSame('basic', $outcome->body['assuranceLevel']);

		$week = $this->store->rows['bpv-hour-week'][0];
		self::assertSame(32, $week['hoursSubmitted']);
		self::assertSame(32.0, $week['hoursApproved']);
		self::assertSame('Karin Smit', $week['approvedByName']);
		self::assertSame(self::TRAINER, $week['approvedBy']);
		self::assertSame('basic', $week['assuranceLevel']);
	}//end testAPlainApprovalKeepsBothNumbers()

	/**
	 * A correction keeps what the student entered, beside the trainer's own
	 * number and her reason. Being overruled is readable, never silent.
	 *
	 * @return void
	 */
	public function testACorrectionKeepsWhatTheStudentEntered(): void {
		$outcome = $this->approvals()->approve(
			trainerRef: self::TRAINER,
			trust: 'low',
			body: ['hourWeekId' => self::WEEK, 'hoursApproved' => 30, 'note' => 'Donderdag twee uur eerder weg.']
		);

		self::assertSame(200, $outcome->status);
		self::assertSame('corrected', $outcome->body['lifecycle']);

		$week = $this->store->rows['bpv-hour-week'][0];
		self::assertSame(32, $week['hoursSubmitted']);
		self::assertSame(30.0, $week['hoursApproved']);
		self::assertSame('Donderdag twee uur eerder weg.', $week['note']);
	}//end testACorrectionKeepsWhatTheStudentEntered()

	/**
	 * The stored week passes the shipped schema fragment, so a field this
	 * service writes can never be one the register refuses.
	 *
	 * @return void
	 */
	public function testTheStoredWeekPassesTheRealSchema(): void {
		$this->approvals()->approve(trainerRef: self::TRAINER, trust: 'substantial', body: ['hourWeekId' => self::WEEK, 'hoursApproved' => 30]);
		$week = $this->store->rows['bpv-hour-week'][0];

		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/learniq_register.json'), true);
		$schema = $register['components']['schemas']['BpvHourWeek'];

		foreach ($schema['required'] as $field) {
			self::assertArrayHasKey($field, $week, $field . ' is required by the schema');
		}

		foreach ($week as $field => $value) {
			// `id` and the `@self` envelope are OpenRegister's own, not the
			// schema's.
			if (in_array($field, ['id', 'uuid', '@self'], true) === true) {
				continue;
			}

			self::assertArrayHasKey($field, $schema['properties'], $field . ' is not a property of BpvHourWeek');
			$declared = $schema['properties'][$field];
			if (isset($declared['enum']) === true) {
				self::assertContains($value, $declared['enum'], $field);
			}
		}

		self::assertSame('substantial', $week['assuranceLevel']);
	}//end testTheStoredWeekPassesTheRealSchema()

	/**
	 * Another trainer's student is refused, and the week is untouched.
	 *
	 * @return void
	 */
	public function testAnotherTrainersStudentIsRefused(): void {
		$outcome = $this->approvals()->approve(
			trainerRef: self::OTHER_TRAINER,
			trust: 'low',
			body: ['hourWeekId' => self::WEEK, 'hoursApproved' => 0]
		);

		self::assertSame(403, $outcome->status);
		self::assertArrayNotHasKey('hoursApproved', $this->store->rows['bpv-hour-week'][0]);
	}//end testAnotherTrainersStudentIsRefused()

	/**
	 * A school that demands eHerkenning refuses an approval from a session
	 * below its floor, and names the level it wants.
	 *
	 * @return void
	 */
	public function testASchoolCanDemandAHigherAssurance(): void {
		$outcome = $this->approvals(floor: 'substantial')->approve(
			trainerRef: self::TRAINER,
			trust: 'low',
			body: ['hourWeekId' => self::WEEK]
		);

		self::assertSame(403, $outcome->status);
		self::assertSame('substantial', $outcome->body['required']);
		self::assertArrayNotHasKey('hoursApproved', $this->store->rows['bpv-hour-week'][0]);
	}//end testASchoolCanDemandAHigherAssurance()

	/**
	 * A week that was already decided is not decided again.
	 *
	 * @return void
	 */
	public function testAWeekThatWasDecidedIsNotDecidedAgain(): void {
		$outcome = $this->approvals(lifecycle: 'approved')->approve(
			trainerRef: self::TRAINER,
			trust: 'low',
			body: ['hourWeekId' => self::WEEK, 'hoursApproved' => 8]
		);

		self::assertSame(409, $outcome->status);
	}//end testAWeekThatWasDecidedIsNotDecidedAgain()

	/**
	 * A form that names no week, and a week that does not exist, are each
	 * refused with the status that says which it was.
	 *
	 * @return void
	 */
	public function testAnIncompleteOrUnknownWeekIsRefused(): void {
		$incomplete = $this->approvals()->approve(trainerRef: self::TRAINER, trust: 'low', body: []);
		self::assertSame(422, $incomplete->status);
		self::assertSame('incomplete', $incomplete->reason);

		// A trainer the assertion did not name is the same refusal: there is
		// nobody to attribute the decision to.
		$nobody = $this->approvals()->approve(trainerRef: '', trust: 'low', body: ['hourWeekId' => self::WEEK]);
		self::assertSame(422, $nobody->status);

		$unknown = $this->approvals()->approve(
			trainerRef: self::TRAINER,
			trust: 'low',
			body: ['hourWeekId' => 'ee03002b-0000-4000-8000-0000000000ff']
		);
		self::assertSame(404, $unknown->status);
		self::assertSame('week-not-found', $unknown->reason);
	}//end testAnIncompleteOrUnknownWeekIsRefused()

	/**
	 * A trainer the school has no record of cannot approve, even with a
	 * perfectly good assertion: her name goes on the row, and there is none.
	 *
	 * @return void
	 */
	public function testATrainerTheSchoolDoesNotKnowIsRefused(): void {
		$this->approvals();
		$this->store->rows['praktijkopleider'] = [];
		$service = new PortalHourWeekApproval(
			objectService: $this->serviceOverStore(),
			appConfig: $this->configWithFloor(floor: 'basic'),
			logger: new NullLogger()
		);

		$outcome = $service->approve(trainerRef: self::TRAINER, trust: 'low', body: ['hourWeekId' => self::WEEK]);
		self::assertSame(403, $outcome->status);
		self::assertSame('unknown-trainer', $outcome->reason);
	}//end testATrainerTheSchoolDoesNotKnowIsRefused()

	/**
	 * A week whose placement the school cannot find is refused: without the
	 * placement there is nothing to prove the student is hers.
	 *
	 * @return void
	 */
	public function testAWeekWithoutItsPlacementIsRefused(): void {
		$this->approvals();
		$this->store->rows['bpv-hour-week'][0]['bpvPlacementId'] = '';
		$service = new PortalHourWeekApproval(
			objectService: $this->serviceOverStore(),
			appConfig: $this->configWithFloor(floor: 'basic'),
			logger: new NullLogger()
		);

		$outcome = $service->approve(trainerRef: self::TRAINER, trust: 'low', body: ['hourWeekId' => self::WEEK]);
		self::assertSame(403, $outcome->status);
		self::assertSame('not-your-student', $outcome->reason);
	}//end testAWeekWithoutItsPlacementIsRefused()

	/**
	 * A write that fails downstream answers 502 and leaks nothing of the
	 * database: this service sits behind a public route.
	 *
	 * @return void
	 */
	public function testADownstreamFailureLeaksNothing(): void {
		$outcome = $this->approvals(saveThrows: true)->approve(
			trainerRef: self::TRAINER,
			trust: 'low',
			body: ['hourWeekId' => self::WEEK]
		);

		self::assertSame(502, $outcome->status);
		self::assertSame(['error' => 'downstream_error'], $outcome->body);
		self::assertStringNotContainsString('SQLSTATE', (string)json_encode($outcome->body));
	}//end testADownstreamFailureLeaksNothing()

	/**
	 * Approving no hours at all is a rejection, and it says so rather than
	 * reading as an approval of zero.
	 *
	 * @return void
	 */
	public function testApprovingNoHoursIsARejection(): void {
		$outcome = $this->approvals()->approve(
			trainerRef: self::TRAINER,
			trust: 'low',
			body: ['hourWeekId' => self::WEEK, 'hoursApproved' => 0, 'note' => 'Niet op de stage geweest.']
		);

		self::assertSame(200, $outcome->status);
		self::assertSame('rejected', $outcome->body['lifecycle']);
		$week = $this->store->rows['bpv-hour-week'][0];
		// What she entered is still readable beside the refusal.
		self::assertSame(32, $week['hoursSubmitted']);
		self::assertSame(0.0, $week['hoursApproved']);
	}//end testApprovingNoHoursIsARejection()

	/**
	 * A school whose configured floor is not a level on the ladder falls back
	 * to `basic` rather than letting an unreadable setting decide.
	 *
	 * @return void
	 */
	public function testAnUnreadableFloorFallsBackToBasic(): void {
		$outcome = $this->approvals(floor: 'zeer-hoog')->approve(
			trainerRef: self::TRAINER,
			trust: 'low',
			body: ['hourWeekId' => self::WEEK]
		);

		self::assertSame(200, $outcome->status);
		self::assertSame('basic', $outcome->body['assuranceLevel']);
	}//end testAnUnreadableFloorFallsBackToBasic()

	/**
	 * OpenRegister answers rows as entities, as arrays and, on a bad day, as
	 * neither. All three are read without an error, and the last one resolves
	 * no week rather than half of one.
	 *
	 * @return void
	 */
	public function testEveryShapeOpenRegisterCanAnswerIsRead(): void {
		$this->approvals();
		$rows = $this->store->rows;

		// Plain arrays, the shape a findAll() with no entity hydration gives.
		$asArrays = $this->createMock(ObjectService::class);
		$asArrays->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($rows): array {
				$slug = (string)(($config['filters'] ?? [])['schema'] ?? '');
				$ids = (array)($config['ids'] ?? []);
				return array_values(array_filter(($rows[$slug] ?? []), static fn (array $row): bool => $ids === [] || in_array($row['id'], $ids, true)));
			}
		);
		$asArrays->method('saveObject')->willReturnCallback(
			// saveObject() always answers an entity; only the READ shape is
			// what this case varies.
			fn (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): object => OrEntityFactory::make($object, 'bpv-hour-week')
		);
		$outcome = (new PortalHourWeekApproval(
			objectService: $asArrays,
			appConfig: $this->configWithFloor(floor: 'basic'),
			logger: new NullLogger()
		))->approve(trainerRef: self::TRAINER, trust: 'low', body: ['hourWeekId' => self::WEEK]);
		self::assertSame(200, $outcome->status);
		self::assertSame(self::WEEK, $outcome->body['hourWeekId']);

		// Neither an array nor something that serialises: no week resolves.
		$asJunk = $this->createMock(ObjectService::class);
		$asJunk->method('findAll')->willReturn(['not-a-row']);
		$junk = (new PortalHourWeekApproval(
			objectService: $asJunk,
			appConfig: $this->configWithFloor(floor: 'basic'),
			logger: new NullLogger()
		))->approve(trainerRef: self::TRAINER, trust: 'low', body: ['hourWeekId' => self::WEEK]);
		self::assertSame(404, $junk->status);
	}//end testEveryShapeOpenRegisterCanAnswerIsRead()

	/**
	 * An ObjectService over the store this test already seeded.
	 *
	 * @return ObjectService
	 */
	private function serviceOverStore(): ObjectService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			fn (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): object => $this->store->save((string)$schema, $object, $uuid ?? self::WEEK)
		);

		return $objectService;
	}//end serviceOverStore()

	/**
	 * An IAppConfig answering one assurance floor.
	 *
	 * @param string $floor The floor.
	 *
	 * @return IAppConfig
	 */
	private function configWithFloor(string $floor): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($floor);

		return $config;
	}//end configWithFloor()
}//end class
