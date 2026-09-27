<?php

/**
 * Learniq department compliance unit tests.
 *
 * Covers learniq#951: LearnerProfile.department and Regulation.audienceScope
 * were stored and never read. A regulation scoped to a department must cover
 * only that department's learners, both when it is assigned and when coverage
 * is rolled up, and compliance must roll up from team to department to
 * directorate.
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

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Listener\RegulationAssignmentHandler;
use OCA\Learniq\Service\ComplianceRollupService;
use OCA\Learniq\Service\ExternalTrainingService;
use OCA\Learniq\Service\RegulationAssignmentService;
use OCA\Learniq\Service\RegulationAudienceResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests audience scoping, the department roll-up and audience-scoped assignment.
 */
class DepartmentComplianceTest extends TestCase {

	/**
	 * In-memory store keyed by schema slug.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Recorded saveObject() calls.
	 *
	 * @var array<int, array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * Two learners in different teams of one directorate, one in another
	 * directorate, and a regulation scoped to the Infra department.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved = [];
		$this->store = [
			'learner-profile' => [
				['id' => 'p-ann', 'ncUserId' => 'ann', 'department' => 'Operations/Infra/Team A', 'roles' => ['learner'], 'lifecycle' => 'active'],
				['id' => 'p-bob', 'ncUserId' => 'bob', 'department' => 'Operations/Stations', 'roles' => ['learner', 'manager'], 'lifecycle' => 'active'],
				['id' => 'p-cas', 'ncUserId' => 'cas', 'department' => 'Finance', 'roles' => ['learner'], 'lifecycle' => 'active'],
				['id' => 'p-old', 'ncUserId' => 'old', 'department' => 'Operations/Infra/Team A', 'roles' => ['learner'], 'lifecycle' => 'merged'],
			],
			'regulation' => [
				[
					'id' => 'reg-vca',
					'slug' => 'VCA',
					'audienceScope' => 'department',
					'audienceDepartments' => ['Operations/Infra'],
					'lifecycle' => 'published',
					'tenant_id' => 't1',
				],
			],
			'course' => [
				['id' => 'course-vca', 'regulationSlug' => 'VCA', 'lifecycle' => 'published'],
			],
			'enrolment' => [
				['id' => 'e1', 'learnerId' => 'ann', 'courseId' => 'course-x', 'mandatory' => true, 'lifecycle' => 'active', 'dueDate' => '2026-10-10'],
				['id' => 'e2', 'learnerId' => 'bob', 'courseId' => 'course-x', 'mandatory' => true, 'lifecycle' => 'pending', 'dueDate' => '2026-09-01'],
			],
			'credential' => [
				['id' => 'c1', 'learnerId' => 'cas', 'expiresAt' => '2026-01-01', 'lifecycle' => 'issued'],
			],
		];

	}//end setUp()

	/**
	 * An ObjectService double over the store whose findAll() honours `filters`.
	 *
	 * @return ObjectService
	 */
	private function makeObjectService(): ObjectService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				$hits = [];
				foreach ($this->store[(string)($config['filters']['schema'] ?? '')] ?? [] as $row) {
					$match = true;
					foreach (array_diff_key(($config['filters'] ?? []), ['register' => true, 'schema' => true]) as $field => $value) {
						if (($row[$field] ?? null) !== $value) {
							$match = false;
						}
					}

					if ($match === true) {
						$hits[] = OrEntityFactory::make($row, (string)$config['filters']['schema']);
					}
				}

