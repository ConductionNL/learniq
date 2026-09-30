<?php

/**
 * Learniq ReportPeriodLockGuard unit tests.
 *
 * Covers the composition with FraudCaseBlockGuard (a fraud-blocked GradeEntry
 * MUST stay blocked regardless of ReportPeriod lock state — the fraud-appeal
 * guarantee this guard must never regress), the fail-open "no governing
 * ReportPeriod" posture, the locked-period block, and the four-eyes rule
 * that replaced the lone role override: a publish in a locked period needs
 * a correction approved by a second person (governance-four-eyes).
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
 * @spec openspec/changes/archive/2026-07-16-report-card-composer/specs/grading/spec.md#scenario-publishrepublish-proceeds-unaffected-when-no-reportperiod-governs-the-entry
 * @spec openspec/changes/archive/2026-07-16-report-card-composer/specs/grading/spec.md#scenario-an-ordinary-teacher-cannot-publish-a-grade-for-a-locked-report-period
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\FraudCaseBlockGuard;
use OCA\Learniq\Lifecycle\ReportPeriodLockGuard;
use OCA\Learniq\Service\Grading\CorrectionApprovals;
use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ReportPeriodLockGuard (GradeEntry publish/republish).
 */
class ReportPeriodLockGuardTest extends TestCase {

	use GuardVerdicts;

	/**
	 * A locked report period for period 1 of plan-1.
	 *
	 * @var array<string, mixed>
	 */
	private const LOCKED = ['id' => 'period-1', 'periodCode' => '1', 'curriculumPlanIds' => ['plan-1'], 'isLocked' => true];

	/**
	 * A published grade in that period, being republished as a 6.5.
	 *
	 * @var array<string, mixed>
	 */
	private const ENTRY = ['id' => 'entry-1', 'period' => '1', 'curriculumPlanId' => 'plan-1', 'tenant_id' => 'tenant-a', 'value' => 6.5, 'lifecycle' => 'published'];

	/**
	 * Teacher A's request for that grade, approved by principal B.
	 *
	 * @var array<string, mixed>
	 */
	private const APPROVED = [
		'id'            => 'dcr-1',
		'gradeEntryId'  => 'entry-1',
		'proposedValue' => 6.5,
		'currentValue'  => 5.5,
		'reason'        => 'A page of the exam was not counted.',
		'requestedBy'   => 'teacher-a',
		'decidedBy'     => 'principal-b',
		'lifecycle'     => 'approved',
	];

	/**
	 * Build a guard over the real CorrectionApprovals.
	 *
	 * @param bool                           $fraudCaseAllows Whether the composed FraudCaseBlockGuard's check() allows.
	 * @param array<int,array<string,mixed>> $reportPeriods   ReportPeriod rows returned by findAll(schema=report-period).
	 * @param array<int,array<string,mixed>> $corrections     DataCorrectionRequest rows in the store.
	 *
	 * @return ReportPeriodLockGuard
	 */
	private function makeGuard(
		bool $fraudCaseAllows,
		array $reportPeriods,
		array $corrections = [],
	): ReportPeriodLockGuard {
		$fraudCaseGuard = $this->createMock(FraudCaseBlockGuard::class);
		$fraudCaseVerdict = GuardResult::deny('Linked fraud case is still open.');
		if ($fraudCaseAllows === true) {
			$fraudCaseVerdict = GuardResult::allow();
		}

		$fraudCaseGuard->method('check')->willReturn($fraudCaseVerdict);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($reportPeriods, $corrections) {
				$filters = $config['filters'];
				if ($filters['schema'] === 'report-period') {
					return $reportPeriods;
				}

				if ($filters['schema'] === 'data-correction-request') {
					return array_values(
						array_filter(
							$corrections,
							static fn (array $row): bool => $row['gradeEntryId'] === $filters['gradeEntryId'] && $row['lifecycle'] === $filters['lifecycle']
						)
					);
				}

				return [];
			}
		);

