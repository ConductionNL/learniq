<?php

/**
 * Unit tests for ClassRosterSync and the `class-roster` landing, through
 * integriq's ExchangeRecordsReceivedEvent.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use DateTime;
use OCA\Integriq\Event\ExchangeRecordsReceivedEvent;
use OCA\Learniq\Kernel\Contracts\ExchangeImportLandingInterface;
use OCA\Learniq\Listener\ExchangeImportLandingListener;
use OCA\Learniq\Service\ClassRosterSync;
use OCA\Learniq\Service\ExchangeImportLanding;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A class lands as a cohort, joiners are enrolled, leavers withdrawn, and a
 * replay changes nothing.
 *
 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/specs/enrolment/spec.md#requirement-classes-from-the-student-administration-land-as-cohorts
 */
class ClassRosterSyncTest extends TestCase {

	private const TENANT = '00000000-0000-4000-8000-000000000009';

	private const COURSE = '00000000-0000-4000-8000-0000000000c1';

	/**
	 * In-memory rows by schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $db = [];

	/**
	 * Writes made, as [schema, row, rbac].
	 *
	 * @var array<int, array{schema: string, row: array<string, mixed>, uuid: string|null, rbac: bool}>
	 */
	private array $writes = [];

	/**
	 * The object service over the in-memory store.
	 *
	 * @return ObjectService
	 */
	private function objects(): ObjectService {
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$filters = $config['filters'];
				$schema = $filters['schema'];
				unset($filters['schema'], $filters['register']);
				$rows = array_values(
					array_filter(
						$this->db[$schema] ?? [],
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

				return OrEntityFactory::makeMany(array_slice($rows, 0, (int)($config['limit'] ?? 20)), $schema);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options, bool $_rbac = true) {
				$schema = $options->target->schema;
				$uuid   = $options->uuid;
				$this->writes[] = ['schema' => (string)$schema, 'row' => $object, 'uuid' => $uuid, 'rbac' => $_rbac];
				$object['id'] = $uuid ?? sprintf('00000000-0000-4000-8000-%012d', count($this->writes));
				$this->db[(string)$schema] = array_values(
					array_filter($this->db[(string)$schema] ?? [], static fn (array $r): bool => ($r['id'] ?? null) !== $object['id'])
				);
				$this->db[(string)$schema][] = $object;
				return OrEntityFactory::make($object, (string)$schema);
			}
		);

		return $objects;
	}//end objects()

	/**
	 * The time the sync stamps.
	 *
	 * @return ITimeFactory
	 */
	private function time(): ITimeFactory {
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-02T07:00:00+00:00'));

		return $time;
	}//end time()

	/**
	 * Land class-roster records through the listener, as integriq's job does.
	 *
	 * @param array<int, array<string, mixed>> $records The records.
	 *
	 * @return ExchangeRecordsReceivedEvent
	 */
	private function receive(array $records): ExchangeRecordsReceivedEvent {
		$event = new ExchangeRecordsReceivedEvent('job-1', 'learniq', 'class-roster', 'import', '', ['tenantId' => self::TENANT], $records);
		$landing = new ExchangeImportLanding(objectService: $this->objects(), time: $this->time());
		(new ExchangeImportLandingListener(landing: $landing, logger: new NullLogger()))->handle($event);

		return $event;
	}//end receive()

	/**
	 * A class record.
	 *
	 * @param array<int, string>   $learners The members.
	 * @param array<string, mixed> $extra    Fields to add or override.
	 *
	 * @return array<string, mixed>
	 */
	private static function klass(array $learners, array $extra = []): array {
		return [
			'recordId'   => 'c1',
			'sourceKind' => 'class',
			'data'       => array_merge(
				[
					'externalId'     => 'mag-3hvb-2026',
					'externalSource' => 'magister',
					'name'           => '3HV-B',
					'academicYear'   => '2026-2027',
					'programmeYear'  => 3,
					'learnerIds'     => $learners,
					'teacherIds'     => ['t.mentor'],
				],
				$extra
			),
		];
	}//end klass()

