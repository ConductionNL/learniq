<?php

/**
 * Learniq RollCallService unit tests.
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
 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Attendance;

use DateTimeZone;
use OCA\Learniq\Service\Attendance\RollCallAccess;
use OCA\Learniq\Service\Attendance\RollCallException;
use OCA\Learniq\Service\Attendance\RollCallLessons;
use OCA\Learniq\Service\Attendance\RollCallMarks;
use OCA\Learniq\Service\Attendance\RollCallReader;
use OCA\Learniq\Service\Attendance\RollCallService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The day's register of one group.
 */
class RollCallServiceTest extends TestCase {
	use RegisterSchemaPayloads;

	private const TENANT = '00000000-0000-4000-8000-000000000000';
	private const G7 = 'c0000000-0000-4000-8000-000000000007';
	private const G3 = 'c0000000-0000-4000-8000-000000000003';
	private const TODAY_SESSION = 'a0000000-0000-4000-8000-000000000001';

	/**
	 * Friday 2 October 2026, 09:00 in Amsterdam: "today" in these tests.
	 */
	private const FRIDAY_0900 = 1790924400;

	/** @var array<string, array<int, array<string, mixed>>> */
	private array $store = [];

	/** @var array<int, array<string, mixed>> */
	private array $saved = [];

	/** @var array<string, array<int, string>> */
	private array $groups = [];

	/** @var array<int, array<string, mixed>> */
	private array $queries = [];

