<?php

/**
 * Learniq AttendanceSummaryService unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Attendance
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
 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-the-summary-follows-every-attendance-write
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Attendance;

use OCA\Learniq\Service\Attendance\AttendanceSummaryCalculator;
use OCA\Learniq\Service\Attendance\AttendanceSummaryService;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\PupilGroupTeachers;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Recounting one learner's summaries.
 */
class AttendanceSummaryServiceTest extends TestCase {
	use RegisterSchemaPayloads;

	private const TENANT = '00000000-0000-4000-8000-000000000000';
	private const PROFILE = '11111111-1111-4111-8111-111111111111';

	/** @var array<int, array<string, mixed>> */
	private array $records = [];

	/** @var array<int, array<string, mixed>> */
	private array $sessions = [];

	/** @var array<int, array<string, mixed>> */
	private array $summaries = [];

	/** @var array<int, array<string, mixed>> */
	private array $saved = [];

	/** @var array<int, array<string, mixed>> */
	private array $recordQueries = [];

	/** @var array<int, string>|null */
	private ?array $teachers = ['juf-7'];

	/**
	 * Reset the fakes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->records = [];
		$this->sessions = [
			['id' => 's-mon', 'startsAt' => '2026-03-02T08:30:00+01:00', 'endsAt' => '2026-03-02T14:15:00+01:00'],
			['id' => 's-tue', 'startsAt' => '2026-03-03T08:30:00+01:00', 'endsAt' => '2026-03-03T14:15:00+01:00'],
			['id' => 's-sep', 'startsAt' => '2026-09-14T08:30:00+02:00', 'endsAt' => '2026-09-14T14:15:00+02:00'],
		];
		$this->summaries = [];
		$this->saved = [];
		$this->recordQueries = [];
		$this->teachers = ['juf-7'];
	}//end setUp()

	/**
	 * The service with faked OpenRegister reads and writes.
	 *
	 * @return AttendanceSummaryService
	 */
	private function service(): AttendanceSummaryService {
		/** @var ObjectService&MockObject $objects */
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config=[], bool $_rbac=true, bool $_multitenancy=true): array {
				self::assertFalse($_rbac, 'a recount reads without RBAC');
				$filters = $config['filters'];
				if ($filters['schema'] === 'attendance-record') {
					$this->recordQueries[] = $config;
					return array_values(array_filter($this->records, static fn (array $r): bool => $r['learnerId'] === $filters['learnerId']));
				}

				if ($filters['schema'] === 'session') {
					$ids = ($config['ids'] ?? []);
					return array_map(
						static fn (array $s): ObjectEntity => OrEntityFactory::make($s, 'session'),
						array_values(array_filter($this->sessions, static fn (array $s): bool => in_array($s['id'], $ids, true)))
					);
				}

				if ($filters['schema'] === 'attendance-summary') {
					return array_values(array_filter($this->summaries, static fn (array $s): bool => $s['learnerId'] === $filters['learnerId']));
				}

				return [];
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			// The double hands the arguments over by POSITION, and OpenRegister
			// grows saveObject's list (development added `_validation` at
			// position 9). Name them from the loaded class's own signature, so
			// the test reads the same call on the stub and on OpenRegister.
			function (mixed ...$args): ObjectEntity {
				$named = self::saveObjectArguments($args);
				$this->saved[] = [
					'object'  => $named['object'],
					'schema'  => ($named['schema'] ?? null),
					'uuid'    => ($named['uuid'] ?? null),
					'rbac'    => ($named['_rbac'] ?? true),
					'unowned' => ($named['_unowned'] ?? false),
				];
				return OrEntityFactory::make((array)$named['object'], (string)($named['schema'] ?? ''));
			}
		);

		$teachers = $this->createMock(PupilGroupTeachers::class);
		$teachers->method('forLearner')->willReturnCallback(
			function (): array {
				if ($this->teachers === null) {
					throw new RuntimeException('cohorts unreadable');
				}

				return $this->teachers;
			}
		);

