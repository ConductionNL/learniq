<?php

/**
 * RegulationAssignmentService::assignPerson() and the job-title audience
 * (compliance-assign-from-hr-roles): one person checked against every
 * published regulation, enrolled where now covered, marked where no longer.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/compliance-assign-from-hr-roles/specs/compliance-rule-coverage/spec.md#requirement-a-change-of-department-roles-or-job-title-re-runs-the-assignment-for-that-person
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\RegulationAssignmentService;
use OCA\Learniq\Service\RegulationAudienceResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Learniq\Service\RegulationAssignmentService
 * @covers \OCA\Learniq\Service\RegulationAudienceResolver
 * @uses   \OCA\Learniq\Service\SchemaRows
 * @uses   \OCA\Learniq\Support\SerialisedRow
 */
class RegulationAssignPersonTest extends TestCase {

	private const TODAY = '2026-11-13';

	/** @var array<string, list<array<string, mixed>>> */
	private array $store = [];

	/** @var list<array{uuid: ?string, object: array<string, mixed>}> */
	private array $saved = [];

	/**
	 * Two published regulations (track work by department, first aid by job
	 * title), one draft, one inactive.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved = [];
		$this->store = [
			'regulation' => [
				['id' => 'r-spoor', 'slug' => 'SPOOR', 'audienceScope' => 'department', 'audienceDepartments' => ['Spooronderhoud'], 'lifecycle' => 'published', 'tenant_id' => 't1'],
				['id' => 'r-bhv', 'slug' => 'BHV', 'audienceScope' => 'job-title', 'audienceJobTitles' => ['Ploegleider'], 'lifecycle' => 'published', 'tenant_id' => 't1'],
				['id' => 'r-draft', 'slug' => 'DRAFT', 'audienceScope' => 'all-employees', 'lifecycle' => 'draft'],
				['id' => 'r-off', 'slug' => 'OFF', 'audienceScope' => 'all-employees', 'lifecycle' => 'published', 'active' => false],
			],
			'course' => [
				['id' => 'c-spoor', 'regulationSlug' => 'SPOOR', 'lifecycle' => 'published'],
				['id' => 'c-bhv', 'regulationSlug' => 'BHV', 'lifecycle' => 'published'],
				['id' => 'c-draft', 'regulationSlug' => 'DRAFT', 'lifecycle' => 'published'],
				['id' => 'c-off', 'regulationSlug' => 'OFF', 'lifecycle' => 'published'],
			],
			'enrolment' => [],
		];
	}//end setUp()

	/**
	 * The service over the in-memory store.
	 *
	 * @return RegulationAssignmentService
	 */
	private function service(): RegulationAssignmentService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$schema = (string)$config['filters']['schema'];
				$filters = array_diff_key($config['filters'], ['register' => true, 'schema' => true]);
				$hits = [];
				foreach (($this->store[$schema] ?? []) as $row) {
					$match = true;
					foreach ($filters as $field => $value) {
						$match = $match && (($row[$field] ?? null) === $value);
					}

					if ($match === true) {
						$hits[] = OrEntityFactory::make($row, $schema);
					}
				}

