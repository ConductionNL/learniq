<?php

/**
 * Tests for HourPlanActivationGuard.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/school-structure/spec.md#scenario-a-second-active-plan-for-the-same-intake-is-refused
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\HourPlanActivationGuard;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Tests\Support\CapturingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * One active hour plan per programme and intake year.
 */
class HourPlanActivationGuardTest extends TestCase {

	/**
	 * Build the guard over stored plans.
	 *
	 * @param array<int,array<string,mixed>> $plans Stored plans.
	 * @param bool                           $fails Whether the read throws.
	 *
	 * @return HourPlanActivationGuard
	 */
	private function guard(array $plans, bool $fails = false): HourPlanActivationGuard {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config) use ($plans, $fails): array {
				if ($fails === true) {
					throw new RuntimeException('down');
				}

				$f = $config['filters'];
				return OrEntityFactory::makeMany(
					array_values(array_filter($plans, static fn (array $p): bool => $p['programmeId'] === $f['programmeId'] && $p['intakeYear'] === $f['intakeYear'])),
					'hour-plan'
				);
			}
		);

		return new HourPlanActivationGuard($objects, new NullLogger());
	}//end guard()

	/**
	 * A second active plan for the same programme and intake is refused, naming the active one.
	 *
	 * @return void
	 */
	public function testSecondActivePlanIsRefused(): void {
		$plans = [['id' => 'hp-1', 'name' => 'MMC 2026-2027', 'programmeId' => 'p', 'intakeYear' => '2026-2027', 'lifecycle' => 'active']];

		$result = $this->guard(plans: $plans)->check(['id' => 'hp-2', 'programmeId' => 'p', 'intakeYear' => '2026-2027'], 'activate', 'coord');

		self::assertFalse($result->isAllowed());
		self::assertStringContainsString('MMC 2026-2027', (string)$result->getMessage());
	}//end testSecondActivePlanIsRefused()

	/**
	 * Another intake, a draft sibling or the plan itself do not block.
	 *
	 * @return void
	 */
	public function testOtherIntakesAndDraftsDoNotBlock(): void {
		$plans = [
			['id' => 'hp-1', 'programmeId' => 'p', 'intakeYear' => '2025-2026', 'lifecycle' => 'active'],
			['id' => 'hp-3', 'programmeId' => 'p', 'intakeYear' => '2026-2027', 'lifecycle' => 'draft'],
			['id' => 'hp-2', 'programmeId' => 'p', 'intakeYear' => '2026-2027', 'lifecycle' => 'active'],
		];

		self::assertTrue($this->guard(plans: $plans)->check(['id' => 'hp-2', 'programmeId' => 'p', 'intakeYear' => '2026-2027'], 'activate', 'coord')->isAllowed());
	}//end testOtherIntakesAndDraftsDoNotBlock()

	/**
	 * A plan without programme or intake, or a failing read, is refused.
	 *
	 * @return void
	 */
	public function testIncompletePlanAndFailingReadAreRefused(): void {
		self::assertFalse($this->guard(plans: [])->check(['id' => 'hp-2', 'programmeId' => 'p'], 'activate', 'coord')->isAllowed());
		self::assertFalse($this->guard(plans: [], fails: true)->check(['id' => 'hp-2', 'programmeId' => 'p', 'intakeYear' => '2026-2027'], 'activate', 'coord')->isAllowed());
	}//end testIncompletePlanAndFailingReadAreRefused()
	/**
	 * Every outcome with its message, the query and the log lines, recorded:
	 * no sibling, an active sibling with and without a name, an active
	 * sibling of another intake, the plan itself by uuid, an array row, a
	 * missing intake year and a failing read.
	 *
	 * @return void
	 */
	public function testEachOutcomeAsRecorded(): void {
		$plan = ['id' => 'hp-2', 'programmeId' => 'p', 'intakeYear' => '2026-2027'];
		$cases = [
			'no sibling' => [$plan, [['id' => 'hp-1', 'programmeId' => 'p', 'intakeYear' => '2026-2027', 'lifecycle' => 'draft']], false],
			'a named active sibling' => [$plan, [['id' => 'hp-1', 'name' => 'Havo 2026', 'programmeId' => 'p', 'intakeYear' => '2026-2027', 'lifecycle' => 'active']], false],
			'an unnamed active sibling' => [$plan, [['uuid' => 'hp-3', 'programmeId' => 'p', 'intakeYear' => '2026-2027', 'lifecycle' => 'active']], false],
			'active, another intake' => [$plan, [['id' => 'hp-4', 'programmeId' => 'p', 'intakeYear' => '2025-2026', 'lifecycle' => 'active']], false],
			'itself by uuid' => [['uuid' => 'hp-5', 'programmeId' => 'p', 'intakeYear' => '2026-2027'], [['id' => 'hp-5', 'programmeId' => 'p', 'intakeYear' => '2026-2027', 'lifecycle' => 'active']], false],
			'a row that is not an entity' => [$plan, ['x', null], false],
			'no intake year' => [['id' => 'hp-2', 'programmeId' => 'p', 'intakeYear' => ''], [], false],
			'a failing read' => [$plan, [], true],
		];

		$actual = [];
		foreach ($cases as $name => [$object, $rows, $fails]) {
			$queries = [];
			$objects = $this->createMock(ObjectService::class);
			$objects->method('findAll')->willReturnCallback(
				function (array $config, ...$rest) use (&$queries, $rows, $fails): array {
					$queries[] = [$config, $rest];
					if ($fails === true) {
						throw new RuntimeException('down');
					}

					return $rows;
				}
			);
			$logger = new CapturingLogger();

			$result = (new HourPlanActivationGuard($objects, $logger))->check($object, 'activate', 'coord');
			$actual[$name] = ['allowed' => $result->isAllowed(), 'message' => $result->getMessage(), 'queries' => $queries, 'log' => $logger->dump()];
		}

		$golden = __DIR__ . '/golden/HourPlanActivationGuard.json';
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents($golden, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		self::assertSame(json_decode((string)file_get_contents($golden), true), json_decode((string)json_encode($actual), true));
	}//end testEachOutcomeAsRecorded()
}//end class