		return new ReportPeriodLockGuard(
			$fraudCaseGuard,
			$objectService,
			new CorrectionApprovals(objects: $objectService),
			$this->createMock(LoggerInterface::class)
		);

	}//end makeGuard()

	/**
	 * A fraud-blocked GradeEntry stays blocked regardless of ReportPeriod lock
	 * state — the composed FraudCaseBlockGuard check runs first and short-circuits.
	 * This is the fraud-appeal guarantee this guard composition must never regress.
	 *
	 * @return void
	 */
	public function testFraudCaseBlockGuardShortCircuitsAndStaysBlocked(): void {
		// Even with NO locked ReportPeriod at all, a fraud-case block wins.
		$guard = $this->makeGuard(fraudCaseAllows: false, reportPeriods: []);
		$object = ['id' => 'entry-1', 'fraudCaseId' => 'case-1', 'lifecycle' => 'published'];

		self::assertDenied($guard->check($object, 'publish', 'admin-1'));

	}//end testFraudCaseBlockGuardShortCircuitsAndStaysBlocked()

	/**
	 * Fraud-case check passes; no ReportPeriod governs this entry -> fail open,
	 * allowed unconditionally.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-16-report-card-composer/specs/grading/spec.md#scenario-publishrepublish-proceeds-unaffected-when-no-reportperiod-governs-the-entry
	 */
	public function testNoGoverningReportPeriodAllowsUnconditionally(): void {
		$guard = $this->makeGuard(fraudCaseAllows: true, reportPeriods: []);
		$object = ['id' => 'entry-1', 'period' => '1', 'curriculumPlanId' => 'plan-1', 'tenant_id' => 'tenant-a', 'lifecycle' => 'published'];

		self::assertAllowed($guard->check($object, 'publish', 'teacher-1'));

	}//end testNoGoverningReportPeriodAllowsUnconditionally()

	/**
	 * A matching ReportPeriod that is NOT locked allows unconditionally.
	 *
	 * @return void
	 */
	public function testMatchingButUnlockedReportPeriodAllows(): void {
		$period = ['id' => 'period-1', 'periodCode' => '1', 'curriculumPlanIds' => ['plan-1'], 'isLocked' => false];
		$guard = $this->makeGuard(fraudCaseAllows: true, reportPeriods: [$period]);
		$object = ['id' => 'entry-1', 'period' => '1', 'curriculumPlanId' => 'plan-1', 'tenant_id' => 'tenant-a', 'lifecycle' => 'published'];

		self::assertAllowed($guard->check($object, 'publish', 'teacher-1'));

	}//end testMatchingButUnlockedReportPeriodAllows()

	/**
	 * A matching, locked ReportPeriod blocks an ordinary teacher (no override role).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-16-report-card-composer/specs/grading/spec.md#scenario-an-ordinary-teacher-cannot-publish-a-grade-for-a-locked-report-period
	 */
	public function testMatchingLockedReportPeriodBlocksOrdinaryTeacher(): void {
		$period = ['id' => 'period-1', 'periodCode' => '1', 'curriculumPlanIds' => ['plan-1'], 'isLocked' => true];
		$guard = $this->makeGuard(fraudCaseAllows: true, reportPeriods: [$period]);
		$object = ['id' => 'entry-1', 'period' => '1', 'curriculumPlanId' => 'plan-1', 'tenant_id' => 'tenant-a', 'lifecycle' => 'published'];

		self::assertDenied($guard->check($object, 'publish', 'teacher-1'));

	}//end testMatchingLockedReportPeriodBlocksOrdinaryTeacher()

	/**
	 * A principal can no longer publish into a locked period alone: without a
	 * correction request the republish is denied, and the reason names the
	 * second approver.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-a-principal-cannot-override-alone
	 */
	public function testAPrincipalCannotOverrideAlone(): void {
		$guard = $this->makeGuard(fraudCaseAllows: true, reportPeriods: [self::LOCKED]);

		$verdict = $guard->check(self::ENTRY, 'republish', 'principal-b');
		self::assertDenied($verdict);
		self::assertStringContainsString('second', (string)$verdict->getMessage());
	}//end testAPrincipalCannotOverrideAlone()

	/**
	 * Teacher A asked, principal B approved: teacher A republishes the
	 * approved value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-a-second-person-approves-a-correction
	 */
	public function testASecondPersonsApprovalLetsTheRequesterRepublish(): void {
		$guard = $this->makeGuard(fraudCaseAllows: true, reportPeriods: [self::LOCKED], corrections: [self::APPROVED]);

		self::assertAllowed($guard->check(self::ENTRY, 'republish', 'teacher-a'));
	}//end testASecondPersonsApprovalLetsTheRequesterRepublish()

	/**
	 * The approval does not cover a publish by the approver, a request its own
	 * requester approved, a request that is not approved, another value, or
	 * another grade entry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
	 */
	public function testAnApprovalCoversOnlyItsOwnCase(): void {
		$cases = [
			'the approver publishes'         => [self::APPROVED, self::ENTRY, 'principal-b'],
			'the requester approved it'      => [array_merge(self::APPROVED, ['decidedBy' => 'teacher-a']), self::ENTRY, 'teacher-c'],
			'the request is still requested' => [array_merge(self::APPROVED, ['lifecycle' => 'requested', 'decidedBy' => null]), self::ENTRY, 'teacher-a'],
			'another value is published'     => [self::APPROVED, array_merge(self::ENTRY, ['value' => 8.0]), 'teacher-a'],
			'another grade entry'            => [array_merge(self::APPROVED, ['gradeEntryId' => 'entry-2']), self::ENTRY, 'teacher-a'],
		];
		foreach ($cases as $label => [$request, $entry, $publisher]) {
			$guard = $this->makeGuard(fraudCaseAllows: true, reportPeriods: [self::LOCKED], corrections: [$request]);
			self::assertDenied($guard->check($entry, 'republish', $publisher), $label);
		}
	}//end testAnApprovalCoversOnlyItsOwnCase()

	/**
	 * A ReportPeriod with a different curriculumPlanIds scope does not match —
	 * fail open.
	 *
	 * @return void
	 */
	public function testNonMatchingCurriculumPlanFailsOpen(): void {
		$period = ['id' => 'period-1', 'periodCode' => '1', 'curriculumPlanIds' => ['other-plan'], 'isLocked' => true];
		$guard = $this->makeGuard(fraudCaseAllows: true, reportPeriods: [$period]);
		$object = ['id' => 'entry-1', 'period' => '1', 'curriculumPlanId' => 'plan-1', 'tenant_id' => 'tenant-a', 'lifecycle' => 'published'];

		self::assertAllowed($guard->check($object, 'publish', 'teacher-1'));

	}//end testNonMatchingCurriculumPlanFailsOpen()

	/**
	 * A GradeEntry with no period/curriculumPlanId set has nothing to match — allowed.
	 *
	 * @return void
	 */
	public function testEmptyPeriodOrCurriculumPlanIdAllowsUnconditionally(): void {
		$guard = $this->makeGuard(fraudCaseAllows: true, reportPeriods: []);
		$object = ['id' => 'entry-1', 'lifecycle' => 'published'];

		self::assertAllowed($guard->check($object, 'publish', 'teacher-1'));

	}//end testEmptyPeriodOrCurriculumPlanIdAllowsUnconditionally()
}//end class
