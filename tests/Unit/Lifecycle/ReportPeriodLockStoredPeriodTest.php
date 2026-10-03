<?php

/**
 * A locked report period as OpenRegister stores it (live pass D2).
 *
 * The guard tests above this one hand the guard a period carrying
 * `isLocked: true`. The live register never stores that field: it is a
 * materialised calculation that OpenRegister returns on the create response
 * only, so every read of a stored period answers `isLocked` null and the
 * guard failed open (livepass/learniq/governance-four-eyes-on-approved-data,
 * probe-guard.php). This test stores the period the way the register does:
 * a payload validated with Opis against the shipped report-period schema,
 * read back through RegisterFaithfulStore, and decided by the real guards.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\FraudCaseBlockGuard;
use OCA\Learniq\Lifecycle\ReportPeriodComposeGuard;
use OCA\Learniq\Lifecycle\ReportPeriodLockGuard;
use OCA\Learniq\Service\Grading\CorrectionApprovals;
use OCA\Learniq\Service\Grading\ReportPeriodLocks;
use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The publish and compose guards over a period as the register stores it.
 */
class ReportPeriodLockStoredPeriodTest extends TestCase {
	use GuardVerdicts;
	use RegisterSchemaPayloads;

	private const PLAN = '5f0c1d2e-0000-4000-8000-000000000a01';

	private const TENANT = '11111111-1111-4111-8111-111111111111';

	private RegisterFaithfulStore $store;

	/**
	 * A fresh store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new RegisterFaithfulStore();
	}//end setUp()

	/**
	 * Store a report period the way the register would: only declared
	 * properties, validated against the shipped schema.
	 *
	 * @param string $lockDate The lock date.
	 * @param array<string,mixed> $extra Keys a stale row might carry.
	 *
	 * @return array<string,mixed> The stored period.
	 */
	private function storePeriod(string $lockDate, array $extra = []): array {
		$period = [
			'name'              => 'Live pass locked period',
			'academicYear'      => '2026-2027',
			'periodCode'        => 'LP1',
			'startDate'         => '2026-08-24',
			'endDate'           => '2026-10-30',
			'curriculumPlanIds' => [self::PLAN],
			'cohortIds'         => [],
			'lockDate'          => $lockDate,
			'tenant_id'         => self::TENANT,
			'lifecycle'         => 'open',
		];
		self::assertNull(self::schemaError('report-period', $period));
		$this->store->rows['report-period'][] = array_merge(['id' => 'be5dd7f0-0000-4000-8000-000000000001'], $period, $extra);

		return $period;
	}//end storePeriod()

	/**
	 * A published grade entry in that period.
	 *
	 * @param float $value The value being published.
	 *
	 * @return array<string,mixed>
	 */
	private function entry(float $value = 6.5): array {
		return [
			'id'               => 'c01aa152-0000-4000-8000-000000000001',
			'period'           => 'LP1',
			'curriculumPlanId' => self::PLAN,
			'tenant_id'        => self::TENANT,
			'value'            => $value,
			'lifecycle'        => 'published',
		];
	}//end entry()

	/**
	 * The publish guard, built over the store.
	 *
	 * @return ReportPeriodLockGuard
	 */
	private function guard(): ReportPeriodLockGuard {
		$fraud = $this->createMock(FraudCaseBlockGuard::class);
		$fraud->method('check')->willReturn(GuardResult::allow());

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);

		return new ReportPeriodLockGuard($fraud, new ReportPeriodLocks(objects: $objects), new CorrectionApprovals(objects: $objects), new NullLogger());
	}//end guard()

	/**
	 * The register does not declare `isLocked` as a property, so it is never
	 * part of a stored period: the guards must not depend on reading it.
	 *
	 * @return void
	 */
	public function testTheRegisterNeverStoresIsLocked(): void {
		self::assertArrayNotHasKey('isLocked', self::shippedSchema('report-period')['properties']);
		self::assertNotContains('isLocked', RegisterFaithfulStore::declaredProperties()['report-period']);
	}//end testTheRegisterNeverStoresIsLocked()

	/**
	 * Live pass D2: a teacher republishes into a period whose lock date has
	 * passed, with no correction. Denied.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-a-principal-cannot-override-alone
	 */
	public function testARepublishIntoAStoredLockedPeriodIsDenied(): void {
		$this->storePeriod(lockDate: '2026-10-01T08:00:00+00:00');

		self::assertDenied($this->guard()->check($this->entry(), 'republish', 'lp-teacher'));
	}//end testARepublishIntoAStoredLockedPeriodIsDenied()

	/**
	 * A period created before its lock date could carry a stale `isLocked`
	 * false; the lock date decides.
	 *
	 * @return void
	 */
	public function testAStaleFalseDoesNotUnlockAPassedLockDate(): void {
		$this->storePeriod(lockDate: '2026-10-01T08:00:00+00:00', extra: ['isLocked' => false]);

		self::assertDenied($this->guard()->check($this->entry(), 'republish', 'lp-teacher'));
	}//end testAStaleFalseDoesNotUnlockAPassedLockDate()

	/**
	 * Before the lock date the teacher publishes freely.
	 *
	 * @return void
	 */
	public function testAPeriodBeforeItsLockDateAllows(): void {
		$this->storePeriod(lockDate: '2099-01-01T00:00:00+00:00');

		self::assertAllowed($this->guard()->check($this->entry(), 'republish', 'lp-teacher'));
	}//end testAPeriodBeforeItsLockDateAllows()

	/**
	 * The four-eyes path still works on a stored period: teacher asked,
	 * principal approved, teacher republishes the approved value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-a-second-person-approves-a-correction
	 */
	public function testAnApprovedCorrectionLetsTheRequesterRepublish(): void {
		$this->storePeriod(lockDate: '2026-10-01T08:00:00+00:00');
		$this->store->rows['data-correction-request'][] = [
			'id'            => 'dcr-1',
			'gradeEntryId'  => 'c01aa152-0000-4000-8000-000000000001',
			'currentValue'  => 5.5,
			'proposedValue' => 6.5,
			'requestedBy'   => 'lp-teacher',
			'decidedBy'     => 'lp-principal',
			'lifecycle'     => 'approved',
		];

		self::assertAllowed($this->guard()->check($this->entry(), 'republish', 'lp-teacher'));
		self::assertDenied($this->guard()->check($this->entry(value: 7.0), 'republish', 'lp-teacher'));
	}//end testAnApprovedCorrectionLetsTheRequesterRepublish()

	/**
	 * Compose reads the same answer: a stored period with a stale false and a
	 * passed lock date can be composed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-compose-succeeds-once-the-lock-date-has-passed
	 */
	public function testComposeFollowsTheLockDateNotAStaleFalse(): void {
		$period = $this->storePeriod(lockDate: '2026-10-01T08:00:00+00:00', extra: ['isLocked' => false]);
		$guard = new ReportPeriodComposeGuard(new ReportPeriodLocks(objects: $this->createMock(ObjectService::class)), new NullLogger());

		self::assertAllowed($guard->check(array_merge($period, ['isLocked' => false]), 'compose', ''));
		self::assertAllowed($guard->check($period, 'compose', ''));
	}//end testComposeFollowsTheLockDateNotAStaleFalse()
}//end class