	/**
	 * Learner profiles for the given user ids, each with an ECK iD of `eck-<id>`.
	 *
	 * @param array<int, string> $ids The user ids.
	 *
	 * @return void
	 */
	private function pupils(array $ids): void {
		foreach ($ids as $id) {
			$this->db['learner-profile'][] = ['id' => 'p-'.$id, 'ncUserId' => $id, 'eckId' => 'eck-'.$id];
		}
	}//end pupils()

	/**
	 * The writes to one schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function writesTo(string $schema): array {
		return array_values(
			array_map(static fn (array $w): array => $w['row'], array_filter($this->writes, static fn (array $w): bool => $w['schema'] === $schema))
		);
	}//end writesTo()

	/**
	 * The landing answers the new kind.
	 *
	 * @return void
	 */
	public function testClassRosterIsALandedTarget(): void {
		self::assertContains(needle: 'class-roster', haystack: ExchangeImportLandingInterface::TARGETS);
		self::assertTrue(condition: $this->receive(records: [])->isAnswered());
	}//end testClassRosterIsALandedTarget()

	/**
	 * A new class arrives: one cohort with its learners, teachers, year and source, synced now.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/specs/enrolment/spec.md#scenario-a-new-class-arrives
	 */
	public function testANewClassArrives(): void {
		$this->pupils(ids: ['anna', 'bram']);

		$event = $this->receive(records: [self::klass(learners: ['anna', 'eck-bram'])]);

		self::assertSame(expected: 1, actual: $event->getAcceptedCount());
		self::assertSame(expected: [], actual: $event->getRejected());
		$cohorts = $this->writesTo(schema: 'cohort');
		self::assertCount(expectedCount: 1, haystack: $cohorts);
		self::assertSame(
			expected: [
				'lifecycle'      => 'planned',
				'period'         => '2026-2027',
				'externalId'     => 'mag-3hvb-2026',
				'externalSource' => 'magister',
				'name'           => '3HV-B',
				'academicYear'   => '2026-2027',
				'learnerIds'     => ['anna', 'bram'],
				'teacherIds'     => ['t.mentor'],
				'programmeYear'  => 3,
				'tenant_id'      => self::TENANT,
				'syncedAt'       => '2026-11-02T07:00:00+00:00',
				'syncChanges'    => 2,
			],
			actual: $cohorts[0]
		);
		self::assertSame(expected: [], actual: $this->writesTo(schema: 'enrolment'), message: 'a new class runs no course yet');
		self::assertFalse(condition: $this->writes[0]['rbac'], message: 'a background job has no session: the write is system-level');
	}//end testANewClassArrives()

	/**
	 * A pupil learniq does not know, or a record without its external id, is refused by code and field name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/specs/enrolment/spec.md#scenario-a-pupil-learniq-does-not-know
	 */
	public function testRefusalsNameTheFieldNotTheValue(): void {
		$this->pupils(ids: ['anna']);

		$event = $this->receive(
			records: [
				self::klass(learners: ['anna', 'eck-stranger']),
				['recordId' => 'c2', 'data' => ['externalSource' => 'magister', 'name' => '3HV-C', 'academicYear' => '2026-2027']],
				[
					'recordId' => 'c3',
					'data' => [
						'externalId' => 'x',
						'externalSource' => 'magister',
						'name' => '3HV-D',
						'academicYear' => '2026-2027',
						'learnerIds' => 'anna'
					]
				],
			]
		);

		self::assertSame(expected: 0, actual: $event->getAcceptedCount());
		self::assertSame(
			expected: [
				['recordId' => 'c1', 'errorCode' => 'ROSTER-UNKNOWN-PUPIL', 'offendingFields' => ['learnerIds']],
				['recordId' => 'c2', 'errorCode' => 'ROSTER-MISSING-FIELD', 'offendingFields' => ['externalId']],
				['recordId' => 'c3', 'errorCode' => 'ROSTER-MISSING-FIELD', 'offendingFields' => ['learnerIds']],
			],
			actual: array_map(
				static fn (array $r): array => [
					'recordId' => $r['recordId'],
					'errorCode' => $r['errorCode'],
					'offendingFields' => $r['offendingFields']
				],
				$event->getRejected()
			)
		);
		self::assertSame(expected: [], actual: $this->writes);
		self::assertStringNotContainsString(needle: 'stranger', haystack: (string)json_encode($event->getRejected()));
	}//end testRefusalsNameTheFieldNotTheValue()