	/**
	 * A small school: Groep 7 with three pupils, Groep 3 with one.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved = [];
		$this->queries = [];
		$this->groups = ['juf-7' => ['instructors'], 'juf-3' => ['instructors'], 'ib' => ['coordinators'], 'parent' => []];
		$this->store = [
			'cohort' => [
				['id' => self::G7, 'name' => 'Groep 7', 'teacherIds' => ['juf-7'], 'teacherAssignments' => [['teacherId' => 'duo-7']], 'learnerIds' => ['vera', 'daan', 'noor'], 'lifecycle' => 'active', 'tenant_id' => self::TENANT, 'courseId' => 'd0000000-0000-4000-8000-000000000001'],
				['id' => self::G3, 'name' => 'Groep 3', 'teacherIds' => ['juf-3'], 'learnerIds' => ['sem'], 'lifecycle' => 'active', 'tenant_id' => self::TENANT],
				['id' => 'c-old', 'name' => 'Groep 6 (vorig jaar)', 'teacherIds' => ['juf-7'], 'learnerIds' => ['vera'], 'lifecycle' => 'archived', 'tenant_id' => self::TENANT],
			],
			'session' => [
				['id' => self::TODAY_SESSION, 'cohortId' => self::G7, 'title' => 'Groep 7, vrijdag 2 oktober 2026', 'startsAt' => '2026-10-02T08:30:00+02:00', 'endsAt' => '2026-10-02T14:15:00+02:00', 'lifecycle' => 'scheduled', 'tenant_id' => self::TENANT],
				['id' => 'a-thu', 'cohortId' => self::G7, 'title' => 'Groep 7, donderdag', 'startsAt' => '2026-10-01T08:30:00+02:00', 'endsAt' => '2026-10-01T14:15:00+02:00', 'lifecycle' => 'completed', 'tenant_id' => self::TENANT],
				['id' => 'a-wed', 'cohortId' => self::G7, 'title' => 'Groep 7, woensdag', 'startsAt' => '2026-09-30T08:30:00+02:00', 'endsAt' => '2026-09-30T12:15:00+02:00', 'lifecycle' => 'completed', 'tenant_id' => self::TENANT],
				['id' => 'a-fri-before', 'cohortId' => self::G7, 'title' => 'Groep 7, vrijdag', 'startsAt' => '2026-09-25T08:45:00+02:00', 'endsAt' => '2026-09-25T14:00:00+02:00', 'lifecycle' => 'completed', 'tenant_id' => self::TENANT],
			],
			'learner-profile' => [
				['id' => 'b0000000-0000-4000-8000-000000000001', 'ncUserId' => 'vera', 'givenName' => 'Vera', 'familyName' => 'Hulstkamp', 'tenant_id' => self::TENANT],
				['id' => 'b0000000-0000-4000-8000-000000000002', 'ncUserId' => 'daan', 'givenName' => 'Daan', 'familyName' => 'Beekdal', 'tenant_id' => self::TENANT],
				['id' => 'b0000000-0000-4000-8000-000000000003', 'ncUserId' => 'noor', 'givenName' => 'Noor', 'familyName' => 'Weidehof', 'tenant_id' => self::TENANT],
			],
			'attendance-record' => [],
			'excuse-request' => [],
		];
	}//end setUp()

	/**
	 * The service against the in-memory school.
	 *
	 * @param int $now Unix time.
	 *
	 * @return RollCallService
	 */
	private function service(int $now=self::FRIDAY_0900): RollCallService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config=[], bool $_rbac=true, bool $_multitenancy=true): array {
				self::assertFalse($_rbac, 'the server decides who may see the register');
				$this->queries[] = $config;
				$filters = $config['filters'];
				$rows = ($this->store[$filters['schema']] ?? []);
				foreach ($filters as $field => $value) {
					if (in_array($field, ['register', 'schema'], true) === true || is_array($value) === true) {
						continue;
					}

					$rows = array_filter($rows, static fn (array $row): bool => in_array($value, (array)($row[$field] ?? null), true));
				}

				return array_map(static fn (array $row): ObjectEntity => OrEntityFactory::make($row, (string)$filters['schema']), array_values($rows));
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend=[], $register=null, $schema=null, ?string $uuid=null, bool $_rbac=true): ObjectEntity {
				$data = (array)$object;
				$data['id'] = ($uuid ?? ('new-' . count($this->saved)));
				$this->saved[] = ['schema' => $schema, 'uuid' => $uuid, 'object' => $object, 'rbac' => $_rbac];
				if ($uuid === null) {
					$this->store[(string)$schema][] = $data;
				}

				return OrEntityFactory::make($data, (string)$schema);
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(fn (string $uid, string $group): bool => in_array($group, ($this->groups[$uid] ?? []), true));
		$groups->method('isAdmin')->willReturn(false);

		$zone = $this->createMock(IDateTimeZone::class);
		$zone->method('getTimeZone')->willReturn(new DateTimeZone('Europe/Amsterdam'));

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $params=[]): string => vsprintf($text, (array)$params));
		$l10n->method('l')->willReturnCallback(static fn (string $type, $data): string => $data->format('l j F Y'));

		$reader = new RollCallReader(objectService: $objects);

		return new RollCallService(
			reader: $reader,
			access: new RollCallAccess(reader: $reader, groupManager: $groups, timeZone: $zone, time: $time, l10n: $l10n),
			lessons: new RollCallLessons(objectService: $objects, l10n: $l10n, logger: new NullLogger()),
			marks: new RollCallMarks(),
			objectService: $objects,
			time: $time,
			l10n: $l10n
		);
	}//end service()

	/**
	 * A user double.
	 *
	 * @param string $uid User id.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * A pupil's row in the register.
	 *
	 * @param array<string, mixed> $register The register.
	 * @param string               $learnerId The pupil.
	 *
	 * @return array<string, mixed>
	 */
	private static function pupil(array $register, string $learnerId): array {
		foreach ($register['pupils'] as $pupil) {
			if ($pupil['learnerId'] === $learnerId) {
				return $pupil;
			}
		}

		self::fail('No pupil ' . $learnerId);
	}//end pupil()

	/**
	 * The records saved.
	 *
	 * @return array<string, array<string, mixed>> Keyed by learnerId.
	 */
	private function savedRecords(): array {
		$records = [];
		foreach ($this->saved as $save) {
			if ($save['schema'] === 'attendance-record') {
				$records[$save['object']['learnerId']] = $save;
			}
		}

		return $records;
	}//end savedRecords()

	/**
	 * A group teacher lands on their own group and today, everyone present by default.
	 *
	 * @return void
	 */
	public function testATeacherOpensTodaysRegisterOfTheirGroup(): void {
		$register = $this->service()->open(user: $this->user('juf-7'), cohortId: null, date: null, sessionId: null);

		self::assertSame('2026-10-02', $register['date']);
		self::assertSame(self::G7, $register['cohortId']);
		self::assertSame([['id' => self::G7, 'name' => 'Groep 7']], $register['cohorts'], 'only their own current group');
		self::assertSame(self::TODAY_SESSION, $register['sessionId']);
		self::assertTrue($register['editable']);
		self::assertSame(['Daan Beekdal', 'Noor Weidehof', 'Vera Hulstkamp'], array_column($register['pupils'], 'name'));
		self::assertSame(['present', 'present', 'present'], array_column($register['pupils'], 'status'));
		self::assertSame([false, false, false], array_column($register['pupils'], 'saved'));
	}//end testATeacherOpensTodaysRegisterOfTheirGroup()

	/**
	 * A duo-partner listed only in teacherAssignments opens the group too.
	 *
	 * @return void
	 */
	public function testADuoPartnerOpensTheGroup(): void {
		$this->groups['duo-7'] = ['instructors'];

		$register = $this->service()->open(user: $this->user('duo-7'), cohortId: self::G7, date: null, sessionId: null);

		self::assertSame(self::G7, $register['cohortId']);
	}//end testADuoPartnerOpensTheGroup()

	/**
	 * A teacher cannot open or save another group.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-who-may-open-which-groups-register
	 */
	public function testATeacherCannotOpenAnotherGroup(): void {
		try {
			$this->service()->open(user: $this->user('juf-7'), cohortId: self::G3, date: null, sessionId: null);
			self::fail('opened another group');
		} catch (RollCallException $exception) {
			self::assertSame(403, $exception->getStatus());
		}

		$this->expectException(RollCallException::class);
		$this->service()->save(user: $this->user('juf-7'), cohortId: self::G3, date: '2026-10-02', sessionId: null, marks: [['learnerId' => 'sem', 'status' => 'absent-unexcused']]);
	}//end testATeacherCannotOpenAnotherGroup()

	/**
	 * Someone who is neither a teacher nor school-wide staff is refused.
	 *
	 * @return void
	 */
	public function testSomeoneElseIsRefused(): void {
		$this->expectException(RollCallException::class);
		$this->service()->open(user: $this->user('parent'), cohortId: null, date: null, sessionId: null);
	}//end testSomeoneElseIsRefused()

	/**
	 * A coordinator chooses from every current group.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-who-may-open-which-groups-register
	 */
	public function testACoordinatorOpensAnyGroup(): void {
		$register = $this->service()->open(user: $this->user('ib'), cohortId: self::G3, date: null, sessionId: null);

		self::assertSame([['id' => self::G3, 'name' => 'Groep 3'], ['id' => self::G7, 'name' => 'Groep 7']], $register['cohorts']);
		self::assertSame(self::G3, $register['cohortId']);
		self::assertSame(['sem'], array_column($register['pupils'], 'learnerId'));
	}//end testACoordinatorOpensAnyGroup()

	/**
	 * One late and one unauthorised absence; the unchanged pupil is recorded present.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#scenario-the-teacher-marks-one-pupil-late-and-one-absent-without-permission
	 */
	public function testSaveWritesLateMinutesAndAnUnauthorisedAbsence(): void {
		$register = $this->service()->save(
			user: $this->user('juf-7'),
			cohortId: self::G7,
			date: '2026-10-02',
			sessionId: null,
			marks: [
				['learnerId' => 'vera', 'status' => 'late', 'lateMinutes' => 10],
				['learnerId' => 'daan', 'status' => 'absent-unexcused', 'reason' => 'Niet ziek gemeld'],
				['learnerId' => 'noor', 'status' => 'present'],
			]
		);

		$records = $this->savedRecords();
		self::assertCount(3, $records);
		$vera = $records['vera']['object'];
		self::assertFalse($records['vera']['rbac']);
		self::assertSame('late', $vera['status']);
		self::assertSame(10, $vera['lateMinutes']);
		self::assertSame(335, $vera['minutesAttended'], '345 minutes long, 10 late');
		self::assertSame(self::TODAY_SESSION, $vera['sessionId']);
		self::assertSame(self::G7, $vera['cohortId']);
		self::assertSame('b0000000-0000-4000-8000-000000000001', $vera['learnerRef']);
		self::assertSame('juf-7', $vera['markedBy']);
		self::assertSame('teacher', $vera['markedVia']);
		self::assertSame(self::TENANT, $vera['tenant_id']);

		$daan = $records['daan']['object'];
		self::assertSame('absent-unexcused', $daan['status']);
		self::assertNull($daan['minutesAttended']);
		self::assertSame('Niet ziek gemeld', $daan['reason']);
		self::assertSame(345, $records['noor']['object']['minutesAttended']);

		foreach ($records as $save) {
			$payload = array_filter($save['object'], static fn ($value): bool => $value !== null);
			self::assertNull(self::schemaError(slug: 'attendance-record', payload: $payload), 'the record passes the shipped schema');
		}

		self::assertSame('late', self::pupil($register, 'vera')['status']);
		self::assertTrue(self::pupil($register, 'vera')['saved']);
	}//end testSaveWritesLateMinutesAndAnUnauthorisedAbsence()

	/**
	 * Saving again changes only what changed.
	 *
	 * @return void
	 */
	public function testSavingAgainWritesOnlyWhatChanged(): void {
		$this->store['attendance-record'] = [
			['id' => 'r-vera', 'sessionId' => self::TODAY_SESSION, 'learnerId' => 'vera', 'status' => 'late', 'lateMinutes' => 10, 'markedVia' => 'teacher', 'tenant_id' => self::TENANT, '@self' => ['id' => 'r-vera']],
			['id' => 'r-noor', 'sessionId' => self::TODAY_SESSION, 'learnerId' => 'noor', 'status' => 'present', 'markedVia' => 'teacher', 'tenant_id' => self::TENANT],
		];

		$this->service()->save(
			user: $this->user('juf-7'),
			cohortId: self::G7,
			date: '2026-10-02',
			sessionId: self::TODAY_SESSION,
			marks: [
				['learnerId' => 'vera', 'status' => 'late', 'lateMinutes' => 15],
				['learnerId' => 'noor', 'status' => 'present'],
			]
		);

		$records = $this->savedRecords();
		self::assertSame(['vera'], array_keys($records));
		self::assertSame('r-vera', $records['vera']['uuid']);
		self::assertSame(15, $records['vera']['object']['lateMinutes']);
		self::assertArrayNotHasKey('@self', $records['vera']['object']);
	}//end testSavingAgainWritesOnlyWhatChanged()

	/**
	 * An absence that becomes present clears its reason and minutes fields.
	 *
	 * @return void
	 */
	public function testAPupilWhoArrivesAfterAllIsPresentAgain(): void {
		$this->store['attendance-record'] = [
			['id' => 'r-daan', 'sessionId' => self::TODAY_SESSION, 'learnerId' => 'daan', 'status' => 'absent-excused', 'absenceReasonKind' => 'illness', 'markedVia' => 'teacher', 'tenant_id' => self::TENANT],
		];

		$this->service()->save(user: $this->user('juf-7'), cohortId: self::G7, date: '2026-10-02', sessionId: null, marks: [['learnerId' => 'daan', 'status' => 'present']]);

		$daan = $this->savedRecords()['daan']['object'];
		self::assertSame('present', $daan['status']);
		self::assertNull($daan['absenceReasonKind']);
		self::assertNull($daan['lateMinutes']);
		self::assertNull($daan['excuseRequestId']);
	}//end testAPupilWhoArrivesAfterAllIsPresentAgain()

	/**
	 * A late mark names its minutes; an absence with permission names its reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#scenario-a-late-mark-without-minutes-is-refused
	 */
	public function testALateMarkNeedsItsMinutes(): void {
		foreach ([
			[['learnerId' => 'vera', 'status' => 'late']],
			[['learnerId' => 'vera', 'status' => 'late', 'lateMinutes' => 0]],
			[['learnerId' => 'vera', 'status' => 'late', 'lateMinutes' => 601]],
			[['learnerId' => 'vera', 'status' => 'absent-excused']],
			[['learnerId' => 'vera', 'status' => 'absent-excused', 'absenceReasonKind' => 'holiday']],
			[['learnerId' => 'vera', 'status' => 'on-a-trip']],
			[['learnerId' => 'sem', 'status' => 'present']],
		] as $marks) {
			try {
				$this->service()->save(user: $this->user('juf-7'), cohortId: self::G7, date: '2026-10-02', sessionId: null, marks: $marks);
				self::fail('accepted ' . json_encode($marks));
			} catch (RollCallException $exception) {
				self::assertSame(422, $exception->getStatus());
			}
		}

		try {
			$this->service()->save(user: $this->user('juf-7'), cohortId: self::G7, date: '2026-10-02', sessionId: null, marks: [['learnerId' => 'vera', 'status' => 'late']]);
		} catch (RollCallException $exception) {
			self::assertStringContainsString('Vera Hulstkamp', $exception->getMessage());
		}

		self::assertSame([], $this->savedRecords(), 'nothing is written when one mark is wrong');
	}//end testALateMarkNeedsItsMinutes()

	/**
	 * An approved report covering the day pre-fills an authorised absence; a pending one only shows.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-an-approved-absence-report-fills-in-the-register
	 */
	public function testAnApprovedReportPrefillsAnAuthorisedAbsence(): void {
		$this->store['excuse-request'] = [
			['id' => 'x-vera', 'learnerId' => 'vera', 'dateFrom' => '2026-09-30', 'dateTo' => '2026-10-02', 'reason' => 'Griep', 'reasonKind' => 'illness', 'lifecycle' => 'approved'],
			['id' => 'x-daan', 'learnerId' => 'daan', 'dateFrom' => '2026-10-02', 'dateTo' => '2026-10-02', 'reason' => 'Tandarts', 'reasonKind' => 'medical-appointment', 'lifecycle' => 'submitted'],
			['id' => 'x-noor', 'learnerId' => 'noor', 'dateFrom' => '2026-09-01', 'dateTo' => '2026-09-02', 'reason' => 'Oud', 'reasonKind' => 'illness', 'lifecycle' => 'approved'],
		];

		$register = $this->service()->open(user: $this->user('juf-7'), cohortId: null, date: null, sessionId: null);

		$vera = self::pupil($register, 'vera');
		self::assertSame('absent-excused', $vera['status']);
		self::assertSame('illness', $vera['absenceReasonKind']);
		self::assertSame('Griep', $vera['reason']);
		self::assertSame('x-vera', $vera['report']['id']);
		$daan = self::pupil($register, 'daan');
		self::assertSame('present', $daan['status'], 'a report still waiting for a decision changes nothing');
		self::assertSame('submitted', $daan['report']['lifecycle']);
		self::assertNull(self::pupil($register, 'noor')['report']);

		$this->service()->save(user: $this->user('juf-7'), cohortId: self::G7, date: '2026-10-02', sessionId: null, marks: [['learnerId' => 'vera', 'status' => 'absent-excused', 'absenceReasonKind' => 'illness', 'reason' => 'Griep']]);
		self::assertSame('x-vera', $this->savedRecords()['vera']['object']['excuseRequestId']);

		// medical-appointment reads as appointment, anything else as other.
		$this->store['excuse-request'][1]['lifecycle'] = 'approved';
		$this->store['excuse-request'][] = ['id' => 'x-sem', 'learnerId' => 'noor', 'dateFrom' => '2026-10-02', 'dateTo' => '2026-10-02', 'reason' => 'Uitvaart', 'reasonKind' => 'bereavement', 'lifecycle' => 'approved'];
		$register = $this->service()->open(user: $this->user('juf-7'), cohortId: null, date: null, sessionId: null);
		self::assertSame('appointment', self::pupil($register, 'daan')['absenceReasonKind']);
		self::assertSame('other', self::pupil($register, 'noor')['absenceReasonKind']);
	}//end testAnApprovedReportPrefillsAnAuthorisedAbsence()

	/**
	 * A teacher's saved earlier day is read-only for them; an unsaved one is not; school-wide staff may change it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-the-register-can-be-changed-the-same-day
	 */
	public function testAnEarlierSavedDayIsReadOnlyForTheTeacher(): void {
		$wednesday = $this->service()->open(user: $this->user('juf-7'), cohortId: self::G7, date: '2026-09-30', sessionId: null);
		self::assertTrue($wednesday['editable'], 'a forgotten register can still be filled in');

		$this->store['attendance-record'] = [
			['id' => 'r-thu', 'sessionId' => 'a-thu', 'learnerId' => 'vera', 'status' => 'present', 'markedVia' => 'teacher', 'tenant_id' => self::TENANT],
		];
		$thursday = $this->service()->open(user: $this->user('juf-7'), cohortId: self::G7, date: '2026-10-01', sessionId: null);
		self::assertFalse($thursday['editable']);
		self::assertSame('past-saved', $thursday['locked']);

		try {
			$this->service()->save(user: $this->user('juf-7'), cohortId: self::G7, date: '2026-10-01', sessionId: null, marks: [['learnerId' => 'vera', 'status' => 'absent-unexcused']]);
			self::fail('changed a saved earlier day');
		} catch (RollCallException $exception) {
			self::assertSame(403, $exception->getStatus());
		}

		$this->groups['ib'] = ['coordinators'];
		$this->store['cohort'][0]['teacherIds'][] = 'nobody';
		$this->service()->save(user: $this->user('ib'), cohortId: self::G7, date: '2026-10-01', sessionId: null, marks: [['learnerId' => 'vera', 'status' => 'absent-unexcused']]);
		self::assertSame('absent-unexcused', $this->savedRecords()['vera']['object']['status']);
		self::assertSame('r-thu', $this->savedRecords()['vera']['uuid']);
	}//end testAnEarlierSavedDayIsReadOnlyForTheTeacher()

	/**
	 * Nobody saves a day after today.
	 *
	 * @return void
	 */
	public function testNobodySavesTheFuture(): void {
		$register = $this->service()->open(user: $this->user('ib'), cohortId: self::G7, date: '2026-10-05', sessionId: null);
		self::assertFalse($register['editable']);
		self::assertSame('future', $register['locked']);

		$this->expectException(RollCallException::class);
		$this->service()->save(user: $this->user('ib'), cohortId: self::G7, date: '2026-10-05', sessionId: null, marks: [['learnerId' => 'vera', 'status' => 'present']]);
	}//end testNobodySavesTheFuture()

	/**
	 * A day without a lesson gets one, timed like the last lesson on the same weekday.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-day-without-a-lesson-gets-one-when-the-register-is-saved
	 */
	public function testSavingADayWithoutALessonCreatesOne(): void {
		$this->store['session'] = array_values(array_filter($this->store['session'], static fn (array $s): bool => $s['id'] !== self::TODAY_SESSION));

		$register = $this->service()->open(user: $this->user('juf-7'), cohortId: null, date: null, sessionId: null);
		self::assertNull($register['sessionId']);
		self::assertSame([], array_filter($this->saved, static fn (array $s): bool => $s['schema'] === 'session'), 'opening creates nothing');

		$this->service()->save(user: $this->user('juf-7'), cohortId: self::G7, date: '2026-10-02', sessionId: null, marks: [['learnerId' => 'vera', 'status' => 'late', 'lateMinutes' => 5]]);

		$sessions = array_values(array_filter($this->saved, static fn (array $s): bool => $s['schema'] === 'session'));
		self::assertCount(1, $sessions);
		$session = $sessions[0]['object'];
		self::assertSame('Groep 7, Friday 2 October 2026', $session['title']);
		self::assertSame('2026-10-02T08:45:00+02:00', $session['startsAt'], 'timed like last Friday');
		self::assertSame('2026-10-02T14:00:00+02:00', $session['endsAt']);
		self::assertSame(self::G7, $session['cohortId']);
		self::assertSame(self::TENANT, $session['tenant_id']);
		self::assertNull(self::schemaError(slug: 'session', payload: $session));
		self::assertSame('new-0', $this->savedRecords()['vera']['object']['sessionId']);
		self::assertSame(310, $this->savedRecords()['vera']['object']['minutesAttended']);

		// The lesson exists now: a second save creates no second lesson.
		$this->saved = [];
		$this->service()->save(user: $this->user('juf-7'), cohortId: self::G7, date: '2026-10-02', sessionId: null, marks: [['learnerId' => 'noor', 'status' => 'absent-unexcused']]);
		self::assertSame([], array_filter($this->saved, static fn (array $s): bool => $s['schema'] === 'session'));
	}//end testSavingADayWithoutALessonCreatesOne()

	/**
	 * Without an earlier lesson the day runs from 08:30 to 14:30.
	 *
	 * @return void
	 */
	public function testANewGroupGetsTheDefaultSchoolDay(): void {
		$this->store['session'] = [];

		$this->service()->save(user: $this->user('juf-7'), cohortId: self::G7, date: '2026-10-02', sessionId: null, marks: [['learnerId' => 'vera', 'status' => 'present']]);

		$session = array_values(array_filter($this->saved, static fn (array $s): bool => $s['schema'] === 'session'))[0]['object'];
		self::assertSame('2026-10-02T08:30:00+02:00', $session['startsAt']);
		self::assertSame('2026-10-02T14:30:00+02:00', $session['endsAt']);
	}//end testANewGroupGetsTheDefaultSchoolDay()

	/**
	 * A self check-in the teacher left as it was stays a self check-in.
	 *
	 * @return void
	 */
	public function testAnUnchangedSelfCheckInIsNotOverwritten(): void {
		$this->store['attendance-record'] = [
			['id' => 'r-noor', 'sessionId' => self::TODAY_SESSION, 'learnerId' => 'noor', 'status' => 'present', 'markedVia' => 'self-check-in', 'tenant_id' => self::TENANT],
		];

		$register = $this->service()->save(user: $this->user('juf-7'), cohortId: self::G7, date: '2026-10-02', sessionId: null, marks: [['learnerId' => 'noor', 'status' => 'present']]);

		self::assertSame([], $this->savedRecords());
		self::assertSame('self-check-in', self::pupil($register, 'noor')['markedVia']);
	}//end testAnUnchangedSelfCheckInIsNotOverwritten()

	/**
	 * A date that is not a date is refused.
	 *
	 * @return void
	 */
	public function testABadDateIsRefused(): void {
		$this->expectException(RollCallException::class);
		$this->service()->open(user: $this->user('juf-7'), cohortId: null, date: '02-10-2026', sessionId: null);
	}//end testABadDateIsRefused()
}//end class
