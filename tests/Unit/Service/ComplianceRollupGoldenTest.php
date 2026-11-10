<?php

/**
 * Learniq ComplianceRollupService characterization test.
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
 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Tests\Support\SaveCallShape;
use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Service\ComplianceRollupService;
use OCA\Learniq\Service\ExternalTrainingService;
use OCA\Learniq\Service\RegulationAudienceResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pins every ObjectService and coverage call ComplianceRollupService::byDepartment()
 * makes, and its roll-up, per evaluation day, against
 * tests/Unit/Service/golden/ComplianceRollup.json. With RECORD_GOLDEN=1 the
 * file is written instead of compared.
 *
 * @covers \OCA\Learniq\Service\ComplianceRollupService
 * @covers \OCA\Learniq\Service\ComplianceSnapshot
 * @uses   \OCA\Learniq\Service\RegulationAudienceResolver
 * @uses   \OCA\Learniq\Service\RunningExemptions
 */
class ComplianceRollupGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/ComplianceRollup.json';

	/**
	 * The stored rows, by schema.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private static function store(): array {
		return [
			'learner-profile' => [
				['id' => 'p-ann', 'ncUserId' => 'ann', 'department' => 'Ops/Infra/Team A', 'roles' => ['learner'], 'lifecycle' => 'active'],
				['uuid' => 'p-bob', 'ncUserId' => 'bob', 'department' => 'Ops/Stations', 'roles' => ['learner', 'manager']],
				['id' => 'p-cas', 'ncUserId' => '', 'department' => 'Finance', 'roles' => ['board'], 'lifecycle' => 'active'],
				['id' => 'p-dee', 'ncUserId' => 'dee', 'roles' => ['learner'], 'lifecycle' => 'active'],
				['id' => 'p-old', 'ncUserId' => 'old', 'department' => 'Ops/Infra', 'lifecycle' => 'merged'],
			],
			'regulation' => [
				['id' => 'r-1', 'slug' => 'VCA', 'audienceScope' => 'department', 'audienceDepartments' => ['Ops/Infra'], 'lifecycle' => 'published'],
				['id' => 'r-2', 'slug' => 'GDPR', 'lifecycle' => 'published'],
				['id' => 'r-3', 'slug' => 'BOARD', 'audienceScope' => 'board', 'audienceRoles' => ['board', 'manager'], 'lifecycle' => 'published'],
				['id' => 'r-4', 'slug' => 'OFF', 'lifecycle' => 'published', 'active' => false],
				['id' => 'r-5', 'slug' => 'DRAFT', 'lifecycle' => 'draft'],
			],
			'regulation-exemption' => [
				['learnerId' => 'bob', 'regulationSlug' => 'GDPR', 'validFrom' => '2026-01-01', 'validUntil' => '2026-06-30', 'lifecycle' => 'granted'],
				['learnerId' => 'p-cas', 'regulationSlug' => 'BOARD', 'validUntil' => '2026-12-31', 'lifecycle' => 'granted'],
				['learnerId' => 'ann', 'regulationSlug' => 'GDPR', 'validFrom' => '2026-04-01', 'validUntil' => '2026-04-30', 'lifecycle' => 'granted'],
			],
			'enrolment' => [
				['learnerId' => 'ann', 'mandatory' => true, 'lifecycle' => 'active', 'dueDate' => '2026-03-01'],
				['learnerRef' => 'p-ann', 'mandatory' => true, 'lifecycle' => 'pending', 'dueDate' => '2026-03-20'],
				['learnerId' => 'ann', 'mandatory' => true, 'lifecycle' => 'completed', 'dueDate' => '2026-01-01'],
				['learnerId' => 'bob', 'mandatory' => true, 'lifecycle' => 'active', 'dueDate' => '2026-05-01'],
				['learnerId' => 'bob', 'mandatory' => true, 'lifecycle' => 'active', 'dueDate' => 'soon'],
				['learnerId' => 'dee', 'mandatory' => true, 'lifecycle' => 'pending', 'dueDate' => '2026-04-14'],
				['learnerId' => 7, 'mandatory' => true, 'lifecycle' => 'active', 'dueDate' => '2026-01-01'],
				['learnerId' => 'dee', 'mandatory' => true, 'lifecycle' => 'active', 'dueDate' => '2026-04-14T08:00:00Z'],
				['learnerRef' => 'p-dee', 'mandatory' => true, 'lifecycle' => 'active', 'dueDate' => '2026-04-14T20:00:00Z'],
			],
			'credential' => [
				['learnerId' => 'p-cas', 'expiresAt' => '2026-01-01', 'lifecycle' => 'issued'],
				['learnerId' => 'ann', 'lifecycle' => 'expired'],
				['learnerRef' => 'bob', 'expiresAt' => '2020-01-01', 'lifecycle' => 'revoked'],
				['learnerId' => 'dee', 'expiresAt' => '2027-01-01', 'lifecycle' => 'issued'],
				['learnerId' => 'dee', 'expiresAt' => 'never', 'lifecycle' => 'issued'],
				['learnerId' => 'ann', 'expiresAt' => '2026-04-01', 'lifecycle' => 'issued'],
			],
		];
	}//end store()

	/**
	 * Every evaluation day's calls and roll-up equal the recorded golden.
	 *
	 * @return void
	 */
	public function testEveryDayMakesTheRecordedCalls(): void {
		$actual = [];
		foreach (['2026-03-15', '2026-07-01', '2027-02-01'] as $day) {
			$actual[$day] = $this->callsFor(new DateTimeImmutable($day . 'T08:00:00', new DateTimeZone('UTC')));
		}

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n";
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		$this->assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testEveryDayMakesTheRecordedCalls()

	/**
	 * Run byDepartment() on one day and return the calls it made and its roll-up.
	 *
	 * @param DateTimeImmutable $now The evaluation instant.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function callsFor(DateTimeImmutable $now): array {
		$calls   = [];
		$store   = self::store();
		$service = $this->createMock(ObjectService::class);
		$service->method('findAll')->willReturnCallback(
			static function (...$args) use ($store, &$calls): array {
				$calls[] = ['findAll' => self::named('findAll', $args)];
				$schema  = (string)$args[0]['filters']['schema'];
				$rows    = $store[$schema] ?? [];
				return array_map(static fn (array $row, int $i) => ($i % 2 === 0) ? $row : OrEntityFactory::make($row, $schema), $rows, array_keys($rows));
			}
		);
		$training = $this->createMock(ExternalTrainingService::class);
		$training->method('isLearnerCovered')->willReturnCallback(
			static function (string $learnerId, string $regulationSlug, DateTimeImmutable $now) use (&$calls): bool {
				$calls[] = ['isLearnerCovered' => [$learnerId, $regulationSlug, $now->format(DATE_ATOM)]];
				return in_array($learnerId . '/' . $regulationSlug, ['ann/VCA', 'p-bob/BOARD', 'dee/GDPR'], true);
			}
		);

		$calls[] = ['result' => (new ComplianceRollupService($service, new RegulationAudienceResolver(), $training))->byDepartment($now)];
		return $calls;
	}//end callsFor()

	/**
	 * A mock call's positional arguments keyed by the loaded ObjectService's parameter names.
	 *
	 * @param string      $method The ObjectService method.
	 * @param list<mixed> $args   The positional arguments.
	 *
	 * @return array<string, mixed>
	 */
	private static function named(string $method, array $args): array {
		if ($method === 'saveObject') {
			return SaveCallShape::named(args: $args);
		}

		$params = (new ReflectionMethod(ObjectService::class, $method))->getParameters();
		$named  = [];
		foreach ($args as $i => $value) {
			if ($params[$i]->isDefaultValueAvailable() === true && $params[$i]->getDefaultValue() === $value) {
				continue;
			}

			$named[$params[$i]->getName()] = $value;
		}

		return $named;
	}//end named()
}//end class