	/**
	 * A pupil who joins in November gets an active roster-sync enrolment for the class's course.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/specs/enrolment/spec.md#scenario-a-pupil-joins-in-november
	 */
	public function testAPupilJoinsInNovember(): void {
		$this->pupils(ids: ['anna', 'daan']);
		$this->db['cohort'] = [
			[
				'id' => 'cohort-1',
				'externalId' => 'mag-3hvb-2026',
				'externalSource' => 'magister',
				'name' => '3HV-B',
				'learnerIds' => ['anna'],
				'notes' => 'kept',
				'tenant_id' => self::TENANT,
				'lifecycle' => 'active',
				'period' => 'Jaar'
			]
		];
		$this->db['enrolment'] = [
			[
				'id' => 'e-anna',
				'learnerId' => 'anna',
				'courseId' => self::COURSE,
				'cohortId' => 'cohort-1',
				'lifecycle' => 'active',
				'source' => 'bulk'
			]
		];

		$this->receive(records: [self::klass(learners: ['anna', 'daan'])]);

		$cohort = $this->writesTo(schema: 'cohort')[0];
		self::assertSame(expected: 'cohort-1', actual: $this->writes[0]['uuid'], message: 'matched on the external id, updated in place');
		self::assertArrayNotHasKey(key: '@self', array: $cohort);
		self::assertSame(
			expected: ['kept', 'active', 'Jaar', 1],
			actual: [$cohort['notes'], $cohort['lifecycle'], $cohort['period'], $cohort['syncChanges']]
		);
		self::assertSame(
			expected: [
				[
					'learnerId' => 'daan',
					'courseId' => self::COURSE,
					'cohortId' => 'cohort-1',
					'source' => 'roster-sync',
					'lifecycle' => 'active',
					'tenant_id' => self::TENANT
				]
			],
			actual: $this->writesTo(schema: 'enrolment')
		);
	}//end testAPupilJoinsInNovember()

	/**
	 * A pupil who moves class keeps their enrolment, withdrawn with the reason; a closed one is left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrolment-sync-classes-from-student-administration/specs/enrolment/spec.md#scenario-a-pupil-moves-to-another-class
	 */
	public function testAPupilMovesToAnotherClass(): void {
		$this->pupils(ids: ['anna', 'yara']);
		$this->db['cohort'] = [
			[
				'id' => 'cohort-1',
				'externalId' => 'mag-3hvb-2026',
				'externalSource' => 'magister',
				'learnerIds' => ['anna', 'yara'],
				'tenant_id' => self::TENANT
			]
		];
		$this->db['enrolment'] = [
			[
				'id' => 'e-yara',
				'learnerId' => 'yara',
				'courseId' => self::COURSE,
				'cohortId' => 'cohort-1',
				'lifecycle' => 'active',
				'source' => 'bulk',
				'progressPercent' => 40
			],
			[
				'id' => 'e-yara-old',
				'learnerId' => 'yara',
				'courseId' => 'old',
				'cohortId' => 'cohort-1',
				'lifecycle' => 'completed',
				'source' => 'bulk'
			],
			[
				'id' => 'e-anna',
				'learnerId' => 'anna',
				'courseId' => self::COURSE,
				'cohortId' => 'cohort-1',
				'lifecycle' => 'active',
				'source' => 'bulk'
			],
		];

		$this->receive(records: [self::klass(learners: ['anna'])]);

		self::assertSame(
			expected: [
				[
					'learnerId' => 'yara',
					'courseId' => self::COURSE,
					'cohortId' => 'cohort-1',
					'lifecycle' => 'withdrawn',
					'source' => 'bulk',
					'progressPercent' => 40,
					'reason' => ClassRosterSync::LEAVER_REASON
				]
			],
			actual: $this->writesTo(schema: 'enrolment')
		);
		self::assertSame(expected: 'Left the class in the student administration', actual: ClassRosterSync::LEAVER_REASON);
		self::assertCount(expectedCount: 3, haystack: $this->db['enrolment'], message: 'nothing is deleted');
		self::assertSame(
			expected: ['cohort-1', 'e-yara'],
			actual: array_column($this->writes, 'uuid'),
			message: 'the cohort and the enrolment are updated in place'
		);
		self::assertSame(expected: ['anna'], actual: $this->writesTo(schema: 'cohort')[0]['learnerIds']);
		self::assertSame(expected: 1, actual: $this->writesTo(schema: 'cohort')[0]['syncChanges']);
	}//end testAPupilMovesToAnotherClass()