				return $hits;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null): ObjectEntity {
				$data = ($object instanceof ObjectEntity) ? $object->jsonSerialize() : $object;
				$this->saved[] = ['schema' => (string)$schema, 'object' => $data];
				return OrEntityFactory::make($data, (string)$schema);
			}
		);

		return $objectService;
	}//end makeObjectService()

	/**
	 * Build the roll-up service; `$coveredKeys` are the learner keys that count as covered.
	 *
	 * @param array<int,string> $coveredKeys Learner keys the coverage predicate accepts.
	 *
	 * @return ComplianceRollupService
	 */
	private function makeRollup(array $coveredKeys): ComplianceRollupService {
		$training = $this->createMock(ExternalTrainingService::class);
		$training->method('isLearnerCovered')->willReturnCallback(
			static fn (string $learnerId): bool => in_array($learnerId, $coveredKeys, true)
		);

		return new ComplianceRollupService($this->makeObjectService(), new RegulationAudienceResolver(), $training);
	}//end makeRollup()

	/**
	 * A department-scoped regulation covers the department and its teams only;
	 * an empty scope list covers nobody; role scopes match on roles.
	 *
	 * @return void
	 *
	 * @spec openspec/parity/capabilities.json#comp-assign-from-hr-roles
	 */
	public function testAudienceScopeIsHonoured(): void {
		$resolver = new RegulationAudienceResolver();
		$regulation = $this->store['regulation'][0];
		[$ann, $bob, $cas, $old] = $this->store['learner-profile'];

		$this->assertTrue($resolver->covers($regulation, $ann));
		$this->assertFalse($resolver->covers($regulation, $bob));
		$this->assertFalse($resolver->covers($regulation, $cas));
		$this->assertFalse($resolver->covers($regulation, $old), 'a merged profile is covered by nothing');

		$this->assertFalse($resolver->covers(['audienceScope' => 'department', 'audienceDepartments' => []], $ann));
		$this->assertFalse($resolver->covers(['audienceScope' => 'department', 'audienceDepartments' => ['Operations/Inf']], $ann));
		$this->assertTrue($resolver->covers(['audienceScope' => 'role-specific', 'audienceRoles' => ['manager']], $bob));
		$this->assertFalse($resolver->covers(['audienceScope' => 'role-specific', 'audienceRoles' => ['manager']], $ann));
		$this->assertTrue($resolver->covers(['audienceScope' => 'all-employees'], $cas));

	}//end testAudienceScopeIsHonoured()

	/**
	 * Compliance rolls up from team to department to directorate, counting a
	 * regulation's obligations only for the learners it covers.
	 *
	 * @return void
	 *
	 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
	 */
	public function testComplianceRollsUpPerDepartment(): void {
		$rows = $this->makeRollup(coveredKeys: [])->byDepartment(new DateTimeImmutable('2026-09-27', new DateTimeZone('UTC')));
		$byDepartment = array_column($rows, null, 'department');

		$this->assertSame(
			['Finance', 'Operations', 'Operations/Infra', 'Operations/Infra/Team A', 'Operations/Stations'],
			array_keys($byDepartment)
		);

		// Only Ann is in the VCA audience: one obligation, uncovered, 0%.
		$this->assertSame(1, $byDepartment['Operations/Infra/Team A']['obligations']);
		$this->assertSame(0.0, $byDepartment['Operations/Infra/Team A']['coveragePercent']);
		$this->assertSame(0, $byDepartment['Operations/Stations']['obligations']);
		$this->assertNull($byDepartment['Operations/Stations']['coveragePercent']);

		// The directorate aggregates both teams.
		$this->assertSame(2, $byDepartment['Operations']['learners']);
		$this->assertSame(1, $byDepartment['Operations']['obligations']);
		$this->assertSame(1, $byDepartment['Operations']['upcomingDeadlines']);
		$this->assertSame(1, $byDepartment['Operations']['overdue']);
		$this->assertSame('Operations/Infra', $byDepartment['Operations/Infra/Team A']['parent']);
		$this->assertSame(2, $byDepartment['Operations/Infra/Team A']['depth']);

		$this->assertSame(1, $byDepartment['Finance']['expiredCredentials']);
		$this->assertSame(0, $byDepartment['Finance']['obligations']);

	}//end testComplianceRollsUpPerDepartment()

	/**
	 * A covered learner lifts coverage at every level above them.
	 *
	 * @return void
	 *
	 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
	 */
	public function testCoverageCountsTheCoveredLearner(): void {
		$rows = $this->makeRollup(coveredKeys: ['ann'])->byDepartment(new DateTimeImmutable('2026-09-27', new DateTimeZone('UTC')));
		$byDepartment = array_column($rows, null, 'department');

		$this->assertSame(100.0, $byDepartment['Operations']['coveragePercent']);
		$this->assertSame(1, $byDepartment['Operations/Infra']['covered']);

	}//end testCoverageCountsTheCoveredLearner()

	/**
	 * Publishing a department-scoped regulation enrols only that department's
	 * learners in its mandatory course, and never twice.
	 *
	 * @return void
	 *
	 * @spec openspec/parity/capabilities.json#comp-assign-from-hr-roles
	 */
	public function testPublishAssignsOnlyTheAudience(): void {
		$service = new RegulationAssignmentService($this->makeObjectService(), new RegulationAudienceResolver(), new NullLogger());
		$handler = new RegulationAssignmentHandler($service);

		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn(OrEntityFactory::make($this->store['regulation'][0], 'regulation'));
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('regulation');
		$event->method('getTo')->willReturn('published');

		$handler->handle($event);

		$this->assertCount(1, $this->saved);
		$enrolment = $this->saved[0]['object'];
		$this->assertSame('enrolment', $this->saved[0]['schema']);
		$this->assertSame('ann', $enrolment['learnerId']);
		$this->assertSame('p-ann', $enrolment['learnerRef']);
		$this->assertSame('course-vca', $enrolment['courseId']);
		$this->assertTrue($enrolment['mandatory']);
		$this->assertSame('VCA', $enrolment['regulationSlug']);

		// A second run finds Ann already enrolled and creates nothing.
		$this->store['enrolment'][] = $enrolment + ['lifecycle' => 'pending'];
		$result = $service->assign($this->store['regulation'][0]);
		$this->assertSame(0, $result['created']);
		$this->assertSame(1, $result['skipped']);

	}//end testPublishAssignsOnlyTheAudience()

	/**
	 * The register carries the audience lists the scope needs.
	 *
	 * @return void
	 *
	 * @spec openspec/parity/capabilities.json#comp-assign-from-hr-roles
	 */
	public function testRegisterDeclaresTheAudienceLists(): void {
		$register = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/learniq_register.json'), true);
		$properties = $register['components']['schemas']['Regulation']['properties'];

		$this->assertSame('array', $properties['audienceDepartments']['type'] ?? null);
		$this->assertSame('array', $properties['audienceRoles']['type'] ?? null);

	}//end testRegisterDeclaresTheAudienceLists()
}//end class
