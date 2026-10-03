<?php

/**
 * Learniq PokParentSignatureRule unit tests.
 *
 * A minor cannot sign a work placement agreement alone. The rule reads the
 * placement's learner, the learner's date of birth and the date the student
 * signed, and says whether a parent must sign and which accounts may.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\PokParentSignatureRule;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PokParentSignatureRule::evaluate().
 */
class PokParentSignatureRuleTest extends TestCase {

	/**
	 * Rows by schema slug, then by id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Every read the rule made: schema slug and whether RBAC was on.
	 *
	 * @var array<int, array{schema: string, rbac: bool}>
	 */
	private array $reads = [];

	/**
	 * Build the rule over the in-memory rows, with "today" fixed.
	 *
	 * @param string $today Today's date (Y-m-d) in Europe/Amsterdam.
	 *
	 * @return PokParentSignatureRule
	 */
	private function makeRule(string $today = '2026-09-27'): PokParentSignatureRule {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null, bool $_rbac = true) {
				$this->reads[] = ['schema' => (string)$schema, 'rbac' => $_rbac];
				$row = $this->rows[(string)$schema][(string)$id] ?? null;
				if ($row === null) {
					throw new DoesNotExistException('not found');
				}

				return OrEntityFactory::make($row, (string)$schema);
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac = true) {
				$this->reads[] = ['schema' => (string)$config['filters']['schema'], 'rbac' => $_rbac];
				$filters = array_diff_key($config['filters'], ['register' => true, 'schema' => true]);

				return array_values(
					array_filter(
						$this->rows[$config['filters']['schema']] ?? [],
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
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable($today . ' 12:00:00', new DateTimeZone('Europe/Amsterdam')));

		return new PokParentSignatureRule($objectService, new LearnerRefResolver($objectService), $time);
	}//end makeRule()

	/**
	 * Seed a placement for the learner and a profile with this birth date.
	 *
	 * @param string|null $birthDate Date of birth, or null for none recorded.
	 * @param bool $withLearnerRef Whether the placement carries learnerRef.
	 *
	 * @return array<string, mixed> The POK.
	 */
	private function seed(?string $birthDate, bool $withLearnerRef = true): array {
		$this->rows['learner-profile']['lp-1'] = [
			'id' => 'lp-1',
			'ncUserId' => 'student-1',
			'birthDate' => $birthDate,
			'parentIds' => ['ouder-1', 'ouder-2'],
			'lifecycle' => 'active',
		];
		$placement = ['id' => 'placement-1', 'learnerId' => 'student-1'];
		if ($withLearnerRef === true) {
			$placement['learnerRef'] = 'lp-1';
		}

		$this->rows['bpv-placement']['placement-1'] = $placement;

		return ['id' => 'pok-1', 'bpvPlacementId' => 'placement-1', 'version' => 1, 'tenant_id' => 'tenant-a'];
	}//end seed()

	/**
	 * A student who was 16 on the day they signed needs a parent, and the
	 * profile's parents may sign.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#scenario-a-minors-agreement-waits-for-a-parent
	 */
	public function testAMinorAtSigningNeedsAParent(): void {
		$pok = $this->seed(birthDate: '2009-04-30');

		$result = $this->makeRule()->evaluate($pok, '2025-08-22T19:05:00+02:00');

		self::assertTrue($result['required']);
		self::assertSame(PokParentSignatureRule::REASON_MINOR, $result['reason']);
		self::assertSame(['ouder-1', 'ouder-2'], $result['parentIds']);
		foreach ($this->reads as $read) {
			self::assertFalse($read['rbac'], $read['schema'] . ' is read without RBAC');
		}
	}//end testAMinorAtSigningNeedsAParent()

	/**
	 * A student of 19 needs no parent.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#scenario-an-adults-agreement-needs-no-parent
	 */
	public function testAnAdultNeedsNoParent(): void {
		$pok = $this->seed(birthDate: '2006-02-11');

		$result = $this->makeRule()->evaluate($pok, '2025-08-22T19:05:00+02:00');

		self::assertFalse($result['required']);
		self::assertSame(PokParentSignatureRule::REASON_ADULT, $result['reason']);
	}//end testAnAdultNeedsNoParent()

	/**
	 * The age on the day the student signed decides, so a student who turns
	 * 18 afterwards still needs the parent; and the day of the 18th birthday
	 * already counts as adult.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature
	 */
	public function testTheAgeOnTheSigningDayDecides(): void {
		$pok  = $this->seed(birthDate: '2007-08-23');
		$rule = $this->makeRule(today: '2026-09-27');

		self::assertTrue($rule->evaluate($pok, '2025-08-22T23:59:00+02:00')['required'], 'signed the day before turning 18');
		self::assertFalse($rule->evaluate($pok, '2025-08-23T08:00:00+02:00')['required'], 'signed on the 18th birthday');
	}//end testTheAgeOnTheSigningDayDecides()

	/**
	 * Before the student has signed, today decides.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#scenario-the-signing-flow-asks-for-the-parent
	 */
	public function testWithoutAStudentSignatureTodayDecides(): void {
		$pok = $this->seed(birthDate: '2010-01-15');

		self::assertTrue($this->makeRule(today: '2026-09-27')->evaluate($pok, null)['required']);
		self::assertFalse($this->makeRule(today: '2028-01-15')->evaluate($pok, null)['required']);
	}//end testWithoutAStudentSignatureTodayDecides()

	/**
	 * No date of birth means the age is unknown, and a parent signs.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#scenario-an-unknown-date-of-birth-asks-for-a-parent
	 */
	public function testAnUnknownDateOfBirthAsksForAParent(): void {
		$pok = $this->seed(birthDate: null);

		$result = $this->makeRule()->evaluate($pok, '2025-08-22T19:05:00+02:00');

		self::assertTrue($result['required']);
		self::assertSame(PokParentSignatureRule::REASON_UNKNOWN_AGE, $result['reason']);
		self::assertSame(['ouder-1', 'ouder-2'], $result['parentIds']);
	}//end testAnUnknownDateOfBirthAsksForAParent()

	/**
	 * A placement without learnerRef finds the profile on the learner's user id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature
	 */
	public function testAPlacementWithoutLearnerRefFindsTheProfileOnTheUserId(): void {
		$pok = $this->seed(birthDate: '2009-04-30', withLearnerRef: false);

		$result = $this->makeRule()->evaluate($pok, '2025-08-22T19:05:00+02:00');

		self::assertSame(PokParentSignatureRule::REASON_MINOR, $result['reason']);
		self::assertSame(['ouder-1', 'ouder-2'], $result['parentIds']);
	}//end testAPlacementWithoutLearnerRefFindsTheProfileOnTheUserId()

	/**
	 * A POK whose placement cannot be found fails closed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature
	 */
	public function testAMissingPlacementFailsClosed(): void {
		$result = $this->makeRule()->evaluate(['id' => 'pok-9', 'bpvPlacementId' => 'gone', 'version' => 1], null);

		self::assertTrue($result['required']);
		self::assertSame(PokParentSignatureRule::REASON_UNKNOWN_AGE, $result['reason']);
		self::assertSame([], $result['parentIds']);
	}//end testAMissingPlacementFailsClosed()
}//end class