		$profiles = $this->createMock(LearnerRefResolver::class);
		$profiles->method('resolveAcrossTenants')->willReturn(self::PROFILE);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);

		return new AttendanceSummaryService(
			objectService: $objects,
			calculator: new AttendanceSummaryCalculator(),
			groupTeachers: $teachers,
			profiles: $profiles,
			time: $time,
			logger: new NullLogger()
		);
	}//end service()

	/**
	 * A record of pupil-1.
	 *
	 * @param string               $sessionId Session uuid.
	 * @param string               $status    Status.
	 * @param array<string, mixed> $extra     More fields.
	 *
	 * @return array<string, mixed>
	 */
	/**
	 * Name a saveObject call's positional arguments after the loaded ObjectService.
	 *
	 * @param array<int,mixed> $args The arguments as the double received them.
	 *
	 * @return array<string,mixed>
	 */
	private static function saveObjectArguments(array $args): array {
		$named = [];
		foreach ((new \ReflectionMethod(ObjectService::class, 'saveObject'))->getParameters() as $position => $parameter) {
			if (array_key_exists($position, $args) === true) {
				$named[$parameter->getName()] = $args[$position];
			}
		}

		return $named;
	}

	private static function record(string $sessionId, string $status, array $extra=[]): array {
		return array_merge(
			['sessionId' => $sessionId, 'learnerId' => 'pupil-1', 'learnerRef' => self::PROFILE, 'status' => $status, 'markedAt' => '2026-03-02T08:40:00+01:00', 'tenant_id' => self::TENANT],
			$extra
		);
	}//end record()

	/**
	 * The summary rows saved for a school year.
	 *
	 * @param string $year The school year.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function savedFor(string $year): array {
		return array_values(array_filter($this->saved, static fn (array $s): bool => ($s['object']['schoolYear'] ?? null) === $year));
	}//end savedFor()

	/**
	 * A first recount creates the row with a stable uuid, the teachers and the system as owner.
	 *
	 * @return void
	 */
	public function testANewSummaryGetsAStableUuidAndTheGroupTeachers(): void {
		$this->records = [self::record('s-mon', 'absent-unexcused'), self::record('s-tue', 'late', ['lateMinutes' => 10])];

		$saved = $this->service()->recompute(learnerId: 'pupil-1');

		self::assertSame(1, $saved);
		$row = $this->savedFor('2025-2026')[0];
		self::assertSame('attendance-summary', $row['schema']);
		self::assertFalse($row['rbac']);
		self::assertTrue($row['unowned'], 'no user owns a summary, so nobody can edit it through the API');
		self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string)$row['uuid']);
		self::assertSame(1, $row['object']['absentDays']);
		self::assertSame(1, $row['object']['absentUnauthorisedDays']);
		self::assertSame(10, $row['object']['lateMinutes']);
		self::assertSame(['juf-7'], $row['object']['teacherIds']);
		self::assertSame(self::PROFILE, $row['object']['learnerRef']);
		self::assertSame(self::TENANT, $row['object']['tenant_id']);

		// Only the counted statuses are read: a year of "present" stays out of the query.
		self::assertSame(AttendanceSummaryCalculator::COUNTED_STATUSES, $this->recordQueries[0]['filters']['status']);

		// The same learner and year always map to the same uuid, so two racing jobs write one row.
		$first = $row['uuid'];
		$this->saved = [];
		$this->service()->recompute(learnerId: 'pupil-1');
		self::assertSame($first, $this->savedFor('2025-2026')[0]['uuid']);
	}//end testANewSummaryGetsAStableUuidAndTheGroupTeachers()

	/**
	 * A recount counts the whole year again and replaces stale numbers on the existing row.
	 *
	 * @return void
	 */
	public function testARecountReplacesTheStoredNumbers(): void {
		// The excuse was approved: the record is excused now.
		$this->records = [self::record('s-mon', 'absent-excused')];
		$this->summaries = [[
			'id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'learnerId' => 'pupil-1', 'learnerRef' => self::PROFILE, 'schoolYear' => '2025-2026',
			'absentDays' => 1, 'absentAuthorisedDays' => 0, 'absentUnauthorisedDays' => 1, 'lateCount' => 0, 'lateMinutes' => 0,
			'teacherIds' => ['juf-7'], 'tenant_id' => self::TENANT, 'updatedAt' => '2026-03-02T09:00:00+00:00', '@self' => ['id' => 'aaaaaaaa-0000-4000-8000-000000000001'],
		]];

		$this->service()->recompute(learnerId: 'pupil-1');

		$row = $this->savedFor('2025-2026')[0];
		self::assertSame('aaaaaaaa-0000-4000-8000-000000000001', $row['uuid']);
		self::assertSame(1, $row['object']['absentAuthorisedDays']);
		self::assertSame(0, $row['object']['absentUnauthorisedDays']);
		self::assertArrayNotHasKey('@self', $row['object']);
	}//end testARecountReplacesTheStoredNumbers()

	/**
	 * A row whose numbers did not change is not saved again.
	 *
	 * @return void
	 */
	public function testAnUnchangedSummaryIsNotSaved(): void {
		$this->records = [self::record('s-mon', 'absent-excused')];
		$this->summaries = [[
			'id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'learnerId' => 'pupil-1', 'learnerRef' => self::PROFILE, 'schoolYear' => '2025-2026',
			'absentDays' => 1, 'absentAuthorisedDays' => 1, 'absentUnauthorisedDays' => 0, 'lateCount' => 0, 'lateMinutes' => 0,
			'teacherIds' => ['juf-7'], 'tenant_id' => self::TENANT, 'updatedAt' => '2026-03-02T09:00:00+00:00',
		]];

		self::assertSame(0, $this->service()->recompute(learnerId: 'pupil-1'));
		self::assertSame([], $this->saved);
	}//end testAnUnchangedSummaryIsNotSaved()

	/**
	 * A year whose last absence was deleted drops to zero; a year asked for without records gets a zero row.
	 *
	 * @return void
	 */
	public function testAYearWithoutRecordsGetsZeros(): void {
		$this->records = [];
		$this->summaries = [[
			'id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'learnerId' => 'pupil-1', 'schoolYear' => '2025-2026',
			'absentDays' => 1, 'absentAuthorisedDays' => 0, 'absentUnauthorisedDays' => 1, 'lateCount' => 0, 'lateMinutes' => 0,
			'teacherIds' => ['juf-7'], 'tenant_id' => self::TENANT,
		]];

		$this->service()->recompute(learnerId: 'pupil-1', schoolYears: ['2026-2027'], tenantId: self::TENANT);

		self::assertSame(0, $this->savedFor('2025-2026')[0]['object']['absentDays']);
		$new = $this->savedFor('2026-2027')[0]['object'];
		self::assertSame(AttendanceSummaryCalculator::emptyCounts(), array_intersect_key($new, AttendanceSummaryCalculator::emptyCounts()));
	}//end testAYearWithoutRecordsGetsZeros()

	/**
	 * A failed group lookup keeps the teachers the row had, and stamps nobody on a new row.
	 *
	 * @return void
	 */
	public function testAFailedTeacherLookupNeverWidensTheAudience(): void {
		$this->teachers = null;
		$this->records = [self::record('s-mon', 'absent-unexcused'), self::record('s-sep', 'late', ['lateMinutes' => 5])];
		$this->summaries = [[
			'id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'learnerId' => 'pupil-1', 'schoolYear' => '2025-2026',
			'absentDays' => 0, 'absentAuthorisedDays' => 0, 'absentUnauthorisedDays' => 0, 'lateCount' => 0, 'lateMinutes' => 0,
			'teacherIds' => ['juf-6'], 'tenant_id' => self::TENANT,
		]];

		$this->service()->recompute(learnerId: 'pupil-1');

		self::assertSame(['juf-6'], $this->savedFor('2025-2026')[0]['object']['teacherIds']);
		self::assertSame([], $this->savedFor('2026-2027')[0]['object']['teacherIds']);
	}//end testAFailedTeacherLookupNeverWidensTheAudience()

	/**
	 * What the service writes passes the shipped AttendanceSummary schema.
	 *
	 * @return void
	 */
	public function testTheWrittenSummaryPassesTheRealSchema(): void {
		$this->records = [self::record('s-mon', 'absent-excused'), self::record('s-tue', 'late', ['lateMinutes' => 15])];

		$this->service()->recompute(learnerId: 'pupil-1');

		$written = $this->savedFor('2025-2026')[0]['object'];
		self::assertNull(self::schemaError(slug: 'attendance-summary', payload: $written));
		self::assertNotNull(self::schemaError(slug: 'attendance-summary', payload: array_merge($written, ['schoolYear' => '2025'])), 'control: the school year pattern bites');
		self::assertNotNull(self::schemaError(slug: 'attendance-summary', payload: array_merge($written, ['lateMinutes' => -1])), 'control: counts are never negative');
	}//end testTheWrittenSummaryPassesTheRealSchema()

	/**
	 * The school years a set of records touches, through their lessons.
	 *
	 * @return void
	 */
	public function testSchoolYearsOfSessions(): void {
		self::assertSame(['2025-2026', '2026-2027'], $this->service()->schoolYearsOf(sessionIds: ['s-mon', 's-tue', 's-sep', 'unknown']));
	}//end testSchoolYearsOfSessions()
}//end class
