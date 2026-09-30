<?php

/**
 * Learniq compliance roll-up with regulation exemptions.
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
 *
 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#requirement-exemptions-in-the-roll-up
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Service\ComplianceRollupService;
use OCA\Learniq\Service\ExternalTrainingService;
use OCA\Learniq\Service\RegulationAudienceResolver;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * A granted exemption excuses a learner from one rule until its end date.
 *
 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#requirement-exemptions-in-the-roll-up
 */
class RegulationExemptionRollupTest extends TestCase {

	/**
	 * The store OpenRegister stands in for, filters and declared properties honoured.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * Ten learners in Infra, a rule for Infra, six of them covered.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new RegisterFaithfulStore();
		for ($i = 0; $i < 10; $i++) {
			$this->store->rows['learner-profile'][] = ['id' => 'p-' . $i, 'ncUserId' => 'u' . $i, 'department' => 'Infra', 'lifecycle' => 'active'];
		}

		$this->store->rows['regulation'] = [
			['id' => 'reg-h', 'slug' => 'working-at-height', 'audienceScope' => 'department', 'audienceDepartments' => ['Infra'], 'lifecycle' => 'published'],
		];
	}//end setUp()

	/**
	 * The roll-up over the store; u0 to u5 are covered.
	 *
	 * @param string $today The evaluation day.
	 *
	 * @return array<string, mixed> The Infra row.
	 */
	private function infra(string $today): array {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(fn (array $config): array => $this->store->findAll(config: $config));

		$training = $this->createMock(ExternalTrainingService::class);
		$training->method('isLearnerCovered')->willReturnCallback(
			static fn (string $learnerId): bool => in_array($learnerId, ['u0', 'u1', 'u2', 'u3', 'u4', 'u5'], true)
		);

		$rows = (new ComplianceRollupService($objectService, new RegulationAudienceResolver(), $training))
			->byDepartment(new DateTimeImmutable($today . 'T09:00:00', new DateTimeZone('UTC')));

		return array_column($rows, null, 'department')['Infra'];
	}//end infra()

	/**
	 * An exemption for learner $n.
	 *
	 * @param int                  $n      The learner.
	 * @param array<string, mixed> $fields Overrides.
	 *
	 * @return array<string, mixed>
	 */
	private static function exemption(int $n, array $fields = []): array {
		return array_merge(
			['id' => 'ex-' . $n, 'learnerId' => 'p-' . $n, 'regulationSlug' => 'working-at-height', 'reasonKind' => 'medical', 'reasonText' => 'x', 'lifecycle' => 'granted', 'validUntil' => '2027-06-30'],
			$fields
		);
	}//end exemption()

	/**
	 * Ten in the audience, one exempt, six covered: nine in scope, six covered, one excused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#scenario-an-exempt-learner-is-not-a-gap
	 */
	public function testAnExemptLearnerIsNotAGap(): void {
		$this->store->rows['regulation-exemption'] = [self::exemption(n: 9)];
		$infra = $this->infra(today: '2026-10-01');

		self::assertSame(9, $infra['obligations']);
		self::assertSame(6, $infra['covered']);
		self::assertSame(1, $infra['excused']);
		self::assertSame(66.7, $infra['coveragePercent']);
	}//end testAnExemptLearnerIsNotAGap()

	/**
	 * The exemption applies through its last day and stops the day after.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#scenario-the-exemption-lapses
	 */
	public function testTheExemptionLapses(): void {
		$this->store->rows['regulation-exemption'] = [self::exemption(n: 9, fields: ['validUntil' => '2026-09-30'])];

		self::assertSame(1, $this->infra(today: '2026-09-30')['excused'], 'the last day still counts');

		$after = $this->infra(today: '2026-10-01');
		self::assertSame(10, $after['obligations']);
		self::assertSame(0, $after['excused']);
		self::assertSame(6, $after['covered']);
	}//end testTheExemptionLapses()

	/**
	 * Only a granted exemption, for this rule, already started, excuses; a
	 * covered learner who is also exempt is excused, not counted twice.
	 *
	 * @return void
	 */
	public function testOnlyAGrantedStartedExemptionForTheRuleExcuses(): void {
		$this->store->rows['regulation-exemption'] = [
			self::exemption(n: 6, fields: ['lifecycle' => 'requested']),
			self::exemption(n: 7, fields: ['regulationSlug' => 'first-aid']),
			self::exemption(n: 8, fields: ['validFrom' => '2026-11-01']),
			self::exemption(n: 9, fields: ['lifecycle' => 'rejected']),
			self::exemption(n: 0),
		];
		$infra = $this->infra(today: '2026-10-01');

		self::assertSame(1, $infra['excused']);
		self::assertSame(9, $infra['obligations']);
		self::assertSame(5, $infra['covered']);
	}//end testOnlyAGrantedStartedExemptionForTheRuleExcuses()
}//end class
