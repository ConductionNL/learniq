<?php

/**
 * Learniq AdmissionsDecisionGuard unit tests.
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
 * @spec openspec/specs/enrolment/spec.md#requirement-an-mbo-applicant-who-applies-by-the-deadline-and-completes-the-mandatory-intake-has-a-right-to-admission
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Lifecycle\AdmissionsDecisionGuard;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the AdmissionsDecisionGuard lifecycle guard.
 */
class AdmissionsDecisionGuardTest extends TestCase {

	use GuardVerdicts;

	/**
	 * ObjectService mock.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Set up a fresh mock before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);

	}//end setUp()

	/**
	 * Wire ObjectService::find to return the given AdmissionsRound for any admissions-round id.
	 *
	 * @param array<string,mixed>|null $round The round to return.
	 *
	 * @return void
	 */
	private function wireRound(?array $round): void {
		// OpenRegister's find() is find($id, $_extend, $files, $register, $schema, ...)
		// and returns ?ObjectEntity. willReturnCallback() hands the closure the
		// mock's arguments POSITIONALLY, so the closure must mirror that order.
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) use ($round) {
				if ($schema === 'admissions-round' && $round !== null) {
					return OrEntityFactory::make($round, 'admissions-round');
				}

				return null;
			}
		);

	}//end wireRound()

	/**
	 * Wire ObjectService::findAll to return $placed rows for a `placed` lifecycle
	 * filter and $converted rows for a `converted` lifecycle filter on application.
	 *
	 * @param array<int,array<string,mixed>> $placed Rows for lifecycle=placed.
	 * @param array<int,array<string,mixed>> $converted Rows for lifecycle=converted.
	 *
	 * @return void
	 */
	private function wireApplicationCounts(array $placed, array $converted): void {
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($placed, $converted) {
				if (($config['filters']['schema'] ?? '') !== 'admission') {
					return [];
				}

				$lifecycle = $config['filters']['lifecycle'] ?? '';
				if ($lifecycle === 'placed') {
					return $placed;
				}

				if ($lifecycle === 'converted') {
					return $converted;
				}

				return [];
			}
		);

	}//end wireApplicationCounts()

	/**
	 * Build the guard under test.
	 *
	 * @return AdmissionsDecisionGuard
	 */
	private function makeGuard(): AdmissionsDecisionGuard {
		return new AdmissionsDecisionGuard($this->objectService, new NullLogger());
	}//end makeGuard()

	/**
	 * Base application fixture. Override fields per test.
	 *
	 * @param array<string,mixed> $overrides Fields to override.
	 *
	 * @return array<string,mixed>
	 */
	private function application(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'app-1',
				'admissionsRoundId' => 'round-1',
				'tenant_id' => 'tenant-a',
				'submittedAt' => '2026-01-15T10:00:00+01:00',
			],
			$overrides
		);

	}//end application()

	/**
	 * completeIntake is blocked when mandatoryIntake is true and intakeCompleted is false.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-an-mbo-applicant-who-applies-by-the-deadline-and-completes-the-mandatory-intake-has-a-right-to-admission
	 */
	public function testMandatoryIntakeBlocksCompleteIntakeWhenNotRecorded(): void {
		$this->wireRound(['kind' => 'generic', 'mandatoryIntake' => true]);

		$object = array_merge($this->application(['intakeCompleted' => false]), ['lifecycle' => 'intake-completed']);

		self::assertDenied($this->makeGuard()->check($object, 'completeIntake', ''));

	}//end testMandatoryIntakeBlocksCompleteIntakeWhenNotRecorded()

	/**
	 * completeIntake succeeds when mandatoryIntake is true and intakeCompleted is true.
	 *
	 * @return void
	 */
	public function testMandatoryIntakeAllowsCompleteIntakeWhenRecorded(): void {
		$this->wireRound(['kind' => 'generic', 'mandatoryIntake' => true]);

		$object = array_merge($this->application(['intakeCompleted' => true]), ['lifecycle' => 'intake-completed']);

		self::assertAllowed($this->makeGuard()->check($object, 'completeIntake', ''));

	}//end testMandatoryIntakeAllowsCompleteIntakeWhenRecorded()

	/**
	 * completeIntake succeeds regardless of intakeCompleted when mandatoryIntake is false.
	 *
	 * @return void
	 */
	public function testMandatoryIntakeFalseAllowsCompleteIntakeRegardless(): void {
		$this->wireRound(['kind' => 'generic', 'mandatoryIntake' => false]);

		$object = array_merge($this->application(['intakeCompleted' => false]), ['lifecycle' => 'intake-completed']);

		self::assertAllowed($this->makeGuard()->check($object, 'completeIntake', ''));

	}//end testMandatoryIntakeFalseAllowsCompleteIntakeRegardless()

	/**
	 * A timely, intake-complete MBO application with a given studiekeuzeadvies cannot be
	 * rejected without a named decisionReason.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enrolment/spec.md#scenario-a-timely-intake-complete-mbo-application-cannot-be-rejected-without-a-named-reason
	 */
	public function testToelatingsrechtBlocksRejectionWithoutNamedReason(): void {
		$this->wireRound(
			[
				'kind' => 'mbo-toelatingsrecht',
				'applicationDeadline' => '2026-04-01',
			]
		);

		$object = array_merge(
			$this->application(
				[
					'submittedAt' => '2026-03-01T10:00:00+01:00',
					'studiekeuzeadviesGiven' => true,
					'decisionReason' => '',
				]
			),
			['lifecycle' => 'rejected']
		);

		self::assertDenied($this->makeGuard()->check($object, 'reject', ''));

	}//end testToelatingsrechtBlocksRejectionWithoutNamedReason()

	/**
	 * A named prerequisite failure still allows rejection.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enrolment/spec.md#scenario-a-named-prerequisite-failure-still-allows-rejection
	 */
	public function testToelatingsrechtAllowsRejectionWithNamedReason(): void {
		$this->wireRound(
			[
				'kind' => 'mbo-toelatingsrecht',
				'applicationDeadline' => '2026-04-01',
			]
		);

		$object = array_merge(
			$this->application(
				[
					'submittedAt' => '2026-03-01T10:00:00+01:00',
					'studiekeuzeadviesGiven' => true,
					'decisionReason' => 'prerequisite diploma not held',
				]
			),
			['lifecycle' => 'rejected']
		);

		self::assertAllowed($this->makeGuard()->check($object, 'reject', ''));

	}//end testToelatingsrechtAllowsRejectionWithNamedReason()

	/**
	 * A late application (after applicationDeadline) is not protected by the toelatingsrecht
	 * safeguard — rejection without a named reason is allowed.
	 *
	 * @return void
	 */
	public function testToelatingsrechtDoesNotBlockLateApplicationRejection(): void {
		$this->wireRound(
			[
				'kind' => 'mbo-toelatingsrecht',
				'applicationDeadline' => '2026-04-01',
			]
		);

		$object = array_merge(
			$this->application(
				[
					'submittedAt' => '2026-05-01T10:00:00+01:00',
					'studiekeuzeadviesGiven' => true,
					'decisionReason' => '',
				]
			),
			['lifecycle' => 'rejected']
		);

		self::assertAllowed($this->makeGuard()->check($object, 'reject', ''));

	}//end testToelatingsrechtDoesNotBlockLateApplicationRejection()

	/**
	 * A higher doorstroomtoets score without an adjustment or motivation blocks the decision.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enrolment/spec.md#scenario-a-higher-doorstroomtoets-score-without-an-adjustment-or-motivation-blocks-the-decision
	 */
	public function testSchooladviesAdjustmentRequiredBlocksDecision(): void {
		$this->wireRound(['kind' => 'vo-schooladvies-doorstroomtoets']);

		$object = array_merge(
			$this->application(
				[
					'schoolAdviceLevel' => 'vmbo-gt',
					'progressionTestLevel' => 'havo',
					'schoolAdviceAdjustedLevel' => 'vmbo-gt',
					'adjustmentMotivation' => '',
				]
			),
			['lifecycle' => 'placed']
		);

		self::assertDenied($this->makeGuard()->check($object, 'promote', ''));

	}//end testSchooladviesAdjustmentRequiredBlocksDecision()

	/**
	 * The pro/vmbo-bb exemption allows the decision without adjustment.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enrolment/spec.md#scenario-the-pro-vmbo-bb-exemption-allows-the-decision-without-adjustment
	 */
	public function testProVmboBbExemptionAllowsDecision(): void {
		$this->wireRound(['kind' => 'vo-schooladvies-doorstroomtoets']);

		$object = array_merge(
			$this->application(
				[
					'schoolAdviceLevel' => 'pro',
					'progressionTestLevel' => 'vmbo-bb',
					'schoolAdviceAdjustedLevel' => 'pro',
					'adjustmentMotivation' => '',
				]
			),
			['lifecycle' => 'waitlisted']
		);

		self::assertAllowed($this->makeGuard()->check($object, 'waitlist', ''));

	}//end testProVmboBbExemptionAllowsDecision()

	/**
	 * Raising schooladviesAdjustedLevel to match doorstroomtoetsLevel satisfies the rule.
	 *
	 * @return void
	 */
	public function testAdjustedLevelMatchingDoorstroomtoetsAllowsDecision(): void {
		$this->wireRound(['kind' => 'vo-schooladvies-doorstroomtoets']);

		$object = array_merge(
			$this->application(
				[
					'schoolAdviceLevel' => 'vmbo-gt',
					'progressionTestLevel' => 'havo',
					'schoolAdviceAdjustedLevel' => 'havo',
					'adjustmentMotivation' => '',
				]
			),
			['lifecycle' => 'placed']
		);

		self::assertAllowed($this->makeGuard()->check($object, 'promote', ''));

	}//end testAdjustedLevelMatchingDoorstroomtoetsAllowsDecision()

	/**
	 * A non-empty adjustmentMotivation satisfies the rule even without raising the level.
	 *
	 * @return void
	 */
	public function testMotivatedExceptionAllowsDecision(): void {
		$this->wireRound(['kind' => 'vo-schooladvies-doorstroomtoets']);

		$object = array_merge(
			$this->application(
				[
					'schoolAdviceLevel' => 'vmbo-gt',
					'progressionTestLevel' => 'havo',
					'schoolAdviceAdjustedLevel' => 'vmbo-gt',
					'adjustmentMotivation' => 'Not in the pupil\'s best interest given documented circumstances.',
				]
			),
			['lifecycle' => 'rejected']
		);

		self::assertAllowed($this->makeGuard()->check($object, 'reject', ''));

	}//end testMotivatedExceptionAllowsDecision()

	/**
	 * A full round routes a new placement attempt to blocked (coordinator must waitlist instead).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enrolment/spec.md#scenario-a-full-round-routes-a-new-placement-to-the-waitlist
	 */
	public function testCapacityReachedBlocksPlacement(): void {
		$this->wireRound(['kind' => 'generic', 'capacity' => 2]);
		$this->wireApplicationCounts(placed: [['id' => 'a'], ['id' => 'b']], converted: []);

		$object = array_merge($this->application(), ['lifecycle' => 'placed']);

		self::assertDenied($this->makeGuard()->check($object, 'promote', ''));

	}//end testCapacityReachedBlocksPlacement()

	/**
	 * A round under capacity allows placement.
	 *
	 * @return void
	 */
	public function testCapacityNotReachedAllowsPlacement(): void {
		$this->wireRound(['kind' => 'generic', 'capacity' => 2]);
		$this->wireApplicationCounts(placed: [['id' => 'a']], converted: []);

		$object = array_merge($this->application(), ['lifecycle' => 'placed']);

		self::assertAllowed($this->makeGuard()->check($object, 'promote', ''));

	}//end testCapacityNotReachedAllowsPlacement()

	/**
	 * A null capacity (uncapped) never blocks placement — no findAll query is even needed.
	 *
	 * @return void
	 */
	public function testNullCapacityNeverBlocksPlacement(): void {
		$this->wireRound(['kind' => 'generic', 'capacity' => null]);
		$this->objectService->expects(self::never())->method('findAll');

		$object = array_merge($this->application(), ['lifecycle' => 'placed']);

		self::assertAllowed($this->makeGuard()->check($object, 'promote', ''));

	}//end testNullCapacityNeverBlocksPlacement()

	/**
	 * Capacity counts both `placed` and `converted` Applications toward the limit.
	 *
	 * @return void
	 */
	public function testCapacityCountsPlacedAndConverted(): void {
		$this->wireRound(['kind' => 'generic', 'capacity' => 2]);
		$this->wireApplicationCounts(placed: [['id' => 'a']], converted: [['id' => 'c']]);

		$object = array_merge($this->application(), ['lifecycle' => 'placed']);

		self::assertDenied($this->makeGuard()->check($object, 'promote', ''));

	}//end testCapacityCountsPlacedAndConverted()

	/**
	 * A missing admissionsRoundId fails closed without querying.
	 *
	 * @return void
	 */
	public function testMissingAdmissionsRoundIdFailsClosed(): void {
		$this->objectService->expects(self::never())->method('find');

		$object = ['id' => 'app-1', 'tenant_id' => 'tenant-a', 'lifecycle' => 'placed'];

		self::assertDenied($this->makeGuard()->check($object, 'promote', ''));

	}//end testMissingAdmissionsRoundIdFailsClosed()

	/**
	 * A missing AdmissionsRound (find() returns null) fails closed.
	 *
	 * @return void
	 */
	public function testMissingRoundFailsClosed(): void {
		$this->wireRound(null);

		$object = array_merge($this->application(), ['lifecycle' => 'placed']);

		self::assertDenied($this->makeGuard()->check($object, 'promote', ''));

	}//end testMissingRoundFailsClosed()

	/**
	 * A transition this guard does not govern (e.g. submit) is always allowed without
	 * touching capacity/schooladvies/toelatingsrecht branches.
	 *
	 * @return void
	 */
	public function testUngovernedTransitionAllowed(): void {
		$this->wireRound(['kind' => 'mbo-toelatingsrecht']);

		$object = array_merge($this->application(), ['lifecycle' => 'submitted']);

		self::assertAllowed($this->makeGuard()->check($object, 'completeIntake', ''));

	}//end testUngovernedTransitionAllowed()

	/**
	 * A generic-kind round only applies the capacity branch — no schooladvies/toelatingsrecht checks.
	 *
	 * @return void
	 */
	public function testGenericKindSkipsToelatingsrechtAndSchooladviesBranches(): void {
		$this->wireRound(['kind' => 'generic', 'capacity' => null]);

		$object = array_merge(
			$this->application(
				[
					'schoolAdviceLevel' => 'vmbo-gt',
					'progressionTestLevel' => 'vwo',
					'decisionReason' => '',
				]
			),
			['lifecycle' => 'rejected']
		);

		self::assertAllowed($this->makeGuard()->check($object, 'reject', ''));

	}//end testGenericKindSkipsToelatingsrechtAndSchooladviesBranches()

	/**
	 * The toelatingsrecht safeguard at every boundary: a rejection without a
	 * named reason is blocked unless the application came in after a readable
	 * deadline or without a studiekeuzeadvies; an unreadable or missing date
	 * counts as on time, an unparsable one as late.
	 *
	 * @return void
	 */
	public function testToelatingsrechtAtEveryBoundary(): void {
		$cases = [
			'on the deadline'        => ['2026-04-01', '2026-04-01T00:00:00+00:00', true, '', false],
			'one second late'        => ['2026-04-01T00:00:00+00:00', '2026-04-01T00:00:01+00:00', true, '', true],
			'no deadline'            => [null, '2026-09-01T10:00:00+01:00', true, '', false],
			'empty deadline'         => ['', '2026-09-01T10:00:00+01:00', true, '', false],
			'no submission date'     => ['2026-04-01', null, true, '', false],
			'numeric submission'     => ['2026-04-01', 20260901, true, '', false],
			'unparsable deadline'    => ['not a date', '2026-03-01T10:00:00+01:00', true, '', true],
			'unparsable submission'  => ['2026-04-01', 'soon', true, '', true],
			'no advies'              => ['2026-04-01', '2026-03-01T10:00:00+01:00', false, '', true],
			'advies as string'       => ['2026-04-01', '2026-03-01T10:00:00+01:00', 'yes', '', true],
			'blank reason'           => ['2026-04-01', '2026-03-01T10:00:00+01:00', true, '   ', false],
			'named reason'           => ['2026-04-01', '2026-03-01T10:00:00+01:00', true, 'no diploma', true],
		];

		foreach ($cases as $name => [$deadline, $submittedAt, $advies, $reason, $allowed]) {
			$this->objectService = $this->createMock(ObjectService::class);
			$this->wireRound(['kind' => 'mbo-toelatingsrecht', 'applicationDeadline' => $deadline]);
			$object = array_merge(
				$this->application(['submittedAt' => $submittedAt, 'studiekeuzeadviesGiven' => $advies, 'decisionReason' => $reason]),
				['lifecycle' => 'rejected']
			);

			$verdict = $this->makeGuard()->check($object, 'reject', '');
			if ($allowed === true) {
				self::assertAllowed($verdict, $name);
				continue;
			}

			self::assertDenied($verdict, $name);
		}
	}//end testToelatingsrechtAtEveryBoundary()

	/**
	 * The schooladvies rule at every branch: nothing to compare, a toets that
	 * does not outrank, a matching adjustment, a motivation, and the pro /
	 * vmbo-bb exemption only when both levels are in it.
	 *
	 * @return void
	 */
	public function testSchooladviesAtEveryBranch(): void {
		$cases = [
			'nothing recorded'     => [null, null, null, '', true],
			'unknown level'        => ['vmbo-gt', 'gymnasium-plus', null, '', true],
			'toets lower'          => ['havo', 'vmbo-gt', null, '', true],
			'toets equal'          => ['havo', 'havo', null, '', true],
			'adjusted to toets'    => ['vmbo-gt', 'havo', 'havo', '', true],
			'adjusted elsewhere'   => ['vmbo-gt', 'havo', 'vmbo-kb', '', false],
			'blank motivation'     => ['vmbo-gt', 'havo', null, '  ', false],
			'motivated'            => ['vmbo-gt', 'havo', null, 'past niet', true],
			'pro to vmbo-bb'       => ['pro', 'vmbo-bb', null, '', true],
			'pro to vmbo-kb'       => ['pro', 'vmbo-kb', null, '', false],
		];

		foreach ($cases as $name => [$advice, $toets, $adjusted, $motivation, $allowed]) {
			$this->objectService = $this->createMock(ObjectService::class);
			$this->wireRound(['kind' => 'vo-schooladvies-doorstroomtoets']);
			$object = array_merge(
				$this->application(
					[
						'schoolAdviceLevel' => $advice,
						'progressionTestLevel' => $toets,
						'schoolAdviceAdjustedLevel' => $adjusted,
						'adjustmentMotivation' => $motivation,
					]
				),
				['lifecycle' => 'waitlisted']
			);

			$verdict = $this->makeGuard()->check($object, 'waitlist', '');
			if ($allowed === true) {
				self::assertAllowed($verdict, $name);
				continue;
			}

			self::assertDenied($verdict, $name);
		}
	}//end testSchooladviesAtEveryBranch()
}//end class