				return $hits;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend=[], $register=null, $schema=null, ?string $uuid=null): ObjectEntity {
				$this->saved[] = ['uuid' => $uuid, 'object' => (array)$object];
				$row = (array)$object;
				$row['id'] = ($uuid ?? ('new-' . count($this->saved)));
				return OrEntityFactory::make($row, (string)$schema);
			}
		);

		return new RegulationAssignmentService(objectService: $objects, audience: new RegulationAudienceResolver(), logger: new NullLogger());
	}//end service()

	/**
	 * A person in the department.
	 *
	 * @param string      $department The department.
	 * @param string|null $jobTitle   The job title.
	 *
	 * @return array<string, mixed>
	 */
	private static function person(string $department, ?string $jobTitle=null): array {
		return ['id' => 'p-anna', 'ncUserId' => 'anna', 'department' => $department, 'jobTitle' => $jobTitle, 'managerId' => 'boss', 'lifecycle' => 'active'];
	}//end person()

	/**
	 * A new colleague in the department is enrolled in its regulation's
	 * course only; drafts and inactive regulations are left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-assign-from-hr-roles/specs/compliance-rule-coverage/spec.md#scenario-a-new-colleague-joins-the-track-maintenance-department
	 */
	public function testANewColleagueIsEnrolledInHerDepartmentsCourse(): void {
		$result = $this->service()->assignPerson(profile: self::person(department: 'Spooronderhoud/Ploeg Noord'), today: self::TODAY);

		self::assertSame(['new-1'], $result['created']);
		self::assertSame([], $result['outOfScope']);
		self::assertCount(1, $this->saved);
		self::assertSame(
			['learnerId' => 'anna', 'learnerRef' => 'p-anna', 'courseId' => 'c-spoor', 'mandatory' => true, 'regulationSlug' => 'SPOOR', 'source' => 'system', 'managerId' => 'boss', 'tenant_id' => 't1'],
			$this->saved[0]['object']
		);
	}//end testANewColleagueIsEnrolledInHerDepartmentsCourse()

	/**
	 * A job title in a regulation's audience counts, without regard to case.
	 *
	 * @return void
	 */
	public function testAJobTitleBringsItsRegulation(): void {
		$result = $this->service()->assignPerson(profile: self::person(department: 'Facilitair', jobTitle: ' ploegleider '), today: self::TODAY);

		self::assertCount(1, $result['created']);
		self::assertSame('c-bhv', $this->saved[0]['object']['courseId']);
	}//end testAJobTitleBringsItsRegulation()

	/**
	 * A replayed feed creates nothing twice; an ended enrolment does not
	 * count as live, so the person is enrolled again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-assign-from-hr-roles/specs/compliance-rule-coverage/spec.md#scenario-a-replayed-feed-creates-no-duplicates
	 */
	public function testAReplayCreatesNoDuplicates(): void {
		$this->store['enrolment'] = [['id' => 'e-1', 'learnerId' => 'anna', 'courseId' => 'c-spoor', 'regulationSlug' => 'SPOOR', 'lifecycle' => 'active']];
		self::assertSame([], $this->service()->assignPerson(profile: self::person(department: 'Spooronderhoud'), today: self::TODAY)['created']);
		self::assertSame([], $this->saved);

		$this->store['enrolment'][0]['lifecycle'] = 'withdrawn';
		self::assertCount(1, $this->service()->assignPerson(profile: self::person(department: 'Spooronderhoud'), today: self::TODAY)['created']);
	}//end testAReplayCreatesNoDuplicates()

	/**
	 * Leaving the department marks the open enrolment, once, and never
	 * withdraws it; a completed enrolment is left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-assign-from-hr-roles/specs/compliance-rule-coverage/spec.md#scenario-someone-leaves-the-department
	 */
	public function testLeavingTheDepartmentMarksTheOpenEnrolment(): void {
		$this->store['enrolment'] = [
			['id' => 'e-open', 'learnerId' => 'anna', 'courseId' => 'c-spoor', 'regulationSlug' => 'SPOOR', 'lifecycle' => 'active'],
			['id' => 'e-done', 'learnerId' => 'anna', 'courseId' => 'c-spoor', 'regulationSlug' => 'SPOOR', 'lifecycle' => 'completed'],
		];

		$result = $this->service()->assignPerson(profile: self::person(department: 'Facilitair'), today: self::TODAY);

		self::assertSame(['e-open'], $result['outOfScope']);
		self::assertSame('e-open', $this->saved[0]['uuid']);
		self::assertSame(self::TODAY, $this->saved[0]['object']['outOfScopeSince']);
		self::assertSame('active', $this->saved[0]['object']['lifecycle'], 'never withdrawn');
		self::assertArrayNotHasKey('id', $this->saved[0]['object']);

		$this->saved = [];
		$this->store['enrolment'][0]['outOfScopeSince'] = self::TODAY;
		self::assertSame([], $this->service()->assignPerson(profile: self::person(department: 'Facilitair'), today: '2026-11-14')['outOfScope'], 'marked once');
		self::assertSame([], $this->saved);
	}//end testLeavingTheDepartmentMarksTheOpenEnrolment()

	/**
	 * A profile without a Nextcloud account is nobody to enrol.
	 *
	 * @return void
	 */
	public function testAProfileWithoutAnAccountIsSkipped(): void {
		$profile = self::person(department: 'Spooronderhoud');
		unset($profile['ncUserId']);

		self::assertSame(['created' => [], 'outOfScope' => []], $this->service()->assignPerson(profile: $profile, today: self::TODAY));
		self::assertSame([], $this->saved);
	}//end testAProfileWithoutAnAccountIsSkipped()

	/**
	 * The job-title scope reads audienceJobTitles only, and an empty title covers nothing.
	 *
	 * @return void
	 */
	public function testTheJobTitleScope(): void {
		$resolver = new RegulationAudienceResolver();
		$regulation = ['audienceScope' => 'job-title', 'audienceJobTitles' => ['Ploegleider', 'Machinist']];

		self::assertTrue($resolver->covers(regulation: $regulation, profile: ['jobTitle' => 'MACHINIST']));
		self::assertFalse($resolver->covers(regulation: $regulation, profile: ['jobTitle' => 'Monteur']));
		self::assertFalse($resolver->covers(regulation: $regulation, profile: ['jobTitle' => '']));
		self::assertFalse($resolver->covers(regulation: $regulation, profile: []));
		self::assertFalse($resolver->covers(regulation: ['audienceScope' => 'job-title'], profile: ['jobTitle' => 'Machinist']), 'an empty list covers nobody');
	}//end testTheJobTitleScope()
}//end class