	/**
	 * The same class delivered again changes no membership and writes no enrolment.
	 *
	 * @return void
	 */
	public function testAReplayChangesNothing(): void {
		$this->pupils(ids: ['anna', 'bram']);
		$this->db['enrolment'] = [];

		$this->receive(records: [self::klass(learners: ['anna', 'bram'])]);
		$cohortId = $this->db['cohort'][0]['id'];
		$this->db['enrolment'][] = [
			'id' => 'e1',
			'learnerId' => 'anna',
			'courseId' => self::COURSE,
			'cohortId' => $cohortId,
			'lifecycle' => 'active',
			'source' => 'bulk'
		];
		$this->db['enrolment'][] = [
			'id' => 'e2',
			'learnerId' => 'bram',
			'courseId' => self::COURSE,
			'cohortId' => $cohortId,
			'lifecycle' => 'active',
			'source' => 'bulk'
		];
		$this->writes = [];

		$event = $this->receive(records: [self::klass(learners: ['bram', 'anna'])]);

		self::assertSame(expected: 1, actual: $event->getAcceptedCount());
		self::assertCount(expectedCount: 1, haystack: $this->db['cohort'], message: 'not a second cohort');
		self::assertSame(expected: $cohortId, actual: $this->writes[0]['uuid']);
		self::assertSame(expected: [], actual: $this->writesTo(schema: 'enrolment'));
		$cohort = $this->writesTo(schema: 'cohort')[0];
		self::assertSame(expected: 0, actual: $cohort['syncChanges']);
		self::assertSame(expected: ['bram', 'anna'], actual: $cohort['learnerIds']);
	}//end testAReplayChangesNothing()

	/**
	 * A joiner who already holds an open enrolment for the course is not enrolled twice; a withdrawn one is re-enrolled.
	 *
	 * @return void
	 */
	public function testAJoinerIsEnrolledOncePerCourse(): void {
		$this->pupils(ids: ['anna', 'daan', 'eva']);
		$this->db['cohort'] = [
			[
				'id' => 'cohort-1',
				'externalId' => 'mag-3hvb-2026',
				'externalSource' => 'magister',
				'learnerIds' => ['anna'],
				'courseId' => 'course-own',
				'tenant_id' => self::TENANT
			]
		];
		$this->db['enrolment'] = [
			['id' => 'e-anna', 'learnerId' => 'anna', 'courseId' => self::COURSE, 'cohortId' => 'cohort-1', 'lifecycle' => 'active'],
			['id' => 'e-daan', 'learnerId' => 'daan', 'courseId' => self::COURSE, 'cohortId' => 'cohort-1', 'lifecycle' => 'pending'],
			['id' => 'e-eva', 'learnerId' => 'eva', 'courseId' => self::COURSE, 'cohortId' => 'cohort-1', 'lifecycle' => 'withdrawn'],
			['id' => 'e-gone', 'learnerId' => 'anna', 'courseId' => 'course-dropped', 'cohortId' => 'cohort-1', 'lifecycle' => 'withdrawn'],
		];

		$this->receive(records: [self::klass(learners: ['anna', 'daan', 'eva'])]);

		$made = array_map(static fn (array $row): string => $row['learnerId'].'@'.$row['courseId'], $this->writesTo(schema: 'enrolment'));
		self::assertSame(expected: ['daan@course-own', 'eva@course-own', 'eva@'.self::COURSE], actual: $made);
	}//end testAJoinerIsEnrolledOncePerCourse()

	/**
	 * What the sync writes passes the shipped Cohort and Enrolment schemas.
	 *
	 * @return void
	 */
	public function testTheWrittenPayloadsPassTheRealSchema(): void {
		$this->pupils(ids: ['anna', 'daan', 'yara']);
		$cohortId = '00000000-0000-4000-8000-0000000000aa';
		$this->db['cohort'] = [
			[
				'id' => $cohortId,
				'externalId' => 'mag-3hvb-2026',
				'externalSource' => 'magister',
				'name' => '3HV-B',
				'period' => '2026-2027',
				'academicYear' => '2026-2027',
				'learnerIds' => ['anna', 'yara'],
				'tenant_id' => self::TENANT,
				'lifecycle' => 'active'
			]
		];
		$this->db['enrolment'] = [
			[
				'id' => 'e-anna',
				'learnerId' => 'anna',
				'courseId' => self::COURSE,
				'cohortId' => $cohortId,
				'lifecycle' => 'active',
				'source' => 'bulk',
				'tenant_id' => self::TENANT
			],
			[
				'id' => 'e-yara',
				'learnerId' => 'yara',
				'courseId' => self::COURSE,
				'cohortId' => $cohortId,
				'lifecycle' => 'active',
				'source' => 'bulk',
				'tenant_id' => self::TENANT
			],
		];

		$this->receive(records: [self::klass(learners: ['anna', 'daan'])]);

		$schemas = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/learniq_register.json'), true)['components']['schemas'];
		$validator = new Validator();
		$checked = 0;
		foreach ($this->writes as $write) {
			$name = ['cohort' => 'Cohort', 'enrolment' => 'Enrolment'][$write['schema']];
			$row = $write['row'];
			unset($row['id']);
			$result = $validator->validate(json_decode((string)json_encode($row)), (string)json_encode($this->validatable(schema: $schemas[$name])));
			self::assertTrue(condition: $result->isValid(), message: $name.': '.json_encode($result->error()?->message()));
			$checked++;
		}

		self::assertSame(expected: 3, actual: $checked, message: 'the cohort, a withdrawal and a new enrolment');
		self::assertContains(needle: ClassRosterSync::ENROLMENT_SOURCE, haystack: $schemas['Enrolment']['properties']['source']['enum']);
		foreach (['externalId', 'externalSource', 'syncedAt', 'syncChanges'] as $field) {
			self::assertArrayHasKey(key: $field, array: $schemas['Cohort']['properties']);
		}
	}//end testTheWrittenPayloadsPassTheRealSchema()

	/**
	 * The schema without OpenRegister's own keys, which a JSON Schema validator cannot resolve.
	 *
	 * @param array<string, mixed> $schema A register schema.
	 *
	 * @return array<string, mixed>
	 */
	private function validatable(array $schema): array {
		$clean = [];
		foreach ($schema as $key => $value) {
			if (
				$key === '$ref' || $key === 'authorization' || str_starts_with((string)$key, 'x-') === true || in_array(
					$key,
					['slug', 'icon', 'version', 'linkedTypes'],
					true
				) === true
			) {
				continue;
			}

			$clean[$key] = $value;
			if (is_array($value) === true && $key !== 'required' && $key !== 'enum') {
				$clean[$key] = $this->validatable(schema: $value);
			}
		}

		return $clean;
	}//end validatable()
}//end class
